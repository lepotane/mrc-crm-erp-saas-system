<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\PresentsPaginator;
use App\Http\Controllers\Api\V1\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\Product;
use App\Models\StockMovement;
use App\Support\PlanLimits;
use App\Support\ReportCache;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    use PresentsPaginator;
    use ResolvesTenant;

    /**
     * Ürünleri listele (ara, kategori süzgeci, sayfalı)
     *
     * @response array{
     *     data: array<int,
     *     array{id: int, company_id: int, category_id: int|null, unit_id: int, name: string, sku: string, barcode: string|null, description: string|null, cost_price: string, sale_price: string, currency_code: string, tax_rate: string, track_inventory: bool, min_stock: string, stock_quantity: string, created_at: string, category: array{id: int, name: string, color: string}|null, unit: array{id: int, name: string, code: string}}>,
     *     links: array<int,
     *     array{url: string|null, label: string, page: int|null, active: bool}>,
     *     meta: array{current_page: int, from: int, last_page: int, per_page: int, to: int, total: float},
     * }
     */
    public function index(Request $request)
    {
        $companyId = $this->tenantId($request);
        $search = trim((string) $request->query('search'));
        $barcode = trim((string) $request->query('barcode'));

        $stockSql = "(SELECT COALESCE(SUM(CASE WHEN sm.type = 'in' THEN sm.quantity WHEN sm.type = 'out' THEN -sm.quantity ELSE sm.quantity END), 0)
                        FROM stock_movements sm WHERE sm.product_id = products.id AND sm.company_id = products.company_id)";

        $query = Product::query()
            ->where('products.company_id', $companyId)
            ->selectRaw("products.*, {$stockSql} AS stock_quantity")
            // Fiyat sınıfları (para birimi bazlı fiyat listesi) — belge satırlarında
            // fiyat sınıfı seçilebildiği için liste ekranında birlikte gelir.
            ->with(['category:id,company_id,name,color', 'unit:id,company_id,name,code', 'prices']);

        if ($barcode !== '') {
            $query->where('barcode', $barcode);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($categoryId = $request->query('category_id')) {
            $query->where('category_id', (int) $categoryId);
        }

        // Kritik stok: stok takibi açık ve anlık stok < min_stock olanlar
        if ($request->boolean('critical')) {
            $query->where('products.track_inventory', true)
                ->whereRaw("({$stockSql}) < products.min_stock");
        }

        $paginator = $query->orderBy('products.name')
            ->paginate(min((int) ($request->query('per_page', 20) ?: 20), 100))
            ->withQueryString();

        $paginator->getCollection()->transform(fn (Product $p) => $this->present($p));

        return response()->json($this->paginateJson($paginator));
    }

    /**
     * Yeni ürün aç (maliyet, satış fiyatı, KDV, stok takibi, min. stok)
     */
    #[Response(
        status: 201,
        type: 'array{message: string|null, product: array{id: int, company_id: int, category_id: int|null, unit_id: int, name: string, sku: string, barcode: string|null, description: string|null, cost_price: string, sale_price: string, currency_code: string, tax_rate: string, track_inventory: bool, min_stock: string, stock_quantity: float, created_at: string, category: string|null, unit: array{id: int, name: string, code: string}}}',
    )]
    #[BodyParameter(name: 'name', description: 'Ürün adı (zorunlu)', required: true, type: 'string')]
    #[BodyParameter(name: 'category_id', description: 'Kategori kimliği', type: 'int')]
    #[BodyParameter(name: 'unit_id', description: 'Birim kimliği', type: 'int')]
    #[BodyParameter(name: 'sku', description: 'Stok kodu (firma içinde tek)', type: 'string')]
    #[BodyParameter(name: 'barcode', description: 'Barkod', type: 'string')]
    #[BodyParameter(name: 'description', description: 'Açıklama', type: 'string')]
    #[BodyParameter(name: 'cost_price', description: 'Alış maliyeti', type: 'float')]
    #[BodyParameter(name: 'sale_price', description: 'Satış fiyatı', type: 'float')]
    #[BodyParameter(name: 'currency_code', description: 'TRY | USD | EUR', type: 'string')]
    #[BodyParameter(name: 'tax_rate', description: 'KDV oranı (0-100)', type: 'float')]
    #[BodyParameter(
        name: 'track_inventory',
        description: 'Stok takibi (false = pasif ürün)',
        type: 'bool',
    )]
    #[BodyParameter(name: 'min_stock', description: 'Kritik stok eşiği', type: 'float')]
    #[BodyParameter(name: 'max_stock', description: 'Tedarik üst sınırı (boşsa min*2)', type: 'float')]
    public function store(Request $request)
    {
        $companyId = $this->tenantId($request);

        // Plan limiti : ürün sayısı artınca katalog/rapor maliyeti de artıyor;
        // limit mesajı çevrilir ve kaç/kaç bilgisiyle gelir (kullanıcı neye çarptığını bilsin).
        PlanLimits::for($this->tenantCompany($request))->assertWithinLimit('products', $this->tenantCompany($request));

        $data = $this->validated($request);
        $data['company_id'] = $companyId;
        $data['currency_code'] = $data['currency_code'] ?? DB::table('companies')->where('id', $companyId)->value('currency_code') ?: 'TRY';

        $product = Product::create($data);

        ReportCache::bump($companyId);

        return response()->json(['product' => $this->present($product->refresh())], 201);
    }

    /**
     * Ürün detayı
     *
     * @response array{product: array{id: int, company_id: int, category_id: int, unit_id: int, name: string, sku: string, barcode: string|null, description: string|null, cost_price: string, sale_price: string, currency_code: string, tax_rate: string, track_inventory: bool, min_stock: string, stock_quantity: float, created_at: string, category: array{id: int, name: string, color: string}, unit: array{id: int, name: string, code: string}}}
     */
    public function show(Request $request, Product $product)
    {
        $this->authorizeProduct($request, $product);
        $product->load(['category', 'unit']);

        return response()->json(['product' => $this->present($product)]);
    }

    /**
     * Ürün bilgilerini güncelle
     *
     * @response array{product: array{id: int, company_id: int, category_id: int|null, unit_id: int, name: string, sku: string, barcode: string|null, description: string|null, cost_price: string, sale_price: string, currency_code: string, tax_rate: string, track_inventory: bool, min_stock: string, stock_quantity: float, created_at: string, category: string|null, unit: array{id: int, name: string, code: string}}}
     */
    #[BodyParameter(name: 'name', description: 'Ürün adı (zorunlu)', type: 'string')]
    #[BodyParameter(name: 'category_id', description: 'Kategori kimliği', type: 'int')]
    #[BodyParameter(name: 'unit_id', description: 'Birim kimliği', type: 'int')]
    #[BodyParameter(name: 'sku', description: 'Stok kodu (firma içinde tek)', type: 'string')]
    #[BodyParameter(name: 'barcode', description: 'Barkod', type: 'string')]
    #[BodyParameter(name: 'description', description: 'Açıklama', type: 'string')]
    #[BodyParameter(name: 'cost_price', description: 'Alış maliyeti', type: 'float')]
    #[BodyParameter(name: 'sale_price', description: 'Satış fiyatı', type: 'float')]
    #[BodyParameter(name: 'currency_code', description: 'TRY | USD | EUR', type: 'string')]
    #[BodyParameter(name: 'tax_rate', description: 'KDV oranı (0-100)', type: 'float')]
    #[BodyParameter(
        name: 'track_inventory',
        description: 'Stok takibi (false = pasif ürün)',
        type: 'bool',
    )]
    #[BodyParameter(name: 'min_stock', description: 'Kritik stok eşiği', type: 'float')]
    #[BodyParameter(name: 'max_stock', description: 'Tedarik üst sınırı (boşsa min*2)', type: 'float')]
    public function update(Request $request, Product $product)
    {
        $this->authorizeProduct($request, $product);

        $product->update($this->validated($request, true));

        ReportCache::bump($product->company_id);

        return response()->json(['product' => $this->present($product->refresh())]);
    }

    /**
     * Ürünü sil (stok hareketi varsa silinemez; pasife almak için stok takibini kapat)
     *
     * @response array{message: string}
     */
    public function destroy(Request $request, Product $product)
    {
        $this->authorizeProduct($request, $product);

        // Hareket gören ürün silinemez (depo kuralıyla tutarlı; cascade ile geçmiş kaybını önler)
        if ($product->movements()->exists()) {
            return response()->json([
                'message' => __('Bu ürüne ait stok hareketi mevcut; silinemez.'),
            ], 422);
        }

        $product->delete();

        ReportCache::bump($product->company_id);

        return response()->json(['message' => __('Ürün silindi.')]);
    }

    protected function authorizeProduct(Request $request, Product $product): void
    {
        abort_if(
            $product->company_id !== $this->tenantId($request),
            404,
            __('Ürün bulunamadı.')
        );
    }

    protected function validated(Request $request, bool $partial = false): array
    {
        $companyId = $this->tenantId($request);

        $rules = [
            'name' => ['required', 'string', 'max:190'],
            'name_en' => ['nullable', 'string', 'max:190'],
            'category_id' => ['nullable', 'integer',
                Rule::exists('product_categories', 'id')->where('company_id', $companyId)],
            'unit_id' => ['nullable', 'integer',
                Rule::exists('units', 'id')->where('company_id', $companyId)],
            'sku' => ['nullable', 'string', 'max:64'],
            'barcode' => ['nullable', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:2000'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'sale_price' => ['nullable', 'numeric', 'min:0'],
            'currency_code' => ['nullable', 'string', Rule::in(Currency::activeCodes())],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'track_inventory' => ['nullable', 'boolean'],
            'min_stock' => ['nullable', 'numeric', 'min:0'],
            'max_stock' => ['nullable', 'numeric', 'min:0'],
        ];

        $data = $request->validate($partial
            ? array_map(fn ($rule) => array_diff($rule, ['required']), $rules)
            : $rules);

        if (! $partial) {
            $data += [
                'cost_price' => 0, 'sale_price' => 0,
                // KDV oranı firmanın varsayılan KDV ayarından gelir ;
                // ürün kartında sonradan değiştirilebilir.
                'tax_rate' => $this->defaultTaxRate($request),
                'track_inventory' => true, 'min_stock' => 0,
            ];
        }

        return $data;
    }

    protected function present(Product $product): array
    {
        $data = $product->only([
            'id', 'company_id', 'category_id', 'unit_id', 'name', 'name_en', 'sku', 'barcode',
            'description', 'cost_price', 'sale_price', 'currency_code', 'tax_rate',
            'track_inventory', 'min_stock', 'max_stock', 'stock_quantity', 'created_at',
        ]);

        $data['category'] = $product->category
            ? $product->category->only(['id', 'name', 'color'])
            : null;
        $data['unit'] = $product->unit
            ? $product->unit->only(['id', 'name', 'name_en', 'code'])
            : null;

        // stok yoksa (tekil show gibi) movement summasyonundan hesapla
        if (! isset($data['stock_quantity'])) {
            $data['stock_quantity'] = $product->track_inventory
                ? StockMovement::stockFor($product->company_id, $product->id)
                : null;
        }

        // Fiyat sınıfları (para birimi bazlı fiyat listesi).
        $product->loadMissing('prices');
        $data['prices'] = $product->prices
            ->sortBy('currency_code')
            ->values()
            ->map(fn ($p) => [
                'currency_code' => $p->currency_code,
                'sale_price' => (string) $p->sale_price,
                'cost_price' => $p->cost_price === null ? null : (string) $p->cost_price,
            ])->all();

        return $data;
    }
}
