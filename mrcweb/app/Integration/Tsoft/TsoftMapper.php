<?php

namespace App\Integration\Tsoft;

use App\Integration\Canonical\CanonicalOrder;
use App\Integration\Canonical\CanonicalOrderLine;
use App\Integration\Canonical\CanonicalPrice;
use App\Integration\Canonical\CanonicalProduct;
use App\Integration\Canonical\CanonicalStock;
use App\Integration\Enums\FieldSource;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use DateTimeZone;
use Throwable;

/**
 * Tsoft ham verisini kanonik DTO'lara çevirir.
 *
 * KVKK ilkesi: müşteri adı / e-posta / telefon / adres kanonik DTO'lara
 * ASLA kopyalanmaz. Yalnızca `buyerTaxId` ve opak bir `buyerRef` (müşteri no)
 * taşınır. `raw` alanı da maskelenerek saklanır.
 *
 * Para tutarları string decimal olarak üretilir (4 ondalık, nokta ayırıcı).
 */
final class TsoftMapper
{
    /**
     * rest1 `CargoId` -> kargo firması adı. Yalnızca canlı doğrulanmış eşleme
     * tutulur; bilinmeyen kimlikler sayısal olarak korunur (kişisel veri değildir).
     */
    private const CARGO_COMPANIES = [
        6 => 'DHL Express',
    ];

