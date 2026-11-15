<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\SessionDevice;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Erişim anahtarı (Sanctum PAT) yönetimi — Faz 5 masaüstü istemci sözleşmesi.
 *
 * Sözleşme:
 * - Düz metin anahtar YALNIZ oluşturulduğu anda bir kez döner; listede asla
 *   görünmez (kolonda sha256 özeti durur, sızsa bile geri alınamaz).
 * - Yetenekler ('read' / 'write' / '*') üretimde sabitlenir; sonraki sürümlerde
 *   "yetkiyi düzenle" yerine iptal + yenisi kuralı geçerli (kullanım geçmişi
 *   kararmasın).
 * - Anahtar yalnız sahibinin listesinde görünür; başkasının kimliği 404 döner.
 *
 * Uçlar firma bağlamı gerektirmez (kullanıcıya ait, tüm firmaları kapsar).
 */
class TokenController extends Controller
{
    /**
     * Erişim anahtarlarını listele (düz metin anahtar asla listelenmez)
     *
     * @response array{tokens: array<int, array{id: int, name: string, kind: string, current: bool, abilities: array<int, string>, last_used_at: string|null, created_at: string, expires_at: string}>}
     */
    public function index(Request $request)
    {
        $currentId = $request->user()->currentAccessToken()?->id ?? null;

        $tokens = $request->user()->tokens()
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'abilities', 'ip_address', 'user_agent', 'last_used_at', 'created_at', 'expires_at'])
            ->map(fn ($t) => $this->present($t, $currentId));

        return response()->json(['tokens' => $tokens]);
    }

    /**
     * Yeni erişim anahtarı üret: düz metin YALNIZ bu cevapta görünür
     */
    #[\Dedoc\Scramble\Attributes\Response(
        status: 201,
        type: 'array{ message: string, token: array{id: int, name: string, kind: string, current: bool, abilities: array<int, string>, last_used_at: string|null, created_at: string, expires_at: string}, plain_text_token: string, }',
    )]
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'abilities' => ['sometimes', 'array'],
            'abilities.*' => ['string', Rule::in(config('api.token_abilities', ['*']))],
        ]);

        $abilities = $data['abilities'] ?? ['*'];

        // Yetenek kümesi tek olmalı: 'read' + 'write' verilirse anlam 'write'tır,
        // '*' ile karıştırmak yerine en yetkili değere indirgeriz.
        if (in_array('*', $abilities, true)) {
            $abilities = ['*'];
        } elseif (in_array('write', $abilities, true)) {
            $abilities = ['write'];
        } elseif ($abilities === []) {
            $abilities = ['*'];
        }

        $ttlDays = (int) config('api.token_ttl_days', 0);
        $expiresAt = $ttlDays > 0 ? now()->addDays($ttlDays) : null;

        $created = $request->user()->createToken($data['name'], $abilities, $expiresAt);
        SessionDevice::stamp($created, $request);

        return response()->json([
            'message' => __('Erişim anahtarı oluşturuldu. Anahtarı YALNIZ bu kez görüyorsun; kaybedersen yenisini oluştur.'),
            'token' => $this->present($created->accessToken, $request->user()->currentAccessToken()?->id),
            'plain_text_token' => $created->plainTextToken,
        ], 201);
    }

    /**
     * Erişim anahtarını iptal et (şu anki oturum anahtarı silinemez)
     *
     * @response array{message: string}
     */
    public function destroy(Request $request, int $tokenId)
    {
        // Kendi oturum anahtarını iptal etmek kullanıcıyı anında düşürür;
        // arayüzde kilitli, API’de de açık bir gerekçeyle reddedilir.
        if ($tokenId === (int) ($request->user()->currentAccessToken()?->id ?? 0)) {
            return response()->json([
                'message' => __('Bu erişim anahtarı şu anki oturumunundur; önce çıkış yapın.'),
            ], 422);
        }

        $deleted = $request->user()->tokens()->where('id', $tokenId)->delete();

        if (! $deleted) {
            return response()->json(['message' => __('Erişim anahtarı bulunamadı.')], 404);
        }

        return response()->json(['message' => __('Erişim anahtarı iptal edildi.')]);
    }

    /**
     * Bu hesapta açık DİĞER tüm oturumları kapat (şu anki oturum korunur).
     *
     * Şifre değişikliği/cihaz kaybı sonrası „her yerden çık" düğmesinin karşılığı.
     * Şu anki anahtar listeden özellikle DIŞLANIR — kullanıcı kendini düşürmez.
     *
     * @response array{message: string, revoked: int}
     */
    public function destroyOthers(Request $request)
    {
        $currentId = (int) ($request->user()->currentAccessToken()?->id ?? 0);

        $revoked = $request->user()->tokens()->where('id', '!=', $currentId)->delete();

        return response()->json([
            'message' => $revoked > 0
                ? __(':count oturum kapatıldı.', ['count' => $revoked])
                : __('Kapatılacak başka oturum yok.'),
            'revoked' => $revoked,
        ]);
    }

    /**
     * @param  int|null  $currentId  istekte kullanılan anahtarın id’si (arayüz
     *         „şu anki oturum” etiketi ve iptal kilidi için)
     */
    protected function present(mixed $token, ?int $currentId = null): array
    {
        return [
            'id' => (int) $token->id,
            'name' => $token->name,
            // 'session' = giriş ekranının ürettiği tarayıcı/istemci oturumu,
            // 'api' = kullanıcı tarafından burada üretilen entegrasyon anahtarı
            'kind' => $token->name === 'auth' ? 'session' : 'api',
            'current' => $currentId !== null && (int) $token->id === $currentId,
            'abilities' => $token->abilities ?: ['*'],
            'ip_address' => $token->ip_address,
            'device' => SessionDevice::label($token->user_agent),
            'last_used_at' => $token->last_used_at,
            'created_at' => $token->created_at,
            'expires_at' => $token->expires_at,
        ];
    }
}
