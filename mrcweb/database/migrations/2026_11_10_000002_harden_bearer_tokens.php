<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BEARER TOKEN'LARIN VERİTABANINDA Kullanılamaz HALE GETİRİLMESİ.
 *
 * Neden: `invitations.token` (davet kabulü → firma üyeliği) ve
 * `payment_links.token` (müşteri ödeme sayfası) düz metin saklanıyordu.
 * Veritabanı sızarsa (yedek kopyası, SQL injection, eski sunucu diski) bu
 * token'lar ele geçer: saldırgan daveti kabul edip şirkete üye olabilir,
 * müşteri ödeme sayfalarını açabilir.
 *
 * Çözüm (ikili kolon):
 *   - `token`        → APP_KEY ile ŞİFRELENİR (Laravel `encrypted` cast).
 *                      APP_KEY veritabanında DEĞİLDİR; sızıntıda okunamaz.
 *   - `token_hash`  → sha256(token) — ARAMA/lookup için (şifreli kolon
 *                      üzerinden sorgu yapılamaz).
 *
 * Mevcut kayıtlar geriye dönük uyumlu şekilde dönüştürülür: eski düz metin
 * token'ın hash'i yazılır, token kolonu şifrelenir → DAHA ÖNCE PAYLAŞILMIŞ
 * bağlantılar çalışmaya devam eder.
 *
 * NOT (MySQL): TEXT kolon doğrudan indexlenemez. Bu yüzden `token` üzerindeki
 * unique index'ler ÖNCE kaldırılır (teklik artık token_hash üzerinde) ve
 * `provider_token`'ı kapsayan bileşik index de düşürülür.
 *
 * Migration YARIM ÇALIŞMIŞ olabilir (MySQL DDL'i transaction değildir):
 * bu yüzden tüm adımlar TEKRAR ÇALIŞTIRILABİLİR (idempotent) şekilde yazıldı.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- 1) token_hash kolonları -----------------------------------------
        Schema::table('invitations', function (Blueprint $table) {
            if (! Schema::hasColumn('invitations', 'token_hash')) {
                $table->string('token_hash', 64)->nullable()->after('token');
            }
        });

        Schema::table('payment_links', function (Blueprint $table) {
            if (! Schema::hasColumn('payment_links', 'token_hash')) {
                $table->string('token_hash', 64)->nullable()->after('token');
            }
            if (! Schema::hasColumn('payment_links', 'provider_token_hash')) {
                $table->string('provider_token_hash', 64)->nullable()->after('provider_token');
            }
        });

        // --- 2) TEXT'e çevirmeden ÖNCE ilgili index'ler kaldırılır ---------
        $this->indexSil('invitations', 'invitations_token_unique');
        $this->indexSil('payment_links', 'payment_links_token_unique');
        $this->indexSil('payment_links', 'payment_links_provider_provider_token_index');

        // --- 3) token kolonları şifreli değer sığdıracak kadar büyür -------
        Schema::table('invitations', function (Blueprint $table) {
            $table->text('token')->change();
        });

        Schema::table('payment_links', function (Blueprint $table) {
            $table->text('token')->change();
            $table->text('provider_token')->nullable()->change();
        });

        // --- 4) Geriye dönük dönüştürme --------------------------------------
        $this->donustur('invitations');
        $this->donustur('payment_links');

        // --- 5) Yeni index'ler (teklik artık token_hash üzerinde) ----------
        if (! $this->indexVarMi('invitations', 'invitations_token_hash_unique')) {
            Schema::table('invitations', function (Blueprint $table) {
                $table->unique('token_hash');
            });
        }

        if (! $this->indexVarMi('payment_links', 'payment_links_token_hash_unique')) {
            Schema::table('payment_links', function (Blueprint $table) {
                $table->unique('token_hash');
            });
        }

        if (! $this->indexVarMi('payment_links', 'payment_links_provider_token_hash_index')) {
            Schema::table('payment_links', function (Blueprint $table) {
                $table->index('provider_token_hash');
            });
        }
    }

    /**
     * Tablodaki bearer token'ları hash'ler + şifreler.
     */
    protected function donustur(string $tablo): void
    {
        DB::table($tablo)->whereNotNull('token')->orderBy('id')->chunk(200, function ($satirlar) use ($tablo) {
            foreach ($satirlar as $satir) {
                $ham = (string) $satir->token;

                if ($ham === '') {
                    continue;
                }

                // Zaten şifreliyse (idempotency) atla.
                try {
                    Crypt::decryptString($ham);

                    continue;
                } catch (\Throwable) {
                    // düz metin — dönüştürülecek
                }

                $güncelle = [
                    'token_hash' => hash('sha256', $ham),
                    'token' => Crypt::encryptString($ham),
                ];

                if ($tablo === 'payment_links' && ! empty($satir->provider_token)) {
                    try {
                        Crypt::decryptString((string) $satir->provider_token);
                    } catch (\Throwable) {
                        $güncelle['provider_token_hash'] = hash('sha256', (string) $satir->provider_token);
                        $güncelle['provider_token'] = Crypt::encryptString((string) $satir->provider_token);
                    }
                }

                // Referans kolonları eskiden bearer token'ı düz metin taşıyordu
                // ("{token}:{status}:{type}"). Token artık şifreli olduğu için
                // referans da token içermemeli — aksi hâlde DB sızıntısı
                // ödeme sayfasını açar. Token ile başlayan referansları
                // link kimliğine çevir.
                $referans = (string) ($satir->reference ?? '');
                if ($referans !== '' && str_starts_with($referans, $ham)) {
                    $yeniReferans = 'link-'.$satir->id.substr($referans, strlen($ham));
                    $güncelle['reference'] = $yeniReferans;

                    DB::table('payments')
                        ->where('reference', $referans)
                        ->update(['reference' => $yeniReferans]);
                }

                DB::table($tablo)->where('id', $satir->id)->update($güncelle);
            }
        });
    }

    /** Index var mı? (MySQL + SQLite) */
    protected function indexVarMi(string $tablo, string $indexAdi): bool
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            foreach (DB::select('PRAGMA index_list("'.$tablo.'")') as $satir) {
                if (($satir->name ?? '') === $indexAdi) {
                    return true;
                }
            }

            return false;
        }

        $row = DB::selectOne(
            'SELECT COUNT(*) AS adet FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [$tablo, $indexAdi]
        );

        return ((int) ($row->adet ?? 0)) > 0;
    }

    /** Index yoksa hata vermeden sil. */
    protected function indexSil(string $tablo, string $indexAdi): void
    {
        if (! $this->indexVarMi($tablo, $indexAdi)) {
            return;
        }

        try {
            Schema::table($tablo, function (Blueprint $table) use ($indexAdi) {
                $table->dropIndex($indexAdi);
            });
        } catch (\Throwable) {
            // Zaten yoksa sorun değil.
        }
    }

    public function down(): void
    {
        // Geri alma: token'lar şifreli kaldığı için düz metne çevrilemez.
        // Kullanıcı bu migration'ı geri almamalı — ileri yönlü güvenlik adımıdır.
        Schema::table('invitations', function (Blueprint $table) {
            $table->dropUnique(['token_hash']);
            $table->dropColumn('token_hash');
        });

        Schema::table('payment_links', function (Blueprint $table) {
            $table->dropUnique(['token_hash']);
            $table->dropIndex(['provider_token_hash']);
            $table->dropColumn(['token_hash', 'provider_token_hash']);
        });
    }
};
