<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\Unit;
use Illuminate\Http\Request;

class UnitController extends Controller
{
    use ResolvesTenant;

    /**
     * Birimleri listele (adet, kg, lt ...)
     *
     * @response array{units: array<int, array{id: int, name: string, code: string, is_default: bool}>}
     */
    public function index(Request $request)
    {
        $units = Unit::where('company_id', $this->tenantId($request))->orderBy('name')->get();

        return response()->json(['units' => $units->map(fn (Unit $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'name_en' => $u->name_en,
            'code' => $u->code,
            'is_default' => $u->is_default,
        ])]);
    }

    /**
     * Yeni birim aç
     */
    #[\Dedoc\Scramble\Attributes\Response(
        status: 201,
        type: 'array{unit: array{id: int, name: string, name_en: string|null, code: string, is_default: bool|null}}',
    )]
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'name_en' => ['nullable', 'string', 'max:60'],
            'code' => ['required', 'string', 'max:16',
                'unique:units,code,NULL,id,company_id,'.$this->tenantId($request)],
        ]);

        $unit = Unit::create($data + ['company_id' => $this->tenantId($request)]);

        return response()->json(['unit' => $unit->only(['id', 'name', 'name_en', 'code', 'is_default'])], 201);
    }

    /**
     * Birimi sil (ürün bağlıysa silinemez)
     *
     * @response array{message: string}
     */
    public function destroy(Request $request, Unit $unit)
    {
        abort_if($unit->company_id !== $this->tenantId($request), 404, __('Birim bulunamadı.'));

        $unit->delete(); // ürünler FK nullOnDelete

        return response()->json(['message' => __('Birim silindi.')]);
    }
}
