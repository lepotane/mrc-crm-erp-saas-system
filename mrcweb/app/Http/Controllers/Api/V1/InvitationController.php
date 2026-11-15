<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\User;
use App\Support\Audit;
use App\Support\PlanLimits;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

class InvitationController extends Controller
{
    /**
     * Gönderilen ekip davetlerinin listesi (Admin)
     *
     * Aktif firmanın davetleri.
     *
     * @response array{invitations: array}
     */
    public function index(Request $request)
    {
        $invitations = Invitation::where('company_id', $request->user()->current_company_id)
            ->orderBy('created_at', 'desc')
            ->get(['id', 'email', 'role', 'expires_at', 'accepted_at', 'created_at'])
            ->map(function (Invitation $i) {
                return [
                    'id' => $i->id,
                    'email' => $i->email,
                    'role' => $i->role,
                    'status' => $i->status(),
                    'created_at' => $i->created_at->toIso8601String(),
                    'expires_at' => $i->expires_at->toIso8601String(),
                ];
            });

        return response()->json(['invitations' => $invitations]);
    }

    /**
     * Ekip üyesi davet et: e-posta + rol, davet 7 gün geçerli ve tek kullanımlık (Admin)
     *
     * Davet üretir (yalnız Admin). E-posta teslimi Faz 2'de (mail);
     * Faz 1'de token listesi yönetim ekranından ilerler, kabul uçbuarı çalışır.
     */
    #[\Dedoc\Scramble\Attributes\Response(
        status: 201,
        type: 'array{invitation: array{id: int, email: string, role: string, status: string, created_at: string, expires_at: string}}',
    )]
    public function store(Request $request)
    {
        if (! $request->user()->hasRole('Admin')) {
            abort(403, __('Bu işlem için yönetici yetkisi gereklidir.'));
        }

        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'role' => ['required', 'string', 'in:Admin,Personel'],
        ]);

        $companyId = (int) $request->user()->current_company_id;

        // Plan limiti : kiracı + yeti kapilarindan SONRA sorulur; limit mesaji
        // baska firmanin kaydi hakkinda bilgi vermez. Bekleyen davetler de kullanimdadir.
        PlanLimits::for($request->user()->currentCompany)->assertWithinLimit('users', $request->user()->currentCompany);

        $pending = Invitation::where('company_id', $companyId)
            ->whereNull('accepted_at')
            ->where('email', $data['email'])
            ->where('expires_at', '>', now())
            ->exists();
        if ($pending) {
            return response()->json(['message' => __('Bu e-posta için bekleyen bir davet zaten var.')], 422);
        }

        $token = bin2hex(random_bytes(32));

        $invitation = Invitation::create([
            'company_id' => $companyId,
            'invited_by' => $request->user()->id,
            'email' => $data['email'],
            'role' => $data['role'],
            // Token şifrelenir (encrypted cast); arama özeti ayrı kolonda.
            'token' => $token,
            'token_hash' => Invitation::hashToken($token),
            'expires_at' => now()->addDays(7),
        ]);

        Audit::fromRequest($request, 'invitation.created', $companyId, $invitation, [
            'email' => $invitation->email,
            'role' => $invitation->role,
        ]);

        return response()->json([
            'invitation' => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $invitation->role,
                'status' => $invitation->status(),
                'created_at' => $invitation->created_at->toIso8601String(),
                'expires_at' => $invitation->expires_at->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Bekleyen daveti iptal et (Admin)
     *
     * Daveti iptal eder (yalnız Admin).
     *
     * @response array{message: string}
     */
    public function destroy(Request $request, Invitation $invitation)
    {
        // Sıra ÖNEMLİ: önce kiracı, sonra rol. Aksi hâlde başka firmanın davet id'si
        // denendiğinde 403 döner ve bu, "o id başka bir firmada VAR" bilgisini sızdırır
        // (sözleşme: kiralara ait kayıtlar 404'tür; bkz. .
        abort_if(
            $invitation->company_id !== (int) $request->user()->current_company_id,
            404,
            __('Davet bulunamadı.')
        );

        if (! $request->user()->hasRole('Admin')) {
            abort(403, __('Bu işlem için yönetici yetkisi gereklidir.'));
        }

        $invitation->delete();

        Audit::fromRequest($request, 'invitation.cancelled', $invitation->company_id, $invitation, [
            'email' => $invitation->email,
            'role' => $invitation->role,
        ]);

        return response()->json(['message' => __('Davet iptal edildi.')]);
    }

    /**
     * Beni bekleyen davetler: token yalnız davet edilen e-postanın sahibine döner
     *
     * Davetli taraf: giriş yapan kullanıcının e-postasına gönderilmiş, bekleyen davetler.
     * E-posta teslimi bu fazda olmadığı için kabul bağlantısının (token) tek güvenli
     * dağıtım yolu budur: token yalnız e-postası eşleşen kullanıcıya döner.
     * (Kabul akışı: kayıt/giriş → GET invitations/mine → POST invitations/accept.)
     *
     * @response array{invitations: array}
     */
    public function mine(Request $request)
    {
        $invitations = Invitation::with('company')
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->whereRaw('LOWER(email) = ?', [mb_strtolower((string) $request->user()->email)])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Invitation $i) => [
                'id' => $i->id,
                'company_id' => $i->company_id,
                'company_name' => $i->company?->name,
                'email' => $i->email,
                'role' => $i->role,
                'token' => $i->token,
                'created_at' => $i->created_at->toIso8601String(),
                'expires_at' => $i->expires_at->toIso8601String(),
            ]);

        return response()->json(['invitations' => $invitations]);
    }

    /**
     * Daveti kabul et: firma üyeliği kurulur, kabul edilen firma ve current bayrağı döner
     *
     * Daveti kabul eder: davet e-postası giriş yapan kullanıcıyla eşleşmelidir.
     *
     * @response array{message: string, company: array{id: int, name: string, slug: string, currency_code: string}, current: bool}
     */
    public function accept(Request $request)
    {
        $data = $request->validate(['token' => ['required', 'string', 'size:64']]);

        // Token şifreli saklandığı için arama özeti (token_hash) üzerinden yapılır.
        $invitation = Invitation::where('token_hash', Invitation::hashToken($data['token']))->first();
        if (! $invitation) {
            return response()->json(['message' => __('Davet bulunamadı.')], 404);
        }
        if ($invitation->accepted_at) {
            return response()->json(['message' => __('Bu davet zaten kullanıldı.')], 422);
        }
        if ($invitation->isExpired()) {
            return response()->json(['message' => __('Davet süresi geçmiş.')], 422);
        }
        if (strcasecmp($request->user()->email, $invitation->email) !== 0) {
            return response()->json(['message' => __('Bu davet farklı bir e-posta adresine gönderildi.')], 403);
        }

        $user = $request->user();
        $joinedCompanyId = (int) $invitation->company_id;

        DB::transaction(function () use ($user, $invitation) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($invitation->company_id);

            $user->companies()->syncWithoutDetaching([$invitation->company_id]);

            if (! $user->hasRole($invitation->role)) {
                $user->assignRole($invitation->role);
            }

            $invitation->forceFill(['accepted_at' => now()])->save();

            if ($user->current_company_id === null) {
                $user->forceFill(['current_company_id' => $invitation->company_id])->save();
            }
        });

        $user->refresh();

        Audit::fromRequest($request, 'invitation.accepted', $joinedCompanyId, null, [
            'email' => $invitation->email,
            'role' => $invitation->role,
        ]);

        return response()->json([
            'message' => __('Davet kabul edildi.'),
            // Kabul EDİLEN firma döner: kullanıcının o anki bağlamı değişmemiş olabilir
            // (kendi firmasında çalışmaya devam eder) → „current” bayrağı ile belirtilir;
            // arayüz bağlamı POST /companies/select ile değiştirir.
            'company' => array_merge(
                $invitation->company?->only(['id', 'name', 'slug', 'currency_code'])
                    ?? ['id' => $joinedCompanyId],
                ['current' => (int) $user->current_company_id === $joinedCompanyId],
            ),
        ]);
    }
}
