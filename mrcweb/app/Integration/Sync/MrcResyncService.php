<?php

namespace App\Integration\Sync;

use App\Integration\Canonical\CanonicalOrder;
use App\Integration\Orders\OrderSyncService;
use App\Integration\Tsoft\TsoftAdapter;
use App\Integration\Tsoft\TsoftMapper;
use App\Models\Party;
use App\Models\Product;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * MRC iş verisini Tsoft'tan (yalnızca OKUYARAK) yeniden kurar.
 *
 * Güvenlik sözleşmesi:
 *  - Varsayılan davranış dry-run'dır; doğrulama olmadan hiçbir satır silinmez,
 *    hiçbir kayıt yazılmaz ve dış sistem çağrılmaz.
 *  - Silme yalnızca açıkça izin verilmiş iş tablolarında, kiracı (company_id)
 *    filtresiyle ve FK-safe sırada yapılır. Kullanıcı/rolling/yetki/ayar tabloları
 *    ASLA dokunulmaz.
 *  - Tsoft adapter'ında yalnızca okuma yöntemleri çağrılır; yazma yöntemleri
 *    bu sınıftan hiçbir yerde kullanılmaz.
 *  - Silme + içe aktarma aynı veritabanı transaction'ındadır; dış okumalar
 *    transaction ÖNCESİ yapılır ki kilitler uzun süre tutulmasın.
 */
final class MrcResyncService
{
    /** Silinecek tabloların FK-safe sırası (önce çocuklar). */
    private const DELETE_ORDER = [
        'document_items',
        'payments',
        'stock_movements',
        'product_prices',
        'integration_id_map',
        'integration_stock_state',
        'sync_diffs',
        'documents',
        'products',
        'contacts',
        'parties',
    ];

    /** Tek seferde alınacak azami sipariş sayısı. */
    private const MAX_ORDERS = 10000;

