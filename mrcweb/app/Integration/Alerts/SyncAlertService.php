<?php

namespace App\Integration\Alerts;

use App\Integration\Sync\SyncLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Senkronizasyon hataları için kısa Telegram bildirimi ve pano sağlığı özeti.
 *
 * Gizlilik: token/sohbet yalnızca config(env) üzerinden okunur; ne loglanır ne
 * de önbelleğe yazılır. İstisnalar yutulur — bildirim, senkronizasyonu asla
 * başarısız yapmaz. İstek gövdesindeki sayılar dışında ham veri gönderilmez.
 */
class SyncAlertService
{
    /** Pano için izlenen varlıklar => sync_logs.entity karşılıkları. */
    private const ENTITIES = [
        'orders' => ['orders', 'order'],
        'catalog' => ['catalog'],
        'writeback' => ['writeback'],
        'translation' => ['translation'],
        'resync' => ['resync', 'mrc_resync'],
    ];

    private const ERROR_STATES = ['failed', 'error', 'exception', 'drift'];

    private const WARNING_STATES = ['partial', 'warning', 'skipped', 'none', 'unknown'];

    /**
     * Tek bir kısa Türkçe bildirim gönderir (kapalıyken hiçbir şey yapmaz).
     *
     * @param  array<string,mixed>  $counts  Yalnızca sayılar / kısa sayaçlar.
     */
    public function notify(string $event, array $counts, ?string $error = null): void
    {
        try {
            if (! config('alerts.enabled')) {
                return;
            }

            $token = config('alerts.token');
            $chat = config('alerts.chat');

            if (! is_string($token) || $token === '' || ! is_string($chat) || $chat === '') {
                return;
            }

            $interval = max(1, (int) config('alerts.min_interval_minutes', 15));

            // Olay + aralık dakikası bucket'ı: aynı olay 15 dk içinde bir kez
            // gönderilir. Gizli değerler önbellek anahtarına girmez.
            $bucket = intdiv(now()->getTimestamp(), $interval * 60);
            $cacheKey = 'sync_alert:'.md5($event).':'.$bucket;

            if (! Cache::add($cacheKey, 1, now()->addMinutes($interval))) {
                return;
            }

            $text = $this->message($event, $counts, $error);

            Http::timeout(10)->asForm()->post((string) config('alerts.endpoint'), [
                'token' => $token,
                'chat' => $chat,
                'text' => $text,
            ]);
        } catch (Throwable) {
            // Bildirim hatası senkronizasyonu etkilemez; payload/token loglanmaz.
        }
    }

    /**
     * Firmanın son senkronizasyon sonuçlarını varlık bazında döner.
     *
     * @return array{items: array<int,array<string,mixed>>, overall: string}
     */
    public function syncHealth(int $companyId): array
    {
        $now = now();
        $items = [];
        $overall = 'ok';

        foreach (self::ENTITIES as $entity => $aliases) {
            /** @var SyncLog|null $log */
            $log = SyncLog::query()
                ->where('company_id', $companyId)
                ->whereIn('entity', $aliases)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();

            if ($log === null) {
                $items[] = [
                    'entity' => $entity,
                    'status' => 'none',
                    'created_at' => null,
                    'age_minutes' => null,
                    'duration_ms' => null,
                    'summary' => null,
                ];
                $overall = $this->worsen($overall, 'warning');

                continue;
            }

            $status = strtolower((string) $log->status);
            $ageMinutes = $log->created_at === null ? null : $log->created_at->diffInMinutes($now);
            $state = $this->state($status, $ageMinutes);
            $overall = $this->worsen($overall, $state);

            $items[] = [
                'entity' => $entity,
                'status' => $status === '' ? 'none' : $status,
                'created_at' => $log->created_at?->format('Y-m-d H:i'),
                'age_minutes' => $ageMinutes,
                'duration_ms' => $log->duration_ms === null ? null : (int) $log->duration_ms,
                'summary' => $this->summary($log->preview),
            ];
        }

        return ['items' => $items, 'overall' => $overall];
    }

    /** @param  array<string,mixed>  $counts */
    private function message(string $event, array $counts, ?string $error): string
    {
        $parts = [];

        foreach ($counts as $key => $value) {
            if (is_array($value)) {
                $value = count($value);
            } elseif (is_bool($value)) {
                $value = $value ? 1 : 0;
            }

            if (! is_scalar($value)) {
                continue;
            }

            $parts[] = $key.':'.$value;
        }

        $text = $event.($parts === [] ? '' : ' | '.implode(' · ', $parts));

        $error = $error === null ? '' : trim((string) preg_replace('/\s+/u', ' ', $error));
        if ($error !== '') {
            $text .= ' | Hata: '.mb_substr($error, 0, 160);
        }

        return $text;
    }

    /** Maskelenmiş preview JSON'ından kısa "anahtar:değer" özeti çıkarır. */
    private function summary(mixed $preview): ?string
    {
        $data = is_string($preview) ? json_decode($preview, true) : $preview;
        if (! is_array($data)) {
            return null;
        }

        $parts = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = count($value);
            } elseif (is_bool($value)) {
                $value = $value ? 1 : 0;
            }

            if (is_scalar($value)) {
                $parts[] = $key.':'.$value;
            }
        }

        return $parts === [] ? null : implode(' · ', array_slice($parts, 0, 6));
    }

    /** Ham durum + yaş -> pano durumu. */
    private function state(string $status, ?int $ageMinutes): string
    {
        if (in_array($status, self::ERROR_STATES, true)) {
            return 'error';
        }

        if ($ageMinutes !== null && $ageMinutes > 1440) {
            return 'warning';
        }

        if (in_array($status, self::WARNING_STATES, true)) {
            return 'warning';
        }

        if (in_array($status, ['success', 'ok', 'synced', 'done', 'completed'], true)) {
            return 'ok';
        }

        return 'warning';
    }

    /** En kötü durum kazanır: error > warning > ok. */
    private function worsen(string $current, string $next): string
    {
        $rank = ['ok' => 0, 'warning' => 1, 'error' => 2];

        return ($rank[$next] ?? 0) > ($rank[$current] ?? 0) ? $next : $current;
    }
}