    /**
     * Hem legacy (rest1) hem admin (2026-01) sipariş şekillerini kabul eder.
     * JSON:API kaynağı verilirse `attributes.*` yolları da okunur.
     */
    public static function order(array $raw): CanonicalOrder
    {
        $externalRef = (string) self::pick($raw, [
            'OrderCode', 'orderNumber', 'id', 'attributes.orderNumber', 'attributes.id', 'externalRef',
        ], '');

        $orderNumberValue = self::pick($raw, [
            'OrderNumber', 'orderNumber', 'attributes.orderNumber', 'orderNo',
        ]);

        $placedAt = self::orderPlacedAt($raw);

        // rest1 `FetchProductData=1` ile dönen OrderDetails gerçek sipariş
        // kalemleridir. Eski gövde şekilleri (items/Products/...) yedektir.
        $lines = [];
        $items = self::pick($raw, [
            'OrderDetails', 'items', 'Items', 'Products', 'OrderProducts', 'products', 'attributes.items',
        ], []);
        if (is_array($items)) {
            foreach ($items as $item) {
                if (is_array($item)) {
                    $lines[] = self::line($item);
                }
            }
        }

        // rest1 order/get durum kimliğini ayrı bir alan olarak verir; etiket yerine
        // daima bu kimliği (string) tercih et.
        $statusId = (string) self::pick($raw, ['OrderStatusId'], '');
        $paymentType = mb_strtolower(trim((string) self::pick($raw, ['PaymentType'], '')));
        $kapida = str_contains($paymentType, 'kapida') || str_contains($paymentType, 'kapıda');

        // Maskelenmiş ham veri: hata ayıklama için yeterli, kişisel veri içermez.
        $safeRaw = self::mask($raw);
        // Bu rest1 alanları kişisel veri değildir; maskeleme sonrası da açıkça
        // korunur (durum kimliği ve para alanları sonraki adımlarda okunur).
        foreach ([
            'OrderSubtotal', 'DiscountTotal', 'OrderStatusId', 'IsInvoiced',
            'InvoiceNumber', 'CargoTrackingCode', 'TransactionId',
        ] as $field) {
            if (array_key_exists($field, $raw)) {
                $safeRaw[$field] = $raw[$field];
            }
        }

        // Kanal metadata'sı için beyaz liste. Ad/e-posta/telefon/adres ASLA
        // eklenmez; yalnızca opak teknik ve para alanları taşınır.
        $cargoId = self::adminString(self::pick($raw, ['CargoId'], ''));
        $safeFields = [
            'PaymentMethod' => self::adminString(self::pick($raw, ['PaymentType'], '')),
            'PaymentVendor' => self::adminString(self::pick($raw, ['BankTransactionVendor', 'PaymentInfo'], '')),
            'PaymentBank' => self::adminString(self::pick($raw, ['PaymentBankName', 'Bank'], '')),
            'PaymentTransactionId' => self::adminString(self::pick($raw, ['TransactionId', 'OrderCode'], '')),
            'Installment' => self::adminString(self::pick($raw, ['Installment'], 0)),
            'CargoCompany' => self::CARGO_COMPANIES[$cargoId] ?? $cargoId,
            'TrackingNumber' => self::adminString(self::pick($raw, ['CargoTrackingCode'], '')),
            'InvoiceNo' => self::adminString(self::pick($raw, ['InvoiceNumber'], '')),
            'Platform' => self::adminString(self::pick($raw, ['Platform'], '')),
            'DiscountTotal' => self::decimal(self::pick($raw, ['DiscountTotal'])),
            'ShippingTotal' => self::decimal(self::pick($raw, ['ShippingTotal', 'CargoTotal'])),
            'TaxTotal' => self::decimal(self::pick($raw, ['TaxTotal'])),
            'SubTotal' => self::decimal(self::pick($raw, ['SubTotal', 'OrderSubtotal'])),
            'TsoftOrderNo' => self::adminString(self::pick($raw, ['OrderCode'], '')),
            'OrderStatusId' => self::adminString(self::pick($raw, ['OrderStatusId'], '')),
            'OrderTotalPrice' => self::decimal(self::pick($raw, ['OrderTotalPrice'])),
            'CustomerCode' => self::adminString(self::pick($raw, ['CustomerCode'], '')),
            'CustomerId' => self::adminString(self::pick($raw, ['CustomerId'], '')),
            'ProductsCount' => self::adminString(self::pick($raw, ['ProductsCount'], count($lines))),
        ];
        foreach ($safeFields as $field => $value) {
            $safeRaw[$field] = $value;
        }

        // Ödeme durumunda temkinliyiz: yalnızca faturalanmış, ödendiği bilinen
        // durum kimlikleri (7/8/13) veya kapıda ödeme OLMAYAN bir ödeme tipi
        // "ödendi" sayılır; diğer hallerde false kalır.
        $isPaid = self::bool(self::pick($raw, ['IsPaid', 'isPaid', 'paid', 'attributes.isPaid']))
            || self::bool(self::pick($raw, ['IsInvoiced', 'isInvoiced']))
            || in_array($statusId, ['7', '8', '13'], true)
            || ($paymentType !== '' && ! $kapida);

        return new CanonicalOrder(
            $externalRef,
            $orderNumberValue === null ? null : (string) $orderNumberValue,
            strtoupper((string) self::pick($raw, [
                'Currency', 'currency', 'currencyCode', 'attributes.currency', 'attributes.currencyCode',
            ], 'TRY')),
            // Toplam da kalemler gibi string decimal taşınır (float yuvarlaması yok).
            // rest1 order/get'de gerçek sipariş toplamı OrderTotalPrice alanındadır.
            self::decimal(self::pick($raw, [
                'OrderTotalPrice', 'GrandTotal', 'TotalAmount', 'total', 'grandTotal',
                'attributes.total', 'attributes.grandTotal',
            ])),
            (string) self::pick($raw, [
                'OrderStatusId', 'OrderStatus', 'Status', 'status', 'orderStatus',
                'statusCode', 'attributes.status',
            ], ''),
            self::nullableString(self::pick($raw, [
                'TransactionId', 'PaymentRef', 'paymentRef', 'paymentId', 'transactionId', 'attributes.paymentRef',
            ])),
            $isPaid,
            $placedAt,
            self::nullableString(self::pick($raw, [
                'TaxNumber', 'TaxNo', 'buyerTaxId', 'taxId', 'VergiNo', 'TaxId',
                'attributes.buyerTaxId', 'attributes.taxId',
            ])),
            // Müşteri kodu, müşteri kimliğinden daha kararlı bir opak referanstır.
            self::nullableString(self::pick($raw, [
                'CustomerCode', 'CustomerId', 'buyerRef', 'customerId', 'buyer_id',
                'attributes.buyerRef', 'attributes.customerId',
            ])),
            $lines,
            $safeRaw,
        );
    }