    public function __construct(
        private readonly mixed $adapterFactory = null,
        private readonly mixed $priceSync = null,
        private readonly mixed $stockEngine = null,
        private readonly mixed $orderSync = null,
        private readonly mixed $backupRunner = null,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function run(int $companyId, bool $confirm, bool $dryRun, bool $skipBackup): array
    {
        $started = microtime(true);
        $effectiveDryRun = $dryRun || ! $confirm;

        $report = [
            'company_id' => $companyId,
            'dry_run' => $effectiveDryRun,
            'dry_run_requested' => $dryRun,
            'confirmed' => $confirm,
            'executed' => false,
            'status' => 'success',
            'backup' => [
                'attempted' => false,
                'skipped' => $skipBackup || $dryRun,
                'ok' => false,
                'file' => null,
                'message' => null,
            ],
            'deleted' => [],
            'imported' => [
                'products' => 0,
                'prices' => 0,
                'stock' => 0,
                'customers' => 0,
                'order_buyers' => 0,
                'orders' => 0,
                'parties' => 0,
            ],
            'errors' => [],
            'plan' => [],
            'pre_counts' => [],
            'elapsed_ms' => 0,
        ];

        try {
            $report['pre_counts'] = $this->preCounts($companyId);

            if ($skipBackup) {
                $report['backup']['message'] = 'Yedek atlandi (--skip-backup); yalnizca yerel gelistirme icin.';
                Log::warning('mrc.resync.backup_skipped', ['company_id' => $companyId]);
            } elseif (! $dryRun) {
                $report['backup'] = $this->backup();

                if (! $report['backup']['ok']) {
                    $report['errors'][] = 'Yedek alinamadi; silme ve ice aktarma iptal edildi.';
                    $report['status'] = 'failed';

                    return $this->finish($report, $companyId, $started);
                }
            }

            $report['plan'] = $this->buildPlan($report['pre_counts']);

            if ($effectiveDryRun) {
                $report['plan']['reason'] = $confirm
                    ? 'dry_run: dis okuma ve yerel yazim yapilmadi.'
                    : 'confirm eksik: --confirm olmadan hici bir sey silinmez veya yazilmaz.';

                return $this->finish($report, $companyId, $started);
            }

            // Dis okumalar transaction DISINDA: yavas ag cagrisi DB kilidini tutmasin.
            // Okuma tamamen basarisizsa silmeye baslamayiz (veri kaybi riski).
            try {
                $external = $this->readExternal($companyId, $report);
            } catch (Throwable) {
                $report['errors'][] = 'Tsoft okuma basarisiz; silme yapilmadi. Yedekten geri donulebilir.';
                $report['status'] = 'failed';

                return $this->finish($report, $companyId, $started);
            }

            $warehouseId = $this->defaultWarehouseId($companyId);

            DB::transaction(function () use (
                $companyId, $external, $warehouseId, &$report
            ): void {
                $report['deleted'] = $this->deleteBusinessData($companyId, $report);
                $report['imported']['products'] = $this->importProducts(
                    $companyId, $external['products'], $report
                );
                $report['imported']['prices'] = $this->importPrices(
                    $companyId, $external['raw_products'], $report
                );
                $report['imported']['stock'] = $this->importStock(
                    $companyId, $external['raw_products'], $warehouseId, $report
                );
                // Gerçek müşteriler siparişlerden önce kurulur ki ingest cariyi bulsun.
                $report['imported']['customers'] = $this->importCustomers(
                    $companyId, $external['customers'] ?? [], $report
                );
                // rest1 listesinde olmayan misafir/non-member alıcılar siparişlerden kurulur.
                $report['imported']['order_buyers'] = $this->importOrderBuyers(
                    $companyId, $external['buyers'] ?? [], $report
                );
                // Yalnızca eşlenmemiş siparişler için placeholder cari açılır.
                $report['imported']['parties'] = $this->importParties(
                    $companyId, $external['orders'], $report
                );
                $report['imported']['orders'] = $this->importOrders(
                    $companyId, $external['orders'], $report
                );

                // Herhangi bir aktarım hatası TÜM silme+içe aktarımı geri alır.
                // Kısmi veri bırakmak üretimde kabul edilemez.
                if ($report['errors'] !== []) {
                    throw new RuntimeException('İçe aktarma hatası; değişiklikler geri alındı.');
                }
            });

            $report['executed'] = true;
        } catch (Throwable) {
            $report['errors'][] = 'Islem basarisiz; degisiklikler geri alindi. '
                .'Geri donus icin yedek dosyasini kullanin.';
            $report['status'] = 'failed';
        }

        if ($report['errors'] !== []) {
            $report['status'] = 'failed';
        }

        return $this->finish($report, $companyId, $started);
    }

    /** @return array<string,int> */
    private function preCounts(int $companyId): array
    {
        $counts = [];

        foreach (self::DELETE_ORDER as $table) {
            if (! Schema::hasTable($table)) {
                $counts[$table] = 0;

                continue;
            }

            if (! Schema::hasColumn($table, 'company_id')) {
                $counts[$table] = 0;

                continue;
            }

            $counts[$table] = DB::table($table)
                ->where('company_id', $companyId)
                ->count();
        }

        return $counts;
    }

    /** @param array<string,int> $counts @return array<string,mixed> */
    private function buildPlan(array $counts): array
    {
        $delete = [];
        foreach (self::DELETE_ORDER as $table) {
            $delete[$table] = $counts[$table] ?? 0;
        }

        return [
            'delete' => $delete,
            'import' => [
                'products' => 'Tsoft urun okumasindan products (sku, barkod, ad, para birimi, satis fiyati, vergi orani)',
                'prices' => 'Ham urun fiyat dizilerinden product_prices (PriceSyncService::pull)',
                'stock' => 'Urun stok miktarindan acilis girisi (ParallelStockEngine::applyInbound)',
                'customers' => 'Tsoft musteri listesinden gercek cariler (CustomerId/CustomerCode esleme)',
                'orders' => 'Son bir yillik siparislerden taslak satis siparisleri (OrderSyncService::ingest)',
                'parties' => 'Esleme bulunmayan siparislerden placeholder cariler (yalnizca vergi numarasi)',
            ],
            'preserved' => [
                'users', 'companies', 'roles', 'permissions', 'settings',
                'print_templates', 'modules', 'audit_events', 'notifications',
                'tenant_integrations', 'migrations',
            ],
        ];
    }

    /**
     * Yedek: db:dump-php komutunu calistirir ve YENI bir döküm dosyasi arar.
     * Komut basari döndürse bile dosya uretilmediyse islem iptal edilir.
     *
     * @return array<string,mixed>
     */
    private function backup(): array
    {
        $result = [
            'attempted' => true,
            'skipped' => false,
            'ok' => false,
            'file' => null,
            'exit_code' => null,
            'message' => null,
        ];

        $dir = (string) config('backup.mrc.db_dump.dir', storage_path('app/db-dumps'));
        $before = $this->dumpSnapshot($dir);
        $startedAt = time() - 2;

        try {
            $runner = $this->backupRunner;
            // --keep=0: yedek alirken eski dökümleri budamayiz.
            $exit = is_callable($runner)
                ? $runner(['--keep' => '0'])
                : Artisan::call('db:dump-php', ['--keep' => '0']);
            $result['exit_code'] = is_numeric($exit) ? (int) $exit : null;
        } catch (Throwable) {
            $result['message'] = 'db:dump-php komutu calistirilamadi.';

            return $result;
        }

        if ($result['exit_code'] !== 0) {
            $result['message'] = 'db:dump-php basarisiz bir cikis kodu döndürdü.';

            return $result;
        }

        $newFile = null;
        foreach ($this->dumpSnapshot($dir) as $name => $mtime) {
            if (($before[$name] ?? 0) < $mtime && $mtime >= $startedAt) {
                $newFile = $dir.DIRECTORY_SEPARATOR.$name;

                break;
            }
        }

        if ($newFile === null || ! is_file($newFile)) {
            $result['message'] = 'db:dump-php yeni bir döküm dosyasi uretmedi.';

            return $result;
        }

        $result['ok'] = true;
        $result['file'] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $newFile);

        return $result;
    }

