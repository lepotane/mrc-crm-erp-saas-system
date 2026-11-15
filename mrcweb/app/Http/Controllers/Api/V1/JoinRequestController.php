<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\Company;
use App\Models\CompanyJoinRequest;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class JoinRequestController extends Controller
{
    /**
     * Başvuru kabul eden aktif firmaları AJAX ile ara ve seçilen firmanın başvuru formu şemasını getir.
     *
     * @unauthenticated
     *
     * @response array{companies: array<int, array{id: int, name: string, city: string|null, form_schema: array}>}
     */
    public function publicCompanies(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        $query = Company::query()
            ->where('accepting_applications', true)
            ->where('approval_status', 'active')
            ->orderBy('name');

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('name', 'like', '%'.$q.'%')
                    ->orWhere('city', 'like', '%'.$q.'%')
                    ->orWhere('slug', 'like', '%'.$q.'%');
            });
        }

        $companies = $query->limit(30)->get()->map(fn (Company $c) => [
            'id' => $c->id,
            'name' => $c->name,
            'city' => $c->city,
            'form_schema' => $c->resolvedApplicationFormSchema(),
        ]);

        return response()->json([
            'companies' => $companies,
        ]);
    }

    /**
     * Güvenli bulanık firma doğrulama ve "Bunu mu demek istediniz?" motoru (Geriye dönük uyumluluk)
     *
     * @unauthenticated
     *
     * @response array{status: string, message: string, company: array{id: int, name: string}|null}
     */
    public function lookupCompany(Request $request)
    {
        $data = $request->validate([
            'query' => ['required', 'string', 'min:3', 'max:120'],
        ]);

        $rawQuery = trim($data['query']);
        $normQuery = $this->normalizeText($rawQuery);
        $queryTokens = $this->extractMeaningfulTokens($normQuery);

        if (empty($queryTokens) || mb_strlen(implode('', $queryTokens)) < 3) {
            return response()->json([
                'status' => 'too_short',
                'message' => __('En az 3 anlamlı karakter girin.'),
                'company' => null,
            ]);
        }

        // Yalnız başvuru ALAN firmalar listelenir. Pasif/kapalı/pending firmalar
        // (ve var olmayan firmalar) kapatılan sayfada sayılmamalı — hem bilgi
        // sızıntısı hem de "onaylanmayacak başvuru" ihtimali.
        $companies = Company::query()
            ->where('accepting_applications', true)
            ->where('approval_status', 'active')
            ->get(['id', 'name', 'city', 'application_form_schema']);

        $exactMatch = null;
        $bestSuggestion = null;
        $bestScore = 0.0;
        $needsMoreDetailTriggered = false;

        foreach ($companies as $company) {
            $normCompanyFull = $this->normalizeText($company->name);
            $companyTokens = $this->extractMeaningfulTokens($normCompanyFull);

            if (empty($companyTokens)) {
                continue;
            }

            $cleanCompanyJoined = implode(' ', $companyTokens);
            $cleanQueryJoined = implode(' ', $queryTokens);

            if ($normQuery === $normCompanyFull || $cleanQueryJoined === $cleanCompanyJoined) {
                $exactMatch = $company;
                break;
            }

            if (count($queryTokens) === 1) {
                $qWord = $queryTokens[0];
                $cFirstWord = $companyTokens[0];

                if ($qWord === $cFirstWord && mb_strlen($qWord) >= 5) {
                    $score = 86.0;
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestSuggestion = $company;
                    }

                    continue;
                }

                if (str_starts_with($cFirstWord, $qWord) || levenshtein($qWord, $cFirstWord) <= 2) {
                    $needsMoreDetailTriggered = true;
                }

                continue;
            }

            $matchedTokens = 0;
            $tokenScoreSum = 0.0;
            $compareCount = min(count($queryTokens), count($companyTokens));

            for ($i = 0; $i < $compareCount; $i++) {
                $qt = $queryTokens[$i];
                $ct = $companyTokens[$i];

                if ($qt === $ct) {
                    $matchedTokens++;
                    $tokenScoreSum += 100.0;
                } else {
                    $maxLen = max(mb_strlen($qt), mb_strlen($ct));
                    $dist = levenshtein($qt, $ct);
                    $sim = $maxLen > 0 ? (1.0 - ($dist / $maxLen)) * 100.0 : 0.0;

                    if (($sim >= 68.0 && mb_strlen($qt) >= 3) || (mb_strlen($qt) >= 4 && str_starts_with($ct, $qt))) {
                        $matchedTokens++;
                        $tokenScoreSum += $sim;
                    }
                }
            }

            if ($matchedTokens >= 2) {
                $avgTokenScore = $tokenScoreSum / $compareCount;
                similar_text($cleanQueryJoined, $cleanCompanyJoined, $overallPercent);
                $combinedScore = ($avgTokenScore * 0.65) + ($overallPercent * 0.35);

                if ($combinedScore >= 68.0 && $combinedScore > $bestScore) {
                    $bestScore = $combinedScore;
                    $bestSuggestion = $company;
                }
            } elseif ($matchedTokens === 1) {
                $needsMoreDetailTriggered = true;
            }
        }

        if ($exactMatch) {
            return response()->json([
                'status' => 'exact',
                'message' => __('Firma doğrulandı: :name', ['name' => $exactMatch->name]),
                'company' => [
                    'id' => $exactMatch->id,
                    'name' => $exactMatch->name,
                    'form_schema' => $exactMatch->resolvedApplicationFormSchema(),
                ],
            ]);
        }

        if ($bestSuggestion) {
            return response()->json([
                'status' => 'suggestion',
                'message' => __('Bunu mu demek istediniz: :name?', ['name' => $bestSuggestion->name]),
                'company' => [
                    'id' => $bestSuggestion->id,
                    'name' => $bestSuggestion->name,
                    'form_schema' => $bestSuggestion->resolvedApplicationFormSchema(),
                ],
            ]);
        }

        if ($needsMoreDetailTriggered || count($queryTokens) === 1) {
            return response()->json([
                'status' => 'need_more_detail',
                'message' => __('Güvenlik koruması: Firma ismini biraz daha detaylandırın (tam unvan veya ikinci kelimeyi ekleyin).'),
                'company' => null,
            ]);
        }

        return response()->json([
            'status' => 'not_found',
            'message' => __('Eşleşen kayıtlı bir firma bulunamadı. Lütfen firma adını kontrol edin.'),
            'company' => null,
        ]);
    }

    /**
     * Katıl (İş & Personel Başvurusu + CV Yükleme) oluştur (Herkese açık)
     *
     * @unauthenticated
     *
     * @response array{message: string, request_id: int, company_name: string}
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:500'],
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'requested_class' => ['nullable', 'string', 'max:80'],
            'message' => ['nullable', 'string', 'max:2000'],
            'custom_answers' => ['nullable'],
            'cv_file' => ['nullable', 'file', 'mimes:pdf,doc,docx,png,jpg,jpeg', 'max:8192'],
        ]);

        $company = Company::findOrFail($data['company_id']);

        // Kapı: bu firma başvuru alıyor mu? (kapalı/pasif/pending firmaya
        // herkese açık uçtan başvuru + yöneticilere bildirim gönderilemez)
        if (! $company->accepting_applications) {
            return response()->json([
                'message' => __('Bu firma şu anda başvuru kabul etmiyor.'),
            ], 422);
        }

        if ($company->approval_status !== 'active') {
            return response()->json([
                'message' => __('Bu firma şu anda başvuru kabul etmiyor.'),
            ], 422);
        }

        $cvPath = null;
        $cvOriginalName = null;

        if ($request->hasFile('cv_file') && $request->file('cv_file')->isValid()) {
            $file = $request->file('cv_file');
            $cvOriginalName = $file->getClientOriginalName();
            $cvPath = $file->store('cvs/'.$company->id, 'public');
        }

        $customAnswers = null;
        if (! empty($data['custom_answers'])) {
            if (is_string($data['custom_answers'])) {
                $decoded = json_decode($data['custom_answers'], true);
                $customAnswers = is_array($decoded) ? $decoded : null;
            } elseif (is_array($data['custom_answers'])) {
                $customAnswers = $data['custom_answers'];
            }
        }

        $existingPending = CompanyJoinRequest::query()
            ->where('company_id', $company->id)
            ->where('email', strtolower(trim($data['email'])))
            ->where('status', 'pending')
            ->first();

        $payload = [
            'company_id' => $company->id,
            'first_name' => trim($data['first_name']),
            'last_name' => trim($data['last_name']),
            'email' => strtolower(trim($data['email'])),
            'password_hash' => Hash::make($data['password']),
            // Onay anında "bu e-posta zaten kayıtlı hesabın parolası mı?" kontrolü
            // için şifreli kopyası (hesap ele geçirme koruması). Onaydan sonra silinir.
            'password_encrypted' => Crypt::encryptString($data['password']),
            'phone' => $data['phone'] ?? null,
            'address' => $data['address'] ?? null,
            'requested_class' => $data['requested_class'] ?? 'Personel',
            'message' => $data['message'] ?? null,
            'custom_answers' => $customAnswers,
            'status' => 'pending',
        ];

        if ($cvPath !== null) {
            $payload['cv_path'] = $cvPath;
            $payload['cv_original_name'] = $cvOriginalName;
        }

        if ($existingPending) {
            $existingPending->fill($payload)->save();
            $joinReq = $existingPending;
        } else {
            $joinReq = CompanyJoinRequest::create($payload);
        }

        // Firma yöneticilerine anlık bildirim düşür (çan ikonu + sağ üst popup)
        AppNotification::pushToCompany(
            $company->id,
            __('Yeni İş / Katılım Başvurusu: :name', ['name' => $joinReq->fullName()]),
            __(':pos pozisyonu için başvuru iletildi.', ['pos' => $joinReq->requested_class ?: 'Personel']),
            'application',
            '/kullanicilar',
            '#B9FF66'
        );

        return response()->json([
            'message' => __('Başvurunuz :company yönetimine iletildi. Onaylandığında giriş yapabilirsiniz.', ['company' => $company->name]),
            'request_id' => $joinReq->id,
            'company_name' => $company->name,
        ], 201);
    }

    /**
     * Aktif firmanın başvuru formu şemasını ve başvuru kabul ayarını getir (Admin).
     *
     * @response array{accepting_applications: bool, form_schema: array, default_schema: array}
     */
    public function formSchema(Request $request)
    {
        $company = $request->user()->currentCompany;

        return response()->json([
            'accepting_applications' => (bool) ($company?->accepting_applications ?? true),
            'form_schema' => $company ? $company->resolvedApplicationFormSchema() : Company::defaultApplicationFormSchema(),
            'default_schema' => Company::defaultApplicationFormSchema(),
        ]);
    }

    /**
     * Aktif firmanın başvuru formu şemasını ve başvuru kabul ayarını kaydet (Admin).
     *
     * @response array{message: string, accepting_applications: bool, form_schema: array}
     */
    public function updateFormSchema(Request $request)
    {
        if (! $request->user()->hasRole('Admin')) {
            abort(403, __('Bu işlem için yönetici yetkisi gereklidir.'));
        }

        $company = $request->user()->currentCompany;
        abort_if(! $company, 404, __('Firma bulunamadı.'));

        $data = $request->validate([
            'accepting_applications' => ['sometimes', 'boolean'],
            'form_schema' => ['nullable', 'array', 'max:30'],
            'reset_default' => ['nullable', 'boolean'],
        ]);

        if (array_key_exists('accepting_applications', $data)) {
            $company->accepting_applications = (bool) $data['accepting_applications'];
        }

        if (! empty($data['reset_default'])) {
            $company->application_form_schema = Company::defaultApplicationFormSchema();
        } elseif (array_key_exists('form_schema', $data) && is_array($data['form_schema'])) {
            $company->application_form_schema = array_values($data['form_schema']);
        }

        $company->save();

        return response()->json([
            'message' => __('Başvuru formu şeması ve ayarları kaydedildi.'),
            'accepting_applications' => (bool) $company->accepting_applications,
            'form_schema' => $company->resolvedApplicationFormSchema(),
        ]);
    }

    /**
     * Başvuruya ait CV dosyasını indir / görüntüle (Admin).
     */
    public function downloadCv(Request $request, CompanyJoinRequest $joinRequest)
    {
        $this->authorizeAdminForRequest($request, $joinRequest);

        if (! $joinRequest->cv_path || ! Storage::disk('public')->exists($joinRequest->cv_path)) {
            abort(404, __('CV dosyası bulunamadı.'));
        }

        return Storage::disk('public')->download(
            $joinRequest->cv_path,
            $joinRequest->cv_original_name ?: basename($joinRequest->cv_path)
        );
    }

    /**
     * Aktif firmaya gelen katılım başvurularını listele (Admin)
     *
     * @response array{join_requests: array<int, array{id: int, first_name: string, last_name: string, full_name: string, email: string, phone: string|null, address: string|null, requested_class: string, message: string|null, has_cv: bool, cv_original_name: string|null, custom_answers: array|null, status: string, created_at: string}>}
     */
    public function index(Request $request)
    {
        $company = $request->user()->currentCompany;

        if (! $company || ! $request->user()->hasRole('Admin')) {
            return response()->json(['join_requests' => []]);
        }

        $items = CompanyJoinRequest::query()
            ->where('company_id', $company->id)
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (CompanyJoinRequest $r) => $this->present($r));

        return response()->json(['join_requests' => $items]);
    }

    /**
     * Katılım başvurusunu kabul et ve kullanıcıyı firmaya Personel olarak ekle (Admin)
     *
     * @response array{message: string, join_request: array{id: int, status: string}, member: array{id: int, name: string, email: string, user_class: string}}
     */
    public function approve(Request $request, CompanyJoinRequest $joinRequest)
    {
        $this->authorizeAdminForRequest($request, $joinRequest);

        if ($joinRequest->status !== 'pending') {
            return response()->json(['message' => __('Bu başvuru zaten sonuçlandırılmış.')], 422);
        }

        $data = $request->validate([
            'user_class' => ['nullable', 'string', 'max:60'],
            'role' => ['nullable', 'string', 'in:Admin,Personel'],
        ]);

        $company = $request->user()->currentCompany;
        $assignedClass = $data['user_class'] ?? ($joinRequest->requested_class ?: 'Personel');
        $assignedRole = $data['role'] ?? 'Personel';

        try {
            $user = DB::transaction(function () use ($joinRequest, $company, $assignedClass, $assignedRole, $request) {
                $user = User::query()->where('email', $joinRequest->email)->first();

                if (! $user) {
                    $user = new User([
                        'name' => $joinRequest->fullName(),
                        'email' => $joinRequest->email,
                        'phone' => $joinRequest->phone,
                        'address' => $joinRequest->address,
                        'user_class' => $assignedClass,
                        'job_title' => $assignedClass,
                        'current_company_id' => $company->id,
                    ]);
                    $user->setRawAttributes(array_merge($user->getAttributes(), [
                        'password' => $joinRequest->password_hash,
                    ]));
                    $user->save();
                } else {
                    // HESAP ELE GEÇİRME KORUMASI: e-posta zaten kayıtlıysa, başvuru
                    // sahibi bu hesabın parolasını biliyor olmalı. Aksi halde biri
                    // kurumun çalışanının e-postasıyla başvurup, yönetici onayladığında
                    // o hesaba erişim kazanırdı.
                    $basvuruParolasi = null;
                    if (! empty($joinRequest->password_encrypted)) {
                        try {
                            $basvuruParolasi = Crypt::decryptString($joinRequest->password_encrypted);
                        } catch (\Throwable) {
                            $basvuruParolasi = null;
                        }
                    }

                    if ($basvuruParolasi === null || ! Hash::check($basvuruParolasi, $user->password)) {
                        throw new \RuntimeException('existing_account_password_mismatch');
                    }

                    $user->fill([
                        'phone' => $user->phone ?: $joinRequest->phone,
                        'address' => $user->address ?: $joinRequest->address,
                        'user_class' => $assignedClass,
                        'current_company_id' => $user->current_company_id ?: $company->id,
                    ])->save();
                }

                if (! $company->users()->whereKey($user->id)->exists()) {
                    $company->users()->attach($user->id);
                }

                Role::findOrCreate($assignedRole, 'web');
                app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
                $user->assignRole($assignedRole);

                $joinRequest->forceFill([
                    'status' => 'approved',
                    'requested_class' => $assignedClass,
                    'reviewed_by' => $request->user()->id,
                    'reviewed_at' => now(),
                    // Şifreli parola kopyası artık işe yaramaz; saklamayalım.
                    'password_encrypted' => null,
                ])->save();

                Audit::fromRequest($request, 'company.join_request.approved', $company->id, $joinRequest, [
                    'email' => $joinRequest->email,
                    'user_class' => $assignedClass,
                ]);

                AppNotification::pushToCompany(
                    $company->id,
                    __('Hoş Geldiniz: :name', ['name' => $user->name]),
                    __('Katılım başvurunuz onaylandı ve :class olarak ekibe eklendiniz.', ['class' => $assignedClass]),
                    'success',
                    '/',
                    '#B9FF66',
                    $user->id
                );

                return $user;
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'existing_account_password_mismatch') {
                return response()->json([
                    'message' => __('Bu e-posta adresiyle kayıtlı bir hesap zaten var. Başvurunun onaylanabilmesi için başvuru formunda hesabınıza ait parolayı kullanın.'),
                ], 422);
            }

            throw $e;
        }

        return response()->json([
            'message' => __(':name firmaya personel olarak eklendi.', ['name' => $user->name]),
            'join_request' => $this->present($joinRequest->refresh()),
            'member' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'user_class' => $user->user_class,
            ],
        ]);
    }

    /**
     * Katılım başvurusunu reddet (Admin)
     *
     * @response array{message: string, join_request: array{id: int, status: string}}
     */
    public function reject(Request $request, CompanyJoinRequest $joinRequest)
    {
        $this->authorizeAdminForRequest($request, $joinRequest);

        if ($joinRequest->status !== 'pending') {
            return response()->json(['message' => __('Bu başvuru zaten sonuçlandırılmış.')], 422);
        }

        $joinRequest->forceFill([
            'status' => 'rejected',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ])->save();

        return response()->json([
            'message' => __('Başvuru reddedildi.'),
            'join_request' => $this->present($joinRequest->refresh()),
        ]);
    }

    private function authorizeAdminForRequest(Request $request, CompanyJoinRequest $joinRequest): void
    {
        $company = $request->user()->currentCompany;

        if (! $company || ! $request->user()->hasRole('Admin') || (int) $joinRequest->company_id !== (int) $company->id) {
            abort(403, __('Bu işlem için firma yöneticisi yetkisi gereklidir.'));
        }
    }

    private function present(CompanyJoinRequest $r): array
    {
        return [
            'id' => $r->id,
            'first_name' => $r->first_name,
            'last_name' => $r->last_name,
            'full_name' => $r->fullName(),
            'email' => $r->email,
            'phone' => $r->phone,
            'address' => $r->address,
            'requested_class' => $r->requested_class,
            'message' => $r->message,
            'has_cv' => ! empty($r->cv_path),
            'cv_original_name' => $r->cv_original_name,
            'custom_answers' => $r->custom_answers,
            'status' => $r->status,
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }

    private function normalizeText(string $value): string
    {
        $map = [
            'İ' => 'i', 'I' => 'i', 'ı' => 'i',
            'Ş' => 's', 'ş' => 's',
            'Ğ' => 'g', 'ğ' => 'g',
            'Ü' => 'u', 'ü' => 'u',
            'Ö' => 'o', 'ö' => 'o',
            'Ç' => 'c', 'ç' => 'c',
        ];

        $lower = mb_strtolower(strtr($value, $map), 'UTF-8');
        $clean = preg_replace('/[^a-z0-9\s]+/u', ' ', $lower) ?? '';

        return trim(preg_replace('/\s+/', ' ', $clean) ?? '');
    }

    /**
     * @return list<string>
     */
    private function extractMeaningfulTokens(string $normalized): array
    {
        $stopWords = [
            'as', 'a', 's', 'ltd', 'sti', 'limited', 'sirketi', 'anonim',
            'ticaret', 'tic', 'sanayi', 'san', 've', 'dis', 'ic', 'co', 'inc', 'grup', 'holding',
        ];

        $parts = explode(' ', $normalized);
        $tokens = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || in_array($part, $stopWords, true)) {
                continue;
            }
            $tokens[] = $part;
        }

        return $tokens;
    }
}