    /**
     * Panel/admin API v3 sipariş gövdesi (products/customer/address gömülü).
     *
     * KVKK: müşteri adı, e-posta, telefon ve adresler okunmaz; yalnızca opak
     * customer.id / customer.wsCode ve fatura vergi numarası taşınır. `raw`
     * alanı elle seçilmiş, iç içe dizi içermeyen güvenli anahtarlardan kurulur.
     */
    public static function adminOrder(array $raw): CanonicalOrder
    {
        $externalRef = (string) self::pick($raw, ['id'], '');
        $orderNumber = self::pick($raw, ['orderNumber']);
        $currency = strtoupper((string) self::pick($raw, ['orderCurrency'], 'EUR'));

        $grandTotal = self::decimal(self::pick($raw, [
            'summary.default.grandTotal',
            'summary.customer.grandTotal',
            'summary.default.total',
        ]));

        $statusId = (string) self::pick($raw, ['orderStatus.id'], '');
        $paymentRef = self::pick($raw, ['payment.transactionId', 'orderNumber']);

        $approved = ($raw['payment']['approved'] ?? null) === true;
        $isPaid = $approved || in_array($statusId, ['7', '8', '13'], true);

        $placedAt = null;
        $createdAt = self::pick($raw, ['createdAt']);
        if ($createdAt !== null && $createdAt !== '' && is_numeric($createdAt)) {
            try {
                // Tsoft paneli createdAt'i mağaza yerel saatinde (Europe/Istanbul) gösterir;
                // UTC'ye çevirmek siparişi ertesi/gün önceye kaydırır.
                $placedAt = Carbon::createFromTimestamp((int) $createdAt, new DateTimeZone('Europe/Istanbul'));
            } catch (Throwable) {
                $placedAt = null;
            }
        }

        $taxNumber = self::pick($raw, ['address.billing.taxNumber']);
        $buyerTaxId = self::nullableString($taxNumber);
        if ($buyerTaxId !== null) {
            $buyerTaxId = trim($buyerTaxId);
            if ($buyerTaxId === '') {
                $buyerTaxId = null;
            }
        }

        $buyerRef = self::nullableString(self::pick($raw, ['customer.wsCode', 'customer.id']));

        $lines = [];
        $products = self::pick($raw, ['products'], []);
        if (is_array($products)) {
            foreach ($products as $product) {
                if (! is_array($product)) {
                    continue;
                }

                $sku = self::nullableString(self::pick($product, ['code', 'variantProductCode']));
                $name = self::nullableString(self::pick($product, ['name']));
                if (($name === null || $name === '') && ($sku === null || $sku === '')) {
                    continue;
                }

                $variant = self::nullableString(self::pick($product, ['variantName']));
                if ($name !== null && $variant !== null && $variant !== '') {
                    $name = $name.' - '.$variant;
                }

                $externalLineRef = self::pick($product, ['id', 'productId']);

                $lines[] = new CanonicalOrderLine(
                    $sku,
                    $name,
                    self::decimal(self::pick($product, ['quantity'])),
                    self::decimal(self::pick($product, [
                        'priceSummary.default.unitSalePrice',
                        'priceSummary.default.salePrice',
                    ])),
                    self::decimal(self::pick($product, ['tax'])),
                    self::decimal(self::pick($product, ['discountPercent'])),
                    $externalLineRef === null ? null : (string) $externalLineRef,
                );
            }
        }

        // Yalnızca güvenli alanlar: "name" içeren anahtarlar maskeleneceğinden
        // bilinçli olarak kullanılmaz (PaymentBank, PaymentCardBrand vb. güvenli).
        $safeRaw = [
            'OrderStatusId' => $statusId,
            'OrderTotalPrice' => $grandTotal,
            'CustomerCode' => self::adminString(self::pick($raw, ['customer.wsCode'], '')),
            'CustomerId' => self::adminString(self::pick($raw, ['customer.id'], '')),
            'TsoftOrderNo' => self::adminString($orderNumber ?? ''),
            'PaymentMethod' => self::adminString(self::pick($raw, ['payment.method'], '')),
            'PaymentSubMethod' => self::adminString(self::pick($raw, ['payment.subMethod'], '')),
            'PaymentBank' => self::adminString(self::pick($raw, ['payment.bankName'], '')),
            'PaymentCardBrand' => self::adminString(self::pick($raw, ['payment.ccBrand'], '')),
            'PaymentVendor' => self::adminString(self::pick($raw, ['payment.onlineTransactionVendor'], '')),
            'PaymentTransactionId' => self::adminString(self::pick($raw, ['payment.transactionId'], '')),
            'Installment' => self::adminString(self::pick($raw, ['payment.installment'], 0)),
            'CargoCompany' => self::adminString(self::pick($raw, ['shipping.trackingCompany'], '')),
            'TrackingNumber' => self::adminString(self::pick($raw, ['shipping.trackingNumber'], '')),
            'InvoiceNo' => self::adminString(self::pick($raw, ['invoice.no'], '')),
            'Platform' => self::adminString(self::pick($raw, ['platform'], '')),
            'DiscountTotal' => self::decimal(self::pick($raw, ['summary.default.discountTotal'])),
            'ShippingTotal' => self::decimal(self::pick($raw, ['summary.default.shipping'])),
            'TaxTotal' => self::decimal(self::pick($raw, ['summary.default.tax'])),
            'SubTotal' => self::decimal(self::pick($raw, ['summary.default.subTotal'])),
            'ProductsCount' => self::adminString(self::pick($raw, ['productsCount'], 0)),
            'PaymentApproved' => $approved ? '1' : '0',
        ];

        return new CanonicalOrder(
            $externalRef,
            $orderNumber === null ? null : (string) $orderNumber,
            $currency,
            $grandTotal,
            $statusId,
            self::nullableString($paymentRef),
            $isPaid,
            $placedAt,
            $buyerTaxId,
            $buyerRef,
            $lines,
            $safeRaw,
        );
    }