    /** @return array<string,int> */
    private function dumpSnapshot(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $out = [];
        foreach (glob(rtrim($dir, '/\\').DIRECTORY_SEPARATOR.'*.sql.gz') ?: [] as $file) {
            $out[basename($file)] = (int) filemtime($file);
        }

        return $out;
    }

    /**
     * Dis veriyi salt-okunur yontemlerle toplar. Bu metot HICBIR yazi yapmaz.
     *
     * @param  array<string,mixed>  $report
     * @return array<string,array<int,mixed>>
     */
    private function readExternal(int $companyId, array &$report): array
    {
        $adapter = $this->adapter($companyId);

        $method = null;
        if (method_exists($adapter, 'fetchAllProducts')) {
            $method = 'fetchAllProducts';
        } elseif (method_exists($adapter, 'fetchProducts')) {
            $method = 'fetchProducts';
        }

        if ($method === null) {
            throw new RuntimeException('Tsoft urun okuma yontemi desteklenmiyor.');
        }

        try {
            $raw = $method === 'fetchAllProducts'
                ? $adapter->fetchAllProducts()
                : $adapter->fetchProducts([]);
        } catch (Throwable) {
            throw new RuntimeException('Tsoft urunleri okunamadi.');
        }

        if (! is_array($raw)) {
            throw new RuntimeException('Tsoft urun yaniti beklenen bicimde degil.');
        }

        $rawProducts = [];
        $skipped = 0;
        foreach (array_values($raw) as $row) {
            if (is_array($row)) {
                $rawProducts[] = $row;

                continue;
            }

            $skipped++;
        }

        if ($skipped > 0) {
            $report['errors'][] = $skipped.' urun satiri beklenen bicimde olmadigi icin atlandi.';
        }

        return [
            'raw_products' => $rawProducts,
            'products' => $rawProducts,
            'customers' => $this->readCustomers($adapter, $report),
            'orders' => $this->readOrders($adapter, $report),
            // Sipariş okuması aynı sürücü örneğinde alıcıları da toplar; ek HTTP yok.
            'buyers' => $adapter instanceof TsoftAdapter
                ? $this->readBuyers($adapter, $report)
                : [],
        ];
    }

    /**
     * Müşteri listesi ham satırlar olarak okunur (kişisel veri yalnızca MRC'de).
     * Eski sürücülerde yöntem yoksa boş dizi döner; resync diğer verilerle sürer.
     *
     * @param  array<string,mixed>  $report
     * @return array<int,array<string,mixed>>
     */
    private function readCustomers(object $adapter, array &$report): array
    {
        // Eski adapterlar yöntemi sunmuyorsa sessizce boş dizi; cari fallback'i
        // (importParties) geriye dönük uyumluluk için çalışmaya devam eder.
        if (! method_exists($adapter, 'fetchAllCustomers')) {
            return [];
        }

        try {
            $rows = $adapter->fetchAllCustomers();
        } catch (Throwable) {
            $report['errors'][] = 'Tsoft musterileri okunamadi.';

            return [];
        }

        if (! is_array($rows)) {
            $report['errors'][] = 'Tsoft musteri yaniti beklenen bicimde degil.';

            return [];
        }

        $customers = [];
        foreach (array_values($rows) as $row) {
            if (is_array($row)) {
                $customers[] = $row;
            }
        }

        return $customers;
    }

    /** @param array<string,mixed> $report @return array<int,CanonicalOrder> */
    private function readOrders(object $adapter, array &$report): array
    {
        if (! method_exists($adapter, 'fetchOrders')) {
            $report['errors'][] = 'Tsoft siparis okuma yontemi desteklenmiyor.';

            return [];
        }

        try {
            $rows = method_exists($adapter, 'fetchAllOrders')
                ? $adapter->fetchAllOrders(self::MAX_ORDERS)
                : $adapter->fetchOrders(null, self::MAX_ORDERS);
        } catch (Throwable) {
            $report['errors'][] = 'Tsoft siparisleri okunamadi.';

            return [];
        }

        if (! is_array($rows)) {
            $report['errors'][] = 'Tsoft siparis yaniti beklenen bicimde degil.';

            return [];
        }

        $orders = [];
        foreach (array_values($rows) as $index => $row) {
            try {
                if ($row instanceof CanonicalOrder) {
                    $orders[] = $row;

                    continue;
                }

                if (is_array($row)) {
                    $orders[] = TsoftMapper::order($row);
                }
            } catch (Throwable) {
                $report['errors'][] = 'orders['.$index.']: siparis donusturulemedi.';
            }
        }

        return $orders;
    }

