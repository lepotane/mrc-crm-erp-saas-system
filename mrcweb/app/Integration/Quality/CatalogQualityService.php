<?php

namespace App\Integration\Quality;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Tsoft -> MRC katalogu için yerel veri kalitesi tarama/onarım servisi.
 *
 * Kurallar (CatalogPullService ile PAYLAŞILIR; çekim sonrası onarım bu sınıfa
 * delege edilir):
 *  - category_id'si boş ürünler firma bazlı "Kategorisiz" kategorisine alınır.
 *  - image_url'si boş ürünler uygulamanın kendi yerel placeholder URL'sini alır.
 *  - Gerçek Tsoft görseli (image_url veya images dizisi dolu) ASLA ezilmez.
 *  - Yinelenen SKU/barkod yalnızca raporlanır; ürün SİLİNMEZ, birleştirilmez.
 *  - Tsoft tarafına hiçbir yazma çağrısı yapılmaz; yalnızca MRC veritabanına yazılır.
 */
final class CatalogQualityService
{
    /** Güvenli kategori: Tsoft'tan gelmeyen, yerel olarak oluşturulan kayıt. */
    public const UNCATEGORIZED_NAME = 'Kategorisiz';

    public const UNCATEGORIZED_CODE = 'UNCATEGORIZED';

    private const PLACEHOLDER_PATH = '/icons/icon-192.png';

    /**
     * Firmanın katalog kalitesini TARAMAZ; hiçbir şey yazmaz.
     *
     * @return array{
     *     no_category:int,
     *     no_image:int,
     *     duplicate_sku:array<string,int>,
     *     duplicate_barcode:array<string,int>,
     *     total:int
     * }
     */
    public function scan(int $companyId): array
    {
        $result = [
            'no_category' => 0,
            'no_image' => 0,
            'duplicate_sku' => [],
            'duplicate_barcode' => [],
            'total' => 0,
        ];

        if ($companyId <= 0) {
            return $result;
        }

        try {
            $query = Product::query()->where('company_id', $companyId);
            $result['total'] = (clone $query)->count();
            $result['no_category'] = (clone $query)->whereNull('category_id')->count();
            $result['no_image'] = (clone $query)
                ->where(static function ($q): void {
                    $q->whereNull('image_url')->orWhere('image_url', '');
                })
                ->count();
            $result['duplicate_sku'] = $this->duplicates($companyId, 'sku');
            $result['duplicate_barcode'] = $this->duplicates($companyId, 'barcode');
        } catch (Throwable) {
            // Genel hata: kısmi/boş sayaçlar döner, katalog bozulmaz.
        }

        return $result;
    }

    /**
     * Yalnızca eksik kategori/görseli onarır; ürün SİLMEZ ve Tsoft'a yazmaz.
     * Dönen sayaçlar kaç satırın düzeltildiğini gösterir.
     *
     * @return array{no_category:int,no_image:int,total:int}
     */
    public function repair(int $companyId): array
    {
        $fixed = ['no_category' => 0, 'no_image' => 0];

        if ($companyId <= 0) {
            $fixed['total'] = 0;

            return $fixed;
        }

        // 1) Kategorisiz ürünler -> "Kategorisiz" (firma bazlı, idempotent).
        try {
            $category = $this->ensureUncategorizedCategory($companyId);
            if ($category !== null) {
                $fixed['no_category'] = Product::query()
                    ->where('company_id', $companyId)
                    ->whereNull('category_id')
                    ->update(['category_id' => (int) $category->id]);
            }
        } catch (Throwable) {
            // Tek hata tüm onarımı durdurmasın; görsel onarımı ayrı denenir.
        }

        // 2) Görseli boş ürünler -> uygulamanın kendi placeholder URL'si.
        try {
            $placeholder = $this->placeholderImageUrl();
            $candidates = Product::query()
                ->where('company_id', $companyId)
                ->where(static function ($q): void {
                    $q->whereNull('image_url')->orWhere('image_url', '');
                })
                ->get(['id', 'images']);

            $ids = [];
            foreach ($candidates as $product) {
                // images dizisi doluysa gerçek Tsoft görseli vardır: kapak URL'si
                // placeholder ile ezilmez (yalnızca image_url boş olsa bile).
                $images = $product->images;
                if (is_array($images) && $images !== []) {
                    continue;
                }
                $ids[] = (int) $product->id;
            }

            if ($ids !== []) {
                $fixed['no_image'] = Product::query()
                    ->where('company_id', $companyId)
                    ->whereIn('id', $ids)
                    ->update(['image_url' => $placeholder]);
            }
        } catch (Throwable) {
            // Genel hata: kalan ürünler korunur.
        }

        $fixed['total'] = $fixed['no_category'] + $fixed['no_image'];

        return $fixed;
    }

    /**
     * Firma bazlı güvenli kategoriyi bulur veya oluşturur.
     * Aynı adda elle açılmış bir kayıt varsa external_code'u boşsa sahiplenir;
     * yabancı bir external_code asla değiştirilmez.
     */
    private function ensureUncategorizedCategory(int $companyId): ?ProductCategory
    {
        $category = ProductCategory::query()
            ->where('company_id', $companyId)
            ->where('external_code', self::UNCATEGORIZED_CODE)
            ->first();

        if ($category === null) {
            $category = ProductCategory::query()
                ->where('company_id', $companyId)
                ->where('name', self::UNCATEGORIZED_NAME)
                ->first();
        }

        if ($category !== null) {
            $changes = [];
            if (($category->external_code === null || trim((string) $category->external_code) === '')
                && $category->external_code !== self::UNCATEGORIZED_CODE) {
                $changes['external_code'] = self::UNCATEGORIZED_CODE;
            }
            if (! $category->is_leaf) {
                $changes['is_leaf'] = true;
            }
            if ($changes !== []) {
                $category->update($changes);
            }

            return $category;
        }

        return ProductCategory::create([
            'company_id' => $companyId,
            'name' => self::UNCATEGORIZED_NAME,
            'external_code' => self::UNCATEGORIZED_CODE,
            'is_leaf' => true,
        ]);
    }

    /** Uygulamanın kendi alan adıyla mutlak placeholder URL'si. */
    private function placeholderImageUrl(): string
    {
        $base = rtrim((string) config('app.url', ''), '/');
        if ($base === '') {
            $base = 'http://localhost';
        }

        return mb_substr($base.self::PLACEHOLDER_PATH, 0, 500);
    }

    /**
     * Tekrar eden değerleri "değer => adet" haritası olarak döndürür.
     * Boş/null değerler yinelenen sayılmaz.
     *
     * @return array<string,int>
     */
    private function duplicates(int $companyId, string $column): array
    {
        $rows = Product::query()
            ->where('company_id', $companyId)
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->select($column.' as value', DB::raw('COUNT(*) as occurrences'))
            ->groupBy($column)
            ->having('occurrences', '>', 1)
            ->orderByDesc('occurrences')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $value = (string) $row->value;
            if ($value !== '') {
                $result[$value] = (int) $row->occurrences;
            }
        }

        return $result;
    }
}