    public static function line(array $raw): CanonicalOrderLine
    {
        // Ürün adıdır; müşteri kişisel verisi değildir. Varyant kodu varsa adı
        // ayırt edici kılmak için eklenir (adminOrder aynı kuralı kullanır).
        $name = self::nullableString(self::pick($raw, ['ProductName', 'productName', 'name', 'title']));
        $subCode = self::nullableString(self::pick($raw, ['SubProductCode', 'subProductCode', 'variantCode']));
        if ($name !== null && $subCode !== null && $subCode !== '') {
            $name = $name.' - '.$subCode;
        }

        $cost = self::pick($raw, [
            'BuyingPrice', 'buyingPrice', 'CostPrice', 'costPrice', 'purchasePrice',
        ]);

        return new CanonicalOrderLine(
            self::nullableString(self::pick($raw, [
                'ProductCode', 'catalogCode', 'sku', 'Sku', 'productCode', 'wsSubProductCode',
            ])),
            $name,
            self::decimal(self::pick($raw, ['Quantity', 'quantity', 'qty', 'attributes.quantity'])),
            self::decimal(self::pick($raw, [
                'SellingPrice', 'price', 'unitPrice', 'Price', 'attributes.price', 'attributes.unitPrice',
            ])),
            self::decimal(self::pick($raw, ['Vat', 'taxRate', 'vat', 'TaxRate', 'attributes.taxRate'])),
            self::decimal(self::pick($raw, [
                'DiscountPercent', 'Discount', 'discountRate', 'discount', 'attributes.discountRate',
            ])),
            self::nullableString(self::pick($raw, [
                'OrderProductId', 'id', 'lineId', 'externalRef', 'ProductId',
            ])),
            ($cost === null || $cost === '') ? '0' : self::decimal($cost),
        );
    }

    public static function stock(
        array $raw,
        string $warehouseCode = 'default',
        string $source = 'tsoft',
        ?int $version = null,
    ): CanonicalStock {
        $versionValue = $version ?? self::pick($raw, ['version', 'Version', 'expected', 'attributes.version'], 0);

        return new CanonicalStock(
            (string) self::pick($raw, [
                'ProductCode', 'catalogCode', 'sku', 'productSku', 'wsSubProductCode', 'attributes.sku',
            ], ''),
            (string) self::pick($raw, [
                'WarehouseCode', 'warehouseCode', 'warehouseId', 'attributes.warehouseId',
            ], $warehouseCode),
            self::decimal(self::pick($raw, ['Stock', 'quantity', 'stock', 'available', 'attributes.quantity'])),
            (int) $versionValue,
            $source,
            self::nullableString(self::pick($raw, ['id', 'productId', 'variantId', 'attributes.variantId', 'attributes.productId'])),
        );
    }

    public static function price(array $raw): CanonicalPrice
    {
        $cost = self::pick($raw, ['CostPrice', 'costPrice', 'purchasePrice', 'attributes.costPrice']);

        return new CanonicalPrice(
            (string) self::pick($raw, [
                'ProductCode', 'catalogCode', 'sku', 'attributes.sku',
            ], ''),
            strtoupper((string) self::pick($raw, [
                'Currency', 'currency', 'currencyCode', 'attributes.currency',
            ], 'TRY')),
            self::decimal(self::pick($raw, [
                'SellingPrice', 'salePrice', 'price', 'Price', 'attributes.salePrice',
            ])),
            ($cost === null || $cost === '') ? null : self::decimal($cost),
            self::bool(self::pick($raw, ['IsActive', 'isActive', 'active', 'attributes.isActive']), true),
        );
    }