    /**
     * Sipariş okumasından elde edilen alıcı satırlarını alır (kişisel veri).
     * Yalnızca MRC tarafında cari oluşturmak için kullanılır; hata mesajları
     * geneldir, satır içeriği asla günlüğe veya rapora kopyalanmaz.
     *
     * @param  array<string,mixed>  $report
     * @return array<int,array<string,mixed>>
     */
    private function readBuyers(object $adapter, array &$report): array
    {
        if (! method_exists($adapter, 'fetchAllOrderBuyers')) {
            return [];
        }

        try {
            $rows = $adapter->fetchAllOrderBuyers(self::MAX_ORDERS);
        } catch (Throwable) {
            $report['errors'][] = 'Tsoft siparis alicilari okunamadi.';

            return [];
        }

        if (! is_array($rows)) {
            return [];
        }

        $buyers = [];
        foreach (array_values($rows) as $row) {
            if (is_array($row)) {
                $buyers[] = $row;
            }
        }

        return $buyers;
    }

    private function adapter(int $companyId): object
    {
        $factory = $this->adapterFactory;
        $adapter = is_callable($factory)
            ? $factory($companyId)
            : app(TsoftAdapter::class, ['company' => $companyId]);

        if (! is_object($adapter)) {
            throw new RuntimeException('Tsoft adapteri olusturulamadi.');
        }

        return $adapter;
    }

    /**
     * Yalnizca izinli is tablolarini, kirac filtresiyle ve FK-safe sırada siler.
     *
     * @param  array<string,mixed>  $report
     * @return array<string,int>
     */
    private function deleteBusinessData(int $companyId, array &$report): array
    {
        $deleted = [];

        // MRC arayüzünde üretilen belgeler Tsoft yeniden kurulumunda KORUNUR.
        // Eski veritabanlarında `origin` kolonu yoksa hiçbir belge korunmaz
        // (mevcut davranış sürer); yeni şemada varsayılan 'tsoft'tur.
        $mrcIds = [];
        if (Schema::hasTable('documents') && Schema::hasColumn('documents', 'origin')) {
            $mrcIds = array_values(array_map(
                'intval',
                DB::table('documents')
                    ->where('company_id', $companyId)
                    ->where('origin', 'mrc')
                    ->pluck('id')
                    ->all(),
            ));
        }
        $report['preserved_mrc_documents'] = count($mrcIds);

        // Korunan belgelerin stok izleri: ihraç/iptal/iade hareketleri.
        $mrcReferences = [];
        foreach ($mrcIds as $id) {
            $mrcReferences[] = 'issue:'.$id;
            $mrcReferences[] = 'cancel:'.$id;
            $mrcReferences[] = 'return:'.$id;
        }

        // FK cascade: korunan belgelerin ürün/cari kayıtları silinirse kalemler
        // ve stok hareketleri yine kaybolur. Onları da silme kapsamından çıkar.
        $mrcProductIds = [];
        $mrcPartyIds = [];
        if ($mrcIds !== []) {
            $mrcProductIds = array_values(array_unique(array_map('intval', array_merge(
                Schema::hasColumn('document_items', 'product_id')
                    ? DB::table('document_items')->whereIn('document_id', $mrcIds)->pluck('product_id')->all()
                    : [],
                $mrcReferences !== [] && Schema::hasColumn('stock_movements', 'product_id')
                    ? DB::table('stock_movements')->whereIn('reference', $mrcReferences)->pluck('product_id')->all()
                    : [],
            ))));
            $mrcPartyIds = Schema::hasColumn('documents', 'party_id')
                ? array_values(array_map('intval', DB::table('documents')->whereIn('id', $mrcIds)->pluck('party_id')->all()))
                : [];
        }

        foreach (self::DELETE_ORDER as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'company_id')) {
                $deleted[$table] = 0;

                continue;
            }

            $query = DB::table($table)->where('company_id', $companyId);

            if ($mrcIds !== []) {
                if ($table === 'document_items' || $table === 'payments') {
                    $query->whereNotIn('document_id', $mrcIds);
                } elseif ($table === 'documents') {
                    $query->whereNotIn('id', $mrcIds);
                } elseif ($table === 'stock_movements' && $mrcReferences !== []
                    && Schema::hasColumn($table, 'reference')) {
                    $query->whereNotIn('reference', $mrcReferences);
                } elseif ($table === 'integration_id_map') {
                    // Yalnızca korunan belgelere ait eşleme kayıtları bırakılır.
                    $query->where(function ($q) use ($mrcIds) {
                        $q->where('mrc_type', '!=', 'document')
                            ->orWhereNotIn('mrc_id', $mrcIds);
                    });
                } elseif ($table === 'products' && $mrcProductIds !== []) {
                    $query->whereNotIn('id', $mrcProductIds);
                } elseif ($table === 'parties' && $mrcPartyIds !== []) {
                    $query->whereNotIn('id', $mrcPartyIds);
                }
            }

