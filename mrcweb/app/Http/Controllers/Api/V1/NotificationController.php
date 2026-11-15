<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\CompanyJoinRequest;
use App\Models\Document;
use App\Models\Product;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    use Concerns\ResolvesTenant;

    protected function scopedQuery($user, ?int $companyId)
    {
        $query = AppNotification::query()->where('user_id', $user->id);
        if (! $user->isSuperAdmin() && $companyId) {
            $query->where('company_id', $companyId);
        }

        return $query;
    }

    /**
     * Aktif kullanıcı ve firma için bildirim listesi ve okunmamış sayısı.
     *
     * @response array{notifications: array<int, array{id: int, type: string, title: string, body: string|null, action_url: string|null, color: string, read_at: string|null, created_at: string}>, unread_count: int}
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $companyId = $this->tenantId($request);

        // Kullanıcı için bu firmada hiç bildirim üretilmemişse başlangıç ve operasyonel bildirimleri senkronla
        $hasAny = $this->scopedQuery($user, $companyId)->exists();

        if (! $hasAny && $companyId) {
            $this->seedSmartOperationalNotifications($companyId, $user->id);
        }

        $items = $this->scopedQuery($user, $companyId)
            ->orderByRaw('CASE WHEN read_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('created_at')
            ->limit(40)
            ->get()
            ->map(fn (AppNotification $n) => $this->present($n));

        $unreadCount = $this->scopedQuery($user, $companyId)
            ->whereNull('read_at')
            ->count();

        return response()->json([
            'notifications' => $items,
            'unread_count' => $unreadCount,
        ]);
    }

    /**
     * Tek bir bildirimi okundu olarak işaretle.
     *
     * @response array{message: string, unread_count: int}
     */
    public function markRead(Request $request, AppNotification $notification)
    {
        $user = $request->user();
        $companyId = $this->tenantId($request);

        abort_if(
            $notification->user_id !== $user->id || (! $user->isSuperAdmin() && $companyId && $notification->company_id !== $companyId),
            404,
            __('Bildirim bulunamadı.')
        );

        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        $unreadCount = $this->scopedQuery($user, $companyId)
            ->whereNull('read_at')
            ->count();

        return response()->json([
            'message' => __('Bildirim okundu olarak işaretlendi.'),
            'unread_count' => $unreadCount,
        ]);
    }

    /**
     * Tüm bildirimleri okundu olarak işaretle.
     *
     * @response array{message: string, unread_count: int}
     */
    public function markAllRead(Request $request)
    {
        $user = $request->user();
        $companyId = $this->tenantId($request);

        $this->scopedQuery($user, $companyId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'message' => __('Tüm bildirimler okundu.'),
            'unread_count' => 0,
        ]);
    }

    /**
     * Tek bir bildirimi sil.
     *
     * @response array{message: string, unread_count: int}
     */
    public function destroy(Request $request, AppNotification $notification)
    {
        $user = $request->user();
        $companyId = $this->tenantId($request);

        abort_if(
            $notification->user_id !== $user->id || (! $user->isSuperAdmin() && $companyId && $notification->company_id !== $companyId),
            404,
            __('Bildirim bulunamadı.')
        );

        $notification->delete();

        $unreadCount = $this->scopedQuery($user, $companyId)
            ->whereNull('read_at')
            ->count();

        return response()->json([
            'message' => __('Bildirim silindi.'),
            'unread_count' => $unreadCount,
        ]);
    }

    /**
     * Tüm bildirimleri temizle.
     *
     * @response array{message: string, unread_count: int}
     */
    public function clearAll(Request $request)
    {
        $user = $request->user();
        $companyId = $this->tenantId($request);

        $this->scopedQuery($user, $companyId)->delete();

        return response()->json([
            'message' => __('Tüm bildirimler temizlendi.'),
            'unread_count' => 0,
        ]);
    }

    protected function seedSmartOperationalNotifications(int $companyId, int $userId): void
    {
        try {
            // 1. Bekleyen katılım başvurusu varsa bildir
            $pendingApps = CompanyJoinRequest::query()
                ->where('company_id', $companyId)
                ->where('status', 'pending')
                ->count();

            if ($pendingApps > 0) {
                AppNotification::create([
                    'company_id' => $companyId,
                    'user_id' => $userId,
                    'type' => 'application',
                    'title' => __('Bekleyen İş / Katılım Başvurusu'),
                    'body' => __(':count adet personel başvurusu incelemenizi bekliyor.', ['count' => $pendingApps]),
                    'action_url' => '/kullanicilar',
                    'color' => '#BC5FCF',
                ]);
            }

            // 2. Vadesi geçen veya yaklaşan belgeler
            $overdueCount = Document::query()
                ->where('company_id', $companyId)
                ->where('status', 'issued')
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<=', now()->addDays(3)->toDateString())
                ->count();

            if ($overdueCount > 0) {
                AppNotification::create([
                    'company_id' => $companyId,
                    'user_id' => $userId,
                    'type' => 'invoice',
                    'title' => __('Vade & Tahsilat Hatırlatması'),
                    'body' => __(':count adet faturanın vadesi geldi veya yaklaşıyor.', ['count' => $overdueCount]),
                    'action_url' => '/borc-alacak',
                    'color' => '#DC6F31',
                ]);
            }

            // 3. Kritik stok uyarısı
            $lowStockCount = Product::query()
                ->where('company_id', $companyId)
                ->where('track_stock', true)
                ->whereColumn('stock_quantity', '<=', 'min_stock')
                ->count();

            if ($lowStockCount > 0) {
                AppNotification::create([
                    'company_id' => $companyId,
                    'user_id' => $userId,
                    'type' => 'stock',
                    'title' => __('Kritik Stok Uyarısı'),
                    'body' => __(':count üründe stok miktarı minimum seviyenin altına düştü.', ['count' => $lowStockCount]),
                    'action_url' => '/stok',
                    'color' => '#D45759',
                ]);
            }

            // 4. Sistem hoş geldin bildirimi
            AppNotification::create([
                'company_id' => $companyId,
                'user_id' => $userId,
                'type' => 'system',
                'title' => __('MRC SaaS Bildirim Merkezi Aktif'),
                'body' => __('Yeni başvurular, vade uyarıları ve kritik stok hareketleri anlık olarak burada listelenir.'),
                'action_url' => '/',
                'color' => '#B9FF66',
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    protected function present(AppNotification $n): array
    {
        return [
            'id' => $n->id,
            'type' => $n->type,
            'title' => $n->title,
            'body' => $n->body,
            'action_url' => $n->action_url ?: '/',
            'color' => $n->color ?: '#B9FF66',
            'read' => $n->read_at !== null,
            'read_at' => $n->read_at?->toIso8601String(),
            'created_at' => $n->created_at?->toIso8601String(),
        ];
    }
}
