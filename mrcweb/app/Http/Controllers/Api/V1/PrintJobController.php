<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Document;
use App\Models\Payment;
use App\Models\PrintJob;
use App\Services\EscPos;
use App\Support\Audit;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Cihazlar + yazdırma kuyruğu .
 *
 * - `GET /devices` — cihazlar (yazıcılar + çevrimiçi bayrağı + bekleyen iş sayısı).
 * - `POST /devices {name}` — eşleşme kodu üretir (TEK gösterim).
 * - `DELETE /devices/{id}` — cihazı ve kuyruğundaki işleri düşürür.
 * - `GET /print-jobs` — kuyruk listesi (üye de izleyebilir; POS'tan fiş durumu takibi).
 * - `POST /print-jobs` — belgeyi kuyruğa at (ESC/POS ham komut ya da PDF).
 * - `DELETE /print-jobs/{id}` — kuyruktaki (basılmamış) işi iptal eder.
 */
class PrintJobController extends Controller
{
    use Concerns\ResolvesTenant;

    /**
     * Cihazları listele: yazıcılar, çevrimiçi bayrağı, bekleyen iş sayısı.
     *
     * @response array{data: array<int, array{id: int, name: string, printers: array, online: bool, pending_jobs: int, last_seen_at: string|null, created_at: string}>}
     */
    public function devices(Request $request)
    {
        $companyId = $this->tenantId($request);

        $rows = Device::where('company_id', $companyId)
            ->withCount(['jobs as pending_jobs' => fn ($q) => $q->whereIn('status', [PrintJob::STATUS_QUEUED, PrintJob::STATUS_CLAIMED])])
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $rows->map(fn (Device $d) => [
            'id' => $d->id,
            'name' => $d->name,
            'printers' => $d->printers ?? [],
            'online' => $d->isOnline(),
            'pending_jobs' => (int) $d->pending_jobs,
            'last_seen_at' => $d->last_seen_at?->format('Y-m-d H:i'),
            'created_at' => $d->created_at->format('Y-m-d H:i'),
        ])->values()]);
    }

    /**
     * Yeni cihaz için eşleşme kodu üret (tek gösterim, 15 dakika geçerli).
     *
     * @response array{device: array{id: int, name: string}, pairing_code: string, expires_at: string, message: string}
     */
    public function pairDevice(Request $request)
    {
        $this->ensureAdmin($request);
        $companyId = $this->tenantId($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        $code = $this->pairingCode();

        $device = Device::create([
            'company_id' => $companyId,
            'name' => $data['name'],
            'printers' => [],
            'pairing_code_hash' => hash('sha256', $code),
            'pairing_expires_at' => now()->addMinutes(15),
        ]);

        Audit::fromRequest($request, 'device.created', $companyId, $device, ['name' => $device->name]);

        return response()->json([
            'device' => ['id' => $device->id, 'name' => $device->name],
            'pairing_code' => $this->prettify($code),
            'expires_at' => $device->pairing_expires_at->format('Y-m-d H:i'),
            'message' => __('Eşleşme kodu üretildi.'),
        ], 201);
    }

    /**
     * Cihazı ve kuyruğundaki işleri sil.
     *
     * @response array{message: string}
     */
    public function destroyDevice(Request $request, Device $device)
    {
        $this->ensureAdmin($request);

        abort_if($device->company_id !== $this->tenantId($request), 404, __('Kayıt bulunamadı.'));

        $device->jobs()->delete();
        $device->delete();

        Audit::fromRequest($request, 'device.deleted', $this->tenantId($request), null, ['name' => $device->name]);

        return response()->json(['message' => __('Cihaz silindi.')]);
    }

    /**
     * Yazdırma kuyruğunu listele (POS fiş takibi).
     *
     * @response array{data: array<int, array{id: int, device_name: string, printer_name: string, kind: string, status: string, attempts: int, document_serial: string|null, created_at: string}>}
     */
    public function index(Request $request)
    {
        $companyId = $this->tenantId($request);

        $query = PrintJob::where('print_jobs.company_id', $companyId)
            ->join('devices as d', 'd.id', '=', 'print_jobs.device_id')
            ->leftJoin('documents as doc', 'doc.id', '=', 'print_jobs.document_id')
            ->selectRaw('print_jobs.*, d.name AS device_name, doc.serial AS document_serial');

        if ($status = $request->query('status')) {
            $query->where('print_jobs.status', in_array($status, ['queued', 'claimed', 'failed'], true) ? $status : 'queued');
        }

        $rows = $query->orderByDesc('print_jobs.id')->limit(100)->get();

        return response()->json(['data' => $rows->map(fn ($j) => [
            'id' => $j->id,
            'device_name' => $j->getAttribute('device_name'),
            'printer_name' => $j->printer_name,
            'kind' => $j->kind,
            'status' => $j->status,
            'attempts' => (int) $j->attempts,
            'document_serial' => $j->getAttribute('document_serial'),
            'created_at' => $j->created_at->format('Y-m-d H:i'),
        ])->values()]);
    }

    /**
     * Belgeyi yazdırma kuyruğuna al (ESC/POS ham komut ya da PDF).
     *
     * @response array{job: array{id: int, status: string}, message: string}
     */
    public function store(Request $request)
    {
        $companyId = $this->tenantId($request);

        $data = $request->validate([
            'device_id' => ['required', 'integer',
                Rule::exists('devices', 'id')->where('company_id', $companyId)],
            'printer_name' => ['required', 'string', 'max:120'],
            'kind' => ['nullable', 'string', Rule::in([PrintJob::KIND_ESCPOS, PrintJob::KIND_PDF])],
            'lang' => ['nullable', 'string', Rule::in(['tr', 'en'])],
            'document_id' => ['required', 'integer',
                Rule::exists('documents', 'id')->where('company_id', $companyId)],
        ]);

        $device = Device::where('company_id', $companyId)->findOrFail($data['device_id']);

        if (! in_array($data['printer_name'], $device->printers ?? [], true)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'printer_name' => [__('Bu yazıcı cihazda kayıtlı değil.')],
            ]);
        }

        $document = Document::where('company_id', $companyId)->findOrFail($data['document_id']);

        if (! in_array($document->type, [Document::TYPE_INVOICE, Document::TYPE_RETURN], true)
            || in_array($document->status, ['draft', 'cancelled'], true)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'document_id' => [__('Yalnız kesilmiş satış belgesi yazdırılır.')],
            ]);
        }

        $kind = $data['kind'] ?? PrintJob::KIND_ESCPOS;
        $lang = $data['lang'] ?? 'tr';
        $payload = $kind === PrintJob::KIND_PDF
            ? base64_encode($this->receiptPdf($document, $lang))
            : base64_encode(EscPos::buildReceipt($document, $this->tenderInfo($companyId, $document) + ['lang' => $lang]));

        $job = PrintJob::create([
            'company_id' => $companyId,
            'device_id' => $device->id,
            'printer_name' => $data['printer_name'],
            'kind' => $kind,
            'payload' => $payload,
            'status' => PrintJob::STATUS_QUEUED,
            'document_id' => $document->id,
        ]);

        Audit::fromRequest($request, 'print_job.created', $companyId, $job, [
            'device' => $device->name,
        ]);

        return response()->json([
            'job' => ['id' => $job->id, 'status' => $job->status],
            'message' => __('Yazdırma kuyruğa alındı.'),
        ], 201);
    }

    /**
     * Kuyruktaki (basılmamış) işi iptal et.
     *
     * @response array{message: string}
     */
    public function destroy(Request $request, PrintJob $job)
    {
        $this->ensureAdmin($request);

        abort_if($job->company_id !== $this->tenantId($request), 404, __('Kayıt bulunamadı.'));

        if ($job->status !== PrintJob::STATUS_QUEUED) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'status' => [__('Yalnız kuyruktaki iş iptal edilir.')],
            ]);
        }

        $job->delete();

        return response()->json(['message' => __('Yazdırma işi iptal edildi.')]);
    }

    protected function ensureAdmin(Request $request): void
    {
        if (! $request->user()->hasRole('Admin')) {
            abort(403, __('Bu işlem için yönetici yetkisi gereklidir.'));
        }
    }

    protected function pairingCode(): string
    {
        $alfabe = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $kod = '';

        for ($i = 0; $i < 8; $i++) {
            $kod .= $alfabe[random_int(0, strlen($alfabe) - 1)];
        }

        return $kod;
    }

    protected function prettify(string $code): string
    {
        return substr($code, 0, 4).'-'.substr($code, 4, 4);
    }

    /** Fişte para üstü görünsün diye belgenin son tahsilatındaki alınan tutar. */
    protected function tenderInfo(int $companyId, Document $document): array
    {
        $tendered = Payment::where('company_id', $companyId)
            ->where('document_id', $document->id)
            ->whereNotNull('tendered_amount')
            ->orderByDesc('id')
            ->value('tendered_amount');

        return $tendered === null ? [] : ['tendered' => (float) $tendered];
    }

    protected function receiptPdf(Document $document, string $lang = 'tr'): string
    {
        $previous = app()->getLocale();
        app()->setLocale($lang);
        $payload = (new DocumentPdfController)->payload($document);
        app()->setLocale($previous);
        $payload['isReceipt'] = true;
        $payload['size'] = '80';
        $payload['paymentMethodLabel'] = __('Nakit');
        $payload['tendered'] = null;
        $payload['change'] = null;

        return Pdf::loadView('pdf.receipt', $payload)
            ->setPaper([0, 0, 226.8, 1000])
            ->output();
    }
}