            $deleted[$table] = $query->delete();
        }

        return $deleted;
    }

    /**
     * @param  array<int,array<string,mixed>>  $rawProducts
     * @param  array<string,mixed>  $report
     */
    private function importProducts(int $companyId, array $rawProducts, array &$report): int
    {
        $currency = strtoupper((string) config('integrations.channels.tsoft.currency', 'TRY'));
        $imported = 0;

        foreach (array_values($rawProducts) as $index => $raw) {
            try {
                $canonical = TsoftMapper::product($raw);

                if ($canonical->sku === '') {
                    $report['errors'][] = 'products['.$index.']: SKU bos oldugu icin atlandi.';

                    continue;
                }

                $sale = $this->pick($raw, [
                    'sale_price', 'salePrice', 'SellingPrice', 'price', 'Price', 'attributes.salePrice',
                ], '0');
                $tax = $this->pick($raw, ['tax_rate', 'taxRate', 'Vat', 'vat', 'attributes.taxRate'], '0');
                $rowCurrency = $this->pick($raw, [
                    'currency', 'currency_code', 'currencyCode', 'Currency', 'attributes.currency',
                ], $currency);

                Product::updateOrCreate(
                    ['company_id' => $companyId, 'sku' => $canonical->sku],
                    [
                        'name' => mb_substr($canonical->name !== '' ? $canonical->name : $canonical->sku, 0, 190),
                        'barcode' => $canonical->barcode === null
                            ? null
                            : mb_substr($canonical->barcode, 0, 64),
                        'currency_code' => strtoupper(mb_substr((string) $rowCurrency, 0, 3)),
                        'sale_price' => $this->decimal($sale),
                        'tax_rate' => $this->decimal($tax, 2),
                        'track_inventory' => true,
                    ],
                );

                $imported++;
            } catch (Throwable) {
                $report['errors'][] = 'products['.$index.']: urun yazilamadi.';
            }
        }

        return $imported;
    }

    /**
     * @param  array<int,array<string,mixed>>  $rawProducts
     * @param  array<string,mixed>  $report
     */
    private function importPrices(int $companyId, array $rawProducts, array &$report): int
    {
        if ($rawProducts === []) {
            return 0;
        }

        try {
            $service = $this->priceSync ?? new PriceSyncService;
            if (! is_object($service) || ! method_exists($service, 'pull')) {
                $report['errors'][] = 'PriceSyncService::pull kullanilamiyor.';

                return 0;
            }

            $result = $service->pull($companyId, $rawProducts);

            return is_array($result) ? (int) ($result['upserted'] ?? 0) : 0;
        } catch (Throwable) {
            $report['errors'][] = 'Fiyatlar ice aktarilamadi.';

            return 0;
        }
    }

    /**
     * Dis stok miktari ACILIS GIRISI olarak yazilir; islem bazli hareket degildir.
     * negatively bir deger giris yapilmaz (sifir kabul edilir).
     *
     * @param  array<int,array<string,mixed>>  $rawProducts
     * @param  array<string,mixed>  $report
     */
    private function importStock(
        int $companyId,
        array $rawProducts,
        ?int $warehouseId,
        array &$report,
    ): int {
        try {
            $engine = $this->stockEngine ?? new ParallelStockEngine;
            if (! is_object($engine) || ! method_exists($engine, 'applyInbound')) {
                $report['errors'][] = 'ParallelStockEngine::applyInbound kullanilamiyor.';

                return 0;
            }
        } catch (Throwable) {
            $report['errors'][] = 'Stok motoru olusturulamadi.';

            return 0;
        }

        $imported = 0;
        foreach (array_values($rawProducts) as $index => $raw) {
            try {
                $sku = (string) $this->pick($raw, [
                    'sku', 'ProductCode', 'product_code', 'catalogCode', 'attributes.sku',
                ], '');

                if ($sku === '') {
                    continue;
                }

                $quantity = $this->decimal($this->pick($raw, [
                    'stock', 'Stock', 'quantity', 'available', 'availableStock', 'attributes.quantity',
                ], '0'));

                if (bccomp($quantity, '0', 4) <= 0) {
                    continue;
                }

                $engine->applyInbound(
                    $companyId,
                    $sku,
                    $quantity,
                    'tsoft',
                    'resync:'.$sku,
                    $warehouseId,
                );

                $imported++;
            } catch (Throwable) {
                $report['errors'][] = 'stock['.$index.']: acilis girisi yazilamadi.';
            }
        }

        return $imported;
    }

    /** @param array<int,CanonicalOrder> $orders @param array<string,mixed> $report */
    private function importOrders(int $companyId, array $orders, array &$report): int
    {
        try {
            $service = $this->orderSync ?? new OrderSyncService;
            if (! is_object($service) || ! method_exists($service, 'ingest')) {
                $report['errors'][] = 'OrderSyncService::ingest kullanilamiyor.';

                return 0;
            }
        } catch (Throwable) {
            $report['errors'][] = 'Siparis servisi olusturulamadi.';

            return 0;
        }

        $imported = 0;
        foreach (array_values($orders) as $index => $order) {
            try {
                // ingest taslak satis siparisi acar; faturalandirma daha sonra yapilir.
                $service->ingest($companyId, $order);
                $imported++;
            } catch (Throwable) {
                $report['errors'][] = 'orders['.$index.']: siparis ice aktarilamadi.';
            }
        }

        return $imported;
    }

    /**
     * Tsoft customer/getCustomers satırlarından GERÇEK carileri kurar.
     *
     * Kişisel veri yalnızca MRC veritabanına yazılır; ham satır hata mesajına
     * veya günlüğe kopyalanmaz. Tsoft tarafında hiçbir yazma yapılmaz.
     *
     * @param  array<int,array<string,mixed>>  $customers
     * @param  array<string,mixed>  $report
     */
    private function importCustomers(int $companyId, array $customers, array &$report): int
    {
        $currency = strtoupper((string) config('integrations.channels.tsoft.currency', 'TRY'));
        $imported = 0;
        $seen = [];
        $usedCodes = [];

        foreach (array_values($customers) as $index => $raw) {
            if (! is_array($raw) || $raw === []) {
                continue;
            }

            try {
                $filled = false;
                foreach ([
                    'CustomerId', 'CustomerCode', 'CompanyName', 'Name', 'Surname',
                    'TaxNo', 'IdentityNo', 'Email', 'Phone', 'Mobile',
                ] as $field) {
                    if (trim((string) ($raw[$field] ?? '')) !== '') {
                        $filled = true;

                        break;
                    }
                }
                if (! $filled) {
                    continue;
                }

                $customerId = trim((string) ($raw['CustomerId'] ?? ''));
                $customerCode = trim((string) ($raw['CustomerCode'] ?? ''));
                $externalKey = $customerId !== '' ? $customerId : $customerCode;

                if ($externalKey === '') {
                    $report['errors'][] = 'customers['.$index.']: kimlik anahtari bos oldugu icin atlandi.';

                    continue;
                }
                if (isset($seen[$externalKey])) {
                    continue;
                }
                $seen[$externalKey] = true;

                $code = $customerCode !== '' && mb_strlen($customerCode) <= 20
                    ? $customerCode
                    : Party::nextCode($companyId);
                // nextCode() numerik olmayan kodlarda ayni degeri uretebilir;
                // bu tur satirlar birbirinin uzerine yazilmasin.
                if (isset($usedCodes[$code])) {
                    $code = 'T'.mb_substr(md5($externalKey), 0, 11);
                }
                $usedCodes[$code] = true;

                $companyName = trim((string) ($raw['CompanyName'] ?? ''));
                $personName = trim(
                    trim((string) ($raw['Name'] ?? '')).' '.trim((string) ($raw['Surname'] ?? ''))
                );
                if ($companyName !== '') {
                    $name = $companyName;
                } elseif ($personName !== '') {
                    $name = $personName;
                } else {
                    $name = 'Tsoft Müşteri';
                    Log::warning('mrc.resync.customer_unnamed', [
                        'company_id' => $companyId,
                        'row' => $index,
                    ]);
                }

                $taxNumber = trim((string) ($raw['TaxNo'] ?? ''));
                if ($taxNumber === '') {
                    $taxNumber = trim((string) ($raw['IdentityNo'] ?? ''));
                }
                $taxNumber = mb_substr($taxNumber, 0, 20);

                $phone = trim((string) ($raw['Mobile'] ?? ''));
                if ($phone === '') {
                    $phone = trim((string) ($raw['Phone'] ?? ''));
                }

                $addressParts = [];
                foreach (['Address', 'District', 'Town', 'City'] as $field) {
                    $value = trim((string) ($raw[$field] ?? ''));
                    if ($value !== '') {
                        $addressParts[] = $value;
                    }
                }

                $email = trim((string) ($raw['Email'] ?? ''));
                $city = trim((string) ($raw['City'] ?? ''));
                $address = mb_substr(implode(', ', $addressParts), 0, 500);
                $label = $customerId !== '' ? 'CustomerId' : 'CustomerCode';

                $attributes = [
                    'type' => 'customer',
                    'name' => mb_substr($name, 0, 190),
                    'tax_number' => $taxNumber !== '' ? $taxNumber : null,
                    'phone' => $phone !== '' ? mb_substr($phone, 0, 30) : null,
                    'email' => $email !== '' ? mb_substr($email, 0, 255) : null,
                    'address' => $address !== '' ? $address : null,
                    'city' => $city !== '' ? mb_substr($city, 0, 60) : null,
                    'currency_code' => $currency,
                    'notes' => mb_substr('Tsoft müşterisi ('.$label.': '.$externalKey.')', 0, 190),
                    'is_active' => true,
                ];

                // Aynı vergi numaralı cari (ör. eski placeholder) varsa tekrar açılmaz.
                $party = null;
                if ($taxNumber !== '') {
                    $party = Party::query()
                        ->where('company_id', $companyId)
                        ->where('tax_number', $taxNumber)
                        ->orderBy('id')
                        ->first();
                }

                try {
                    if ($party instanceof Party) {
                        // Mevcarinin kodu/siparis baglantisi korunur; veri guncellenir.
                        $party->fill($attributes)->save();
                    } else {
                        $party = Party::updateOrCreate(
                            ['company_id' => $companyId, 'code' => $code],
                            $attributes + ['code' => $code],
                        );
                    }
                } catch (Throwable) {
                    // MySQL'de code/address kolonlari daha kisa olabilir; satiri
                    // kaybetmemek için şema güvenli uzunlukla bir kez daha dene.
                    $short = $attributes;
                    $short['address'] = $short['address'] === null
                        ? null
                        : mb_substr($short['address'], 0, 255);
                    if ($party instanceof Party) {
                        $party->fill($short)->save();
                    } else {
                        $party = Party::updateOrCreate(
                            ['company_id' => $companyId, 'code' => mb_substr($code, 0, 12)],
                            $short + ['code' => mb_substr($code, 0, 12)],
                        );
                    }
                }

                IntegrationIdMap::updateOrCreate(
                    [
                        'company_id' => $companyId,
                        'mrc_type' => 'party',
                        'mrc_id' => (int) $party->id,
                        'external_type' => 'customer',
                        'external_key' => mb_substr($externalKey, 0, 190),
                    ],
                    [],
                );

                $imported++;
            } catch (Throwable) {
                // Ham veri/müşteri bilgisi hata mesajına yazılmaz.
                $report['errors'][] = 'customers['.$index.']: cari yazilamadi.';
            }
        }

        return $imported;
    }

    /**
     * rest1 customer/getCustomers listesinde yer almayan misafir alıcılar için
     * sipariş gövdesinden cari kurar. Kişisel veri yalnızca MRC veritabanına
     * yazılır; ham satır hata mesajına veya günlüğe kopyalanmaz.
     *
     * @param  array<int,array<string,mixed>>  $buyers
     * @param  array<string,mixed>  $report
     */
    private function importOrderBuyers(int $companyId, array $buyers, array &$report): int
    {
        $currency = strtoupper((string) config('integrations.channels.tsoft.currency', 'TRY'));
        $imported = 0;
        $seen = [];

        foreach (array_values($buyers) as $index => $raw) {
            if (! is_array($raw)) {
                continue;
            }

            try {
                $name = trim((string) ($raw['name'] ?? ''));
                if ($name === '') {
                    continue;
                }

                $code = trim((string) ($raw['code'] ?? ''));
                $externalId = trim((string) ($raw['id'] ?? ''));
                $dedupeKey = $code.'|'.$externalId;
                if (isset($seen[$dedupeKey])) {
                    continue;
                }
                $seen[$dedupeKey] = true;

                // parties.code varchar(12): uzun misafir kodlari (ts_...) kisaltılır.
                $partyCode = $code !== '' && mb_strlen($code) <= 12
                    ? $code
                    : 'T'.mb_substr(md5($dedupeKey), 0, 11);

                $name = mb_substr($name, 0, 190);
                $taxNumber = mb_substr(trim((string) ($raw['tax'] ?? '')), 0, 20);
                $email = mb_substr(trim((string) ($raw['email'] ?? '')), 0, 255);
                $phone = mb_substr(trim((string) ($raw['phone'] ?? '')), 0, 30);
                $city = mb_substr(trim((string) ($raw['city'] ?? '')), 0, 60);
                $address = mb_substr(trim((string) ($raw['address'] ?? '')), 0, 255);

                $contactAttributes = [
                    'name' => $name,
                    'tax_number' => $taxNumber !== '' ? $taxNumber : null,
                    'email' => $email !== '' ? $email : null,
                    'phone' => $phone !== '' ? $phone : null,
                    'city' => $city !== '' ? $city : null,
                    'address' => $address !== '' ? $address : null,
                ];

                $party = Party::query()
                    ->where('company_id', $companyId)
                    ->where('code', $partyCode)
                    ->first();

                if ($party instanceof Party) {
                    // Gerçek adı olan cari korunur; placeholder/boşsa kişisel veri yazılır.
                    $currentName = trim((string) $party->name);
                    if ($currentName === '' || in_array($currentName, ['Tsoft Müşteri', 'TSOFT-ANON'], true)) {
                        $party->fill($contactAttributes)->save();
                    } else {
                        // Üye cari listesinde adres/telefon boş olabilir; sipariş
                        // adresinden yalnızca eksik alanları tamamla, mevcut veriyi ezme.
                        $eksik = [];
                        foreach (['tax_number', 'email', 'phone', 'city', 'address'] as $field) {
                            if (trim((string) ($party->{$field} ?? '')) === '' && ($contactAttributes[$field] ?? null) !== null) {
                                $eksik[$field] = $contactAttributes[$field];
                            }
                        }
                        if ($eksik !== []) {
                            $party->fill($eksik)->save();
                        }
                    }
                } else {
                    $attributes = $contactAttributes + [
                        'type' => 'customer',
                        'code' => $partyCode,
                        'currency_code' => $currency,
                        'notes' => 'Tsoft sipariş müşterisi',
                        'is_active' => true,
                    ];
                    $party = Party::updateOrCreate(
                        ['company_id' => $companyId, 'code' => $partyCode],
                        $attributes,
                    );
                }

                // Hem opak kodu hem sayısal müşteri kimliği eşlenir (importCustomers ile aynı).
                $keys = array_filter(
                    [$code, $externalId],
                    static fn (string $key): bool => $key !== '' && $key !== '0',
                );
                foreach (array_unique($keys) as $externalKey) {
                    IntegrationIdMap::updateOrCreate(
                        [
                            'company_id' => $companyId,
                            'mrc_type' => 'party',
                            'mrc_id' => (int) $party->id,
                            'external_type' => 'customer',
                            'external_key' => mb_substr($externalKey, 0, 190),
                        ],
                        [],
                    );
                }

                $imported++;
            } catch (Throwable) {
                // Ham veri/kişisel bilgi hata mesajına yazılmaz.
                $report['errors'][] = 'order_buyers['.$index.']: cari yazilamadi.';
            }
        }

        return $imported;
    }

    /**
     * Yalnizca eşlenmemiş siparişlerdeki vergi numaralarindan cari olusturur.
     * Ad/e-posta/telefon/adres uydurulmaz; placeholder ad kullanilir.
     *
     * @param  array<int,CanonicalOrder>  $orders
     * @param  array<string,mixed>  $report
     */
    private function importParties(int $companyId, array $orders, array &$report): int
    {
        $taxIds = [];
        foreach ($orders as $order) {
            // Müşteri customer/getCustomers'dan zaten eşlendiyse placeholder açılmaz.
            $keys = [];
            if (is_array($order->raw ?? null)) {
                foreach (['CustomerCode', 'CustomerId'] as $field) {
                    $value = trim((string) ($order->raw[$field] ?? ''));
                    if ($value !== '') {
                        $keys[] = $value;
                    }
                }
            }
            $buyerRef = $order->buyerRef ?? null;
            if ($buyerRef !== null && trim((string) $buyerRef) !== '') {
                $keys[] = trim((string) $buyerRef);
            }

            if ($keys !== [] && IntegrationIdMap::query()
                ->where('company_id', $companyId)
                ->where('mrc_type', 'party')
                ->where('external_type', 'customer')
                ->whereIn('external_key', array_values($keys))
                ->exists()) {
                continue;
            }

            $taxId = $order->buyerTaxId === null ? '' : trim($order->buyerTaxId);

            if ($taxId !== '') {
                $taxIds[mb_substr($taxId, 0, 20)] = true;
            }
        }

        $imported = 0;
        foreach (array_keys($taxIds) as $taxNumber) {
            try {
                Party::updateOrCreate(
                    ['company_id' => $companyId, 'tax_number' => $taxNumber],
                    [
                        'type' => 'customer',
                        'code' => Party::nextCode($companyId),
                        'name' => 'Tsoft Müşteri',
                        'notes' => 'Tsoft yeniden kurulum: yalnizca vergi numarasi guvenilirdir.',
                        'is_active' => true,
                    ],
                );

                $imported++;
            } catch (Throwable) {
                $report['errors'][] = 'parties: cari yazilamadi.';
            }
        }

        return $imported;
    }

    private function defaultWarehouseId(int $companyId): ?int
    {
        try {
            if (! Schema::hasTable('warehouses')) {
                return null;
            }

            $id = DB::table('warehouses')
                ->where('company_id', $companyId)
                ->orderBy('id')
                ->value('id');

            return $id === null ? null : (int) $id;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $data @param array<int,string> $keys */
    private function pick(array $data, array $keys, mixed $default = null): mixed
    {
        foreach ($keys as $key) {
            $value = $data;

            foreach (explode('.', $key) as $segment) {
                if (! is_array($value) || ! array_key_exists($segment, $value)) {
                    $value = null;

                    break;
                }

                $value = $value[$segment];
            }

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return $default;
    }

    /** Para ve miktarlar her zaman string decimaldir (float kullanilmaz). */
    private function decimal(mixed $value, int $scale = 4): string
    {
        $raw = str_replace(',', '.', (string) $value);

        return bcadd(is_numeric($raw) ? $raw : '0', '0', $scale);
    }

    /**
     * @param  array<string,mixed>  $report
     * @return array<string,mixed>
     */
    private function finish(array $report, int $companyId, float $started): array
    {
        $report['elapsed_ms'] = (int) round((microtime(true) - $started) * 1000);

        $this->writeSyncLog($companyId, $report);

        return $report;
    }

    /** @param array<string,mixed> $report */
    private function writeSyncLog(int $companyId, array $report): void
    {
        try {
            if (! Schema::hasTable('sync_logs')) {
                return;
            }

            DB::table('sync_logs')->insert([
                'company_id' => $companyId,
                'channel' => 'tsoft',
                'direction' => 'in',
                'entity' => 'mrc_resync',
                'external_ref' => 'resync:'.$companyId,
                'duration_ms' => (int) ($report['elapsed_ms'] ?? 0),
                'status' => (string) ($report['status'] ?? 'failed'),
                // Yalnizca sayilar: ham veri/kisisel veri yazilmaz.
                'preview' => json_encode([
                    'dry_run' => (bool) ($report['dry_run'] ?? true),
                    'deleted' => $report['deleted'] ?? [],
                    'imported' => $report['imported'] ?? [],
                    'errors' => count($report['errors'] ?? []),
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ]);
        } catch (Throwable) {
            Log::warning('mrc.resync.sync_log_failed', ['company_id' => $companyId]);
        }
    }
}
