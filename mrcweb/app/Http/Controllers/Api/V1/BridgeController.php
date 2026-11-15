<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\PrintJob;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bridge agent protokolü  — cihaz anahtarıyla konuşur (kullanıcı yok).
 *
 * - `POST /bridge/pair` (anahtarsız; eşleşme kodu kimliktir): yazıcı listesini
 *   bildirir, cihaz anahtarı alır. Kod tek kullanımlık + 15 dk ömürlüdür.
 * - `GET /bridge/poll?wait=` — kuyruktaki işi alıp kilitler (`claimed` +
 *   `claim_token`); boşsa `wait` sn bekleyip 204 döner.
 * - `POST /bridge/jobs/{job}/ack` — basıldı/kaldı bildirimi. Yalnız güncel
 *   `claim_token` kabul edilir (çift baskı kilidi). `printed` satırı SİLİNİR.
 * - `POST /bridge/rotate` — cihaz anahtarını döndürür (eski anında düşer).
 */
class BridgeController extends Controller
{
    /**
     * Eşleşme koduyla cihazı bağla, cihaz anahtarı üret (tek kullanımlık kod).
     *
     * @response array{device: array{id: int, name: string}, token: string}
     */
    public function pair(Request $request)
    {
        $data = $request->validate([
            'pairing_code' => ['required', 'string', 'max:16'],
            'printers' => ['required', 'array', 'min:1', 'max:10'],
            'printers.*' => ['required', 'string', 'max:120'],
        ]);

        $code = strtoupper(str_replace(['-', ' '], '', (string) $data['pairing_code']));

        $device = Device::where('pairing_code_hash', hash('sha256', $code))->first();

        if ($device === null || $device->pairing_expires_at === null || $device->pairing_expires_at->isPast()) {
            abort(404, __('Eşleştirme kodu geçersiz veya süresi dolmuş.'));
        }

        $token = bin2hex(random_bytes(32));

        $device->update([
            'printers' => array_values($data['printers']),
            'pairing_code_hash' => null,
            'pairing_expires_at' => null,
            'token_hash' => hash('sha256', $token),
            'last_seen_at' => now(),
        ]);

        Audit::record('device.paired', $device->company_id, null, $device, [
            'printers' => $device->printers,
        ]);

        return response()->json([
            'device' => ['id' => $device->id, 'name' => $device->name],
            'token' => $token,
        ]);
    }

    /**
     * Kuyruktaki işi kilitleyip al (boşsa `wait` saniye bekleyip 204 döner).
     */
    public function poll(Request $request)
    {
        /** @var Device $device */
        $device = $request->attributes->get('bridge_device');

        $wait = min(25, max(0, (int) $request->query('wait', 0)));
        $deadline = time() + $wait;

        do {
            $job = $this->nextJob($device);

            if ($job !== null) {
                return response()->json(['job' => $this->presentJob($job)]);
            }

            if (time() >= $deadline) {
                break;
            }

            sleep(1);
        } while (true);

        return response()->noContent();
    }

    /**
     * Baskı sonucunu bildir: basıldı (satır silinir) ya da başarısız (kilitli).
     *
     * @response array{message: string, deleted?: bool, status?: string}
     */
    public function ack(Request $request, PrintJob $job)
    {
        /** @var Device $device */
        $device = $request->attributes->get('bridge_device');

        if ($job->company_id !== $device->company_id || (int) $job->device_id !== (int) $device->id) {
            abort(404, __('Kayıt bulunamadı.'));
        }

        $data = $request->validate([
            'claim_token' => ['required', 'string', 'max:64'],
            'status' => ['required', 'string', 'in:printed,failed'],
            'error' => ['nullable', 'string', 'max:255'],
        ]);

        if ($job->status !== PrintJob::STATUS_CLAIMED || ! hash_equals((string) $job->claim_token, $data['claim_token'])) {
            abort(403, __('Talep kilidi geçersiz.'));
        }

        if ($data['status'] === 'printed') {
            $job->delete();

            return response()->json(['message' => __('Baskı tamamlandı.'), 'deleted' => true]);
        }

        if ($job->attempts >= PrintJob::MAX_ATTEMPTS) {
            $job->update([
                'status' => PrintJob::STATUS_FAILED,
                'claim_token' => null,
                'error' => $data['error'] ?? null,
            ]);

            return response()->json(['message' => __('Baskı başarısız sayıldı.'), 'status' => 'failed']);
        }

        $job->update([
            'status' => PrintJob::STATUS_QUEUED,
            'claim_token' => null,
            'claimed_at' => null,
            'error' => $data['error'] ?? null,
        ]);

        return response()->json(['message' => __('İş kuyruğa döndü.'), 'status' => 'queued']);
    }

    /**
     * Cihaz anahtarını döndür (eski anahtar anında düşer).
     *
     * @response array{token: string, message: string}
     */
    public function rotate(Request $request)
    {
        /** @var Device $device */
        $device = $request->attributes->get('bridge_device');

        $token = bin2hex(random_bytes(32));
        $device->update(['token_hash' => hash('sha256', $token), 'last_seen_at' => now()]);

        return response()->json([
            'token' => $token,
            'message' => __('Cihaz anahtarı yenilendi.'),
        ]);
    }

    protected function nextJob(Device $device): ?PrintJob
    {
        $staleBefore = now()->subSeconds(PrintJob::CLAIM_TTL_SECONDS);

        $job = PrintJob::where('device_id', $device->id)
            ->where('company_id', $device->company_id)
            ->where('status', PrintJob::STATUS_QUEUED)
            ->orderBy('id')
            ->first();

        if ($job === null) {
            $job = PrintJob::where('device_id', $device->id)
                ->where('company_id', $device->company_id)
                ->where('status', PrintJob::STATUS_CLAIMED)
                ->where('claimed_at', '<', $staleBefore)
                ->orderBy('id')
                ->first();
        }

        if ($job === null) {
            return null;
        }

        return DB::transaction(function () use ($job) {
            $fresh = PrintJob::whereKey($job->id)->lockForUpdate()->first();

            if ($fresh === null || ! in_array($fresh->status, [PrintJob::STATUS_QUEUED, PrintJob::STATUS_CLAIMED], true)) {
                return null;
            }

            if ($fresh->status === PrintJob::STATUS_CLAIMED
                && $fresh->claimed_at !== null
                && $fresh->claimed_at->greaterThanOrEqualTo(now()->subSeconds(PrintJob::CLAIM_TTL_SECONDS))) {
                return null; // Başka uzun-poll aynı işi kaptı.
            }

            $fresh->update([
                'status' => PrintJob::STATUS_CLAIMED,
                'claim_token' => bin2hex(random_bytes(32)),
                'claimed_at' => now(),
                'attempts' => $fresh->attempts + 1,
            ]);

            return $fresh->refresh();
        });
    }

    protected function presentJob(PrintJob $job): array
    {
        return [
            'id' => $job->id,
            'kind' => $job->kind,
            'printer_name' => $job->printer_name,
            'payload' => $job->payload,
            'claim_token' => $job->claim_token,
            'attempts' => (int) $job->attempts,
            'document_id' => $job->document_id,
        ];
    }
}