    public static function product(array $raw): CanonicalProduct
    {
        $nameSource = self::pick($raw, ['nameSource', 'name_source', 'attributes.nameSource'], 'tsoft');
        $imageSource = self::pick($raw, ['imageSource', 'image_source', 'attributes.imageSource'], 'tsoft');

        return new CanonicalProduct(
            (string) self::pick($raw, ['ProductCode', 'catalogCode', 'sku', 'attributes.sku'], ''),
            self::nullableString(self::pick($raw, ['Barcode', 'barcode', 'Ean', 'attributes.barcode'])),
            (string) self::pick($raw, ['ProductName', 'productName', 'name', 'title', 'attributes.name'], ''),
            self::nullableString(self::pick($raw, ['Description', 'description', 'attributes.description'])),
            self::nullableString(self::pick($raw, ['ImageUrl', 'imageUrl', 'image', 'Photo', 'attributes.imageUrl'])),
            FieldSource::tryFrom(strtolower((string) $nameSource)) ?? FieldSource::Tsoft,
            FieldSource::tryFrom(strtolower((string) $imageSource)) ?? FieldSource::Tsoft,
            self::nullableString(self::pick($raw, ['CategoryId', 'categoryRef', 'category', 'attributes.categoryId'])),
            self::nullableString(self::pick($raw, ['id', 'productId', 'externalRef'])),
        );
    }

    /**
     * Loglama öncesi kişisel veri ve sırları kırmızıya çeker.
     * email|phone|address|name|token|secret|password ile eşleşen her anahtar
     * (iç içe dizilerde dahil) "[REDACTED]" yapılır.
     */
    public static function mask(array $payload): array
    {
        $sensitive = '/(email|phone|address|name|token|secret|password)/i';
        $out = [];

        foreach ($payload as $key => $value) {
            if (preg_match($sensitive, (string) $key) === 1) {
                $out[$key] = '[REDACTED]';

                continue;
            }

            $out[$key] = is_array($value) ? self::mask($value) : $value;
        }

        return $out;
    }

    /** Noktalı yolları da destekleyen "ilk dolu değer" seçici. */
    private static function pick(array $data, array $keys, mixed $default = null): mixed
    {
        foreach ($keys as $key) {
            $value = $data;

            foreach (explode('.', (string) $key) as $segment) {
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

    /** Para ve miktarlar için daima string decimal üretir. */
    private static function decimal(mixed $value, string $default = '0'): string
    {
        if ($value === null || $value === '' || ! is_scalar($value) || ! is_numeric((string) $value)) {
            return $default;
        }

        return (string) number_format((float) $value, 4, '.', '');
    }

    private static function nullableString(mixed $value): ?string
    {
        return ($value === null || $value === '') ? null : (string) $value;
    }

    /** Güvenli `raw` alanları için skaler olmayan değerleri boş dizeye indirger. */
    private static function adminString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function bool(mixed $value, bool $default = false): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return $value != 0;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'y', 'paid', 'ok'], true);
    }

    /**
     * rest1'de OrderDate alanındaki "Z" mağaza yerel saatinin gösterimidir;
     * UTC sanıp çevirmek tarihi kaydırır. Güvenilir kaynak sayısal
     * OrderDateTimeStamp'tir (gerçek UTC); panelin gösterdiği yerel tarihi
     * Europe/Istanbul ile üretiriz.
     */
    private static function orderPlacedAt(array $raw): ?CarbonInterface
    {
        $zone = new DateTimeZone('Europe/Istanbul');

        $stamp = self::pick($raw, ['OrderDateTimeStamp', 'orderDateTimeStamp']);
        if ($stamp !== null && is_numeric($stamp)) {
            try {
                return Carbon::createFromTimestamp((int) $stamp, $zone);
            } catch (Throwable) {
                // Bozuk damga varsa tarih metnine düş.
            }
        }

        $value = self::pick($raw, [
            'OrderDate', 'placedAt', 'createdAt', 'attributes.placedAt', 'attributes.createdAt', 'orderDate',
        ]);
        if ($value === null || $value === '') {
            return null;
        }

        try {
            if (is_numeric($value)) {
                return Carbon::createFromTimestamp((int) $value, $zone);
            }

            return Carbon::parse((string) $value, $zone);
        } catch (Throwable) {
            return null;
        }
    }

    private static function date(mixed $value): ?CarbonInterface
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $parsed = Carbon::parse((string) $value);

            return $parsed instanceof CarbonInterface ? $parsed : null;
        } catch (Throwable) {
            return null;
        }
    }
}
