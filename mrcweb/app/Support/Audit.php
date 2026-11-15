<?php

namespace App\Support;

use App\Models\AuditEvent;
use Illuminate\Database\Eloquent\Model;

/**
 * Denetim kaydı — TEK yazma noktası .
 *
 * Her controller kendi olayını kendi cümlesiyle yazarsa bir süre sonra aynı eylem
 * üç farklı adla loglanır ve rapor „kimin ne yaptığı" sorusunu cevaplayamaz.
 * Eylem adları (`document.created` …) burada sabit listede durur; yeni eylem
 * gerektiğinde ÖNCE bu liste büyür, sonra çağrı yazılır.
 *
 * Kırmızı çizgi: `meta`'ya sır yazılmaz. Ödeme anahtarları, şifreler, 2FA sırları,
 * yedek kodlar bu fonksiyona DEĞER olarak bile geçemez — çağıran tarafta meta
 * kurulurken ayıklanır, burada ikinci bir sigorta yoktur çünkü sigorta „çağıran
 * dikkat eder" olamaz. (PaymentSettingController meta'ya yalnız provider + bayrak
 * koyar; TwoFactorController meta'ya kod koymaz.)
 *
 * Kayıt yazımı asla işi BOZMAZ: denetim yazılamazsa (DB hatası) asıl işlem geri
 * alınmaz — işin durması, izinin kaybolmasından kötüdür. Hata log'a düşer.
 */
class Audit
{
    /** Bilinen eylemler (liste dışı eylem 500 değil 422 mantığıyla reddedilir — testte). */
    public const ACTIONS = [
        'auth.login', 'auth.logout', 'auth.2fa.enabled', 'auth.2fa.disabled',
        'auth.password_reset_requested', 'auth.password_reset', 'auth.email_verified',
        'auth.verification_sent',
        'document.created', 'document.updated', 'document.status', 'document.converted', 'document.deleted',
        'document.returned', 'document.duplicated',
        'payment.created', 'payment.deleted',
        'payment_link.created', 'payment_link.cancelled',
        'payment_settings.updated',
        'invitation.created', 'invitation.cancelled', 'invitation.accepted',
        'settings.updated', 'company.updated', 'product.updated',
        'module.updated', 'plugin.installed',
        'stock_transfer.created', 'stock_count.created', 'stock_count.approved', 'stock_count.cancelled',
        'device.created', 'device.paired', 'device.deleted',
        'print_job.created',
        'print_template.created', 'print_template.updated', 'print_template.deleted', 'print_template.default',
        'company.join_request.approved', 'company.join_request.rejected',
    ];

    public static function record(
        string $action,
        ?int $companyId = null,
        ?int $userId = null,
        ?Model $auditable = null,
        array $meta = [],
        ?string $ip = null,
    ): ?AuditEvent {
        if (! in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException("Bilinmeyen denetim eylemi: {$action}");
        }

        try {
            return AuditEvent::create([
                'company_id' => $companyId,
                'user_id' => $userId,
                'action' => $action,
                'auditable_type' => $auditable ? $auditable::class : null,
                'auditable_id' => $auditable?->getKey(),
                'meta' => $meta === [] ? null : $meta,
                'ip' => $ip,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /** Controller kısayolu: istekten kullanıcı + IP'yi alır. */
    public static function fromRequest(
        \Illuminate\Http\Request $request,
        string $action,
        ?int $companyId = null,
        ?Model $auditable = null,
        array $meta = [],
    ): ?AuditEvent {
        return static::record(
            $action,
            $companyId ?? (int) ($request->user()?->current_company_id),
            (int) $request->user()?->id,
            $auditable,
            $meta,
            $request->ip()
        );
    }
}
