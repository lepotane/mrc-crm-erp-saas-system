<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Party;
use App\Models\Product;
use Illuminate\Http\Request;

/**
 * Genel arama (hızlı ara): cari + belge + ürün, tek uçta (V2 sonrası — üst bar).
 *
 * Neden ayrı uç: üç ayrı listeyi istemcinin çekmesi 3 istek + 3 sayfa demek;
 * arama kutusu TEK tuşta ilk 5'er sonucu ister. Kiracı süzgeci zorunlu :
 * başka firmanın kaydı ASLA dönmez — isim benzerliği bile sızdırılmaz.
 *
 * @response array{parties: array<int, array{id: int, code: string, name: string, type: string}>, documents: array<int, array{id: int, serial: string, type: string, status: string, grand_total: string, currency_code: string, party_name: string|null}>, products: array<int, array{id: int, name: string, sku: string|null, sale_price: string, currency_code: string}>}
 */
class SearchController extends Controller
{
    use ResolvesTenant;

    /** Hızlı arama: cari + belge + ürün, ilk 5'er sonuç. */
    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:60'],
        ]);

        $companyId = $this->tenantId($request);
        $q = trim($data['q']);
        $like = "%{$q}%";

        $parties = Party::where('company_id', $companyId)
            ->where(function ($w) use ($like) {
                $w->where('name', 'like', $like)
                    ->orWhere('code', 'like', $like)
                    ->orWhere('phone', 'like', $like);
            })
            ->orderBy('name')
            ->limit(5)
            ->get(['id', 'code', 'name', 'type']);

        $documents = Document::with('party:id,name')
            ->where('company_id', $companyId)
            ->where('serial', 'like', $like)
            ->orderByDesc('id')
            ->limit(5)
            ->get(['id', 'serial', 'type', 'status', 'grand_total', 'currency_code', 'party_id']);

        $products = Product::where('company_id', $companyId)
            ->where(function ($w) use ($like) {
                $w->where('name', 'like', $like)
                    ->orWhere('sku', 'like', $like)
                    ->orWhere('barcode', 'like', $like);
            })
            ->orderBy('name')
            ->limit(5)
            ->get(['id', 'name', 'sku', 'sale_price', 'currency_code']);

        return response()->json([
            'parties' => $parties->map(fn (Party $p) => [
                'id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'type' => $p->type,
            ])->values(),
            'documents' => $documents->map(fn (Document $d) => [
                'id' => $d->id,
                'serial' => $d->serial,
                'type' => $d->type,
                'status' => $d->status,
                'grand_total' => $d->grand_total,
                'currency_code' => $d->currency_code,
                'party_name' => $d->party?->name,
            ])->values(),
            'products' => $products->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'sale_price' => $p->sale_price,
                'currency_code' => $p->currency_code,
            ])->values(),
        ]);
    }
}
