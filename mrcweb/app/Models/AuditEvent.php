<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Denetim olayı : salt-eklenir kayıt.
 *
 * Bu modelde update/delete YOKTUR (API de sunmaz): denetim kaydının kendisi
 * değiştirilebilirse denetim anlamsızlaşır. Hatalı kayıt düzeltmesi ters kayıtla
 * değil, yeni olayla yapılır — muhasebe fişi mantığı.
 */
class AuditEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'company_id', 'user_id', 'action', 'auditable_type', 'auditable_id', 'meta', 'ip',
    ];

    protected $casts = [
        'meta' => 'array',
        'created_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /** Eylemin okunur adı (kılavuz + SPA + EN çeviri aynı anahtardan). */
    public static function label(string $action): string
    {
        return __(match ($action) {
            'auth.login' => 'Giriş yaptı',
            'auth.logout' => 'Çıkış yaptı',
            'auth.2fa.enabled' => 'İki faktörü açtı',
            'auth.2fa.disabled' => 'İki faktörü kapattı',
            'document.created' => 'Belge oluşturdu',
            'document.updated' => 'Belgeyi düzenledi',
            'document.status' => 'Belge durumunu değiştirdi',
            'document.converted' => 'Belgeyi dönüştürdü',
            'document.duplicated' => 'Belgeyi kopyaladı',
            'document.deleted' => 'Belgeyi sildi',
            'payment.created' => 'Ödeme kaydetti',
            'payment.deleted' => 'Ödemeyi sildi',
            'payment_link.created' => 'Ödeme bağlantısı üretti',
            'payment_link.cancelled' => 'Ödeme bağlantısını kapattı',
            'payment_settings.updated' => 'Ödeme hesaplarını güncelledi',
            'invitation.created' => 'Üye davet etti',
            'invitation.cancelled' => 'Daveti iptal etti',
            'invitation.accepted' => 'Daveti kabul etti',
            'settings.updated' => 'Ayarları güncelledi',
            'company.updated' => 'Firma bilgilerini güncelledi',
            'print_template.created' => 'Yazdırma şablonu oluşturdu',
            'print_template.updated' => 'Yazdırma şablonunu düzenledi',
            'print_template.deleted' => 'Yazdırma şablonunu sildi',
            'print_template.default' => 'Varsayılan yazdırma şablonunu değiştirdi',
            default => $action,
        });
    }
}
