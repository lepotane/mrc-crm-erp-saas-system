<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\PresentsPaginator;
use App\Http\Controllers\Api\V1\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use Illuminate\Http\Request;

/**
 * Denetim kaydı listesi .
 *
 * YALNIZ Admin: denetimde herkesin işlemi görünür (Personel'in kendi işlemi dahil)
 * ve bu şeffaflık tek taraflıdır — Personel başkasının işlemini göremez.
 * Kiracı süzgeci zorunlu, yetkisiz erişimde 403.
 *
 * Süzgeçler: `action`, `user_id`, `from`/`to` (tarih), `auditable_type` + `auditable_id`
 * (belirli kaydın geçmişi), `search` (kullanıcı adı/e-posta + eylem adı).
 * Kayıtlar SALT OKUNURDUR: POST/PUT/DELETE ucu yoktur (modelde de update/delete yok).
 *
 * @response array{data: array<int, array{id: int, action: string, action_label: string, user: array{id: int, name: string, email: string}|null, auditable: array{type: string, id: int, label: string}|null, meta: array|null, ip: string|null, created_at: string|null}>, links: array, meta: array}
 */
class AuditController extends Controller
{
    use ResolvesTenant, PresentsPaginator;

    /** Denetim kayıtlarını listele (YALNIZ Admin). */
    public function index(Request $request)
    {
        if (! $request->user()->hasRole('Admin')) {
            abort(403, __('Bu işlem için yönetici yetkisi gereklidir.'));
        }

        $companyId = $this->tenantId($request);

        $query = AuditEvent::with('user')
            ->where('company_id', $companyId);

        if (($action = trim((string) $request->query('action', ''))) !== '') {
            $query->where('action', $action);
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->query('user_id'));
        }

        if (($from = trim((string) $request->query('from', ''))) !== '') {
            $query->whereDate('created_at', '>=', $from);
        }

        if (($to = trim((string) $request->query('to', ''))) !== '') {
            $query->whereDate('created_at', '<=', $to);
        }

        if (($type = trim((string) $request->query('auditable_type', ''))) !== '') {
            $query->where('auditable_type', $this->resolveType($type));
        }

        if ($request->filled('auditable_id')) {
            $query->where('auditable_id', (int) $request->query('auditable_id'));
        }

        if (($search = trim((string) $request->query('search', ''))) !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('action', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($u) => $u
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%"));
            });
        }

        $paginator = $query->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(min((int) ($request->query('per_page', 20) ?: 20), 100))
            ->withQueryString();

        $paginator->getCollection()->transform(fn (AuditEvent $e) => $this->present($e));

        return response()->json($this->paginateJson($paginator));
    }

    /** Kısa tür adı → model sınıfı (istemci `Document:12` yazmaz, `document:12` yazar). */
    protected function resolveType(string $type): string
    {
        return match (mb_strtolower($type)) {
            'document' => \App\Models\Document::class,
            'payment' => \App\Models\Payment::class,
            'payment_link', 'paymentlink' => \App\Models\PaymentLink::class,
            'invitation' => \App\Models\Invitation::class,
            'user' => \App\Models\User::class,
            'company' => \App\Models\Company::class,
            default => $type,
        };
    }

    protected function present(AuditEvent $event): array
    {
        $auditable = null;

        if ($event->auditable_type !== null && $event->auditable_id !== null) {
            $auditable = [
                'type' => $event->auditable_type,
                'id' => (int) $event->auditable_id,
                'label' => $this->auditableLabel($event),
            ];
        }

        return [
            'id' => $event->id,
            'action' => $event->action,
            'action_label' => AuditEvent::label($event->action),
            'user' => $event->user ? $event->user->only(['id', 'name', 'email']) : null,
            'auditable' => $auditable,
            'meta' => $event->meta,
            'ip' => $event->ip,
            'created_at' => $event->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    /** Kayıt silinmişse bile meta'dan okunur etiket (denetim „ölü kayıt" göstermez). */
    protected function auditableLabel(AuditEvent $event): string
    {
        $meta = $event->meta ?? [];

        return (string) ($meta['serial']
            ?? $meta['from_serial']
            ?? $meta['to_serial']
            ?? $meta['email']
            ?? $event->auditable_type.' #'.$event->auditable_id);
    }
}
