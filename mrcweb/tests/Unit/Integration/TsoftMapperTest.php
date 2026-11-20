<?php

namespace Tests\Unit\Integration;

use App\Integration\Tsoft\TsoftMapper;
use PHPUnit\Framework\TestCase;

final class TsoftMapperTest extends TestCase
{
    public function test_admin_shape_is_mapped_and_personal_data_is_not_copied(): void
    {
        $raw = [
            'id' => 'ord-1',
            'type' => 'orders',
            'attributes' => [
                'orderNumber' => 'MRC-100',
                'currency' => 'TRY',
                'total' => '1234.5678',
                'status' => 'paid',
                'isPaid' => true,
                'placedAt' => '2026-11-20T10:00:00Z',
                'buyerTaxId' => '1234567890',
                'buyerRef' => 'cust-42',
                'customerEmail' => 'ahmet@example.com',
                'customerPhone' => '05550000000',
                'shippingAddress' => 'Ataturk Cad. No:1',
                'customerName' => 'Ahmet Yilmaz',
            ],
            'items' => [
                [
                    'id' => 'line-1',
                    'catalogCode' => 'SKU-1',
                    'name' => 'Kalem',
                    'quantity' => '2',
                    'price' => '10.5000',
                    'taxRate' => '20',
                    'discountRate' => '0',
                ],
            ],
        ];

        $order = TsoftMapper::order($raw);

        $this->assertSame('ord-1', $order->externalRef);
        $this->assertSame('MRC-100', $order->orderNumber);
        $this->assertSame('TRY', $order->currency);
        $this->assertSame('paid', $order->externalStatus);
        $this->assertSame('1234567890', $order->buyerTaxId);
        $this->assertSame('cust-42', $order->buyerRef);
        $this->assertTrue($order->isPaid);
        $this->assertNotNull($order->placedAt);
        $this->assertCount(1, $order->lines);

        $line = $order->lines[0];
        $this->assertSame('SKU-1', $line->sku);
        $this->assertSame('2.0000', $line->quantity);
        $this->assertSame('10.5000', $line->unitPrice);
        $this->assertSame('20.0000', $line->taxRate);

        // Toplam ve tüm kalem fiyatları string decimal olarak taşınır.
        $this->assertSame('1234.5678', $order->grandTotal);
        $this->assertIsString($order->grandTotal);
        $this->assertIsString($line->unitPrice);

        $serialized = json_encode($order->toArray(), JSON_UNESCAPED_UNICODE);
        $this->assertIsString($serialized);
        $this->assertStringNotContainsString('ahmet@example.com', $serialized);
        $this->assertStringNotContainsString('05550000000', $serialized);
        $this->assertStringNotContainsString('Ataturk Cad. No:1', $serialized);
        $this->assertStringNotContainsString('Ahmet Yilmaz', $serialized);

        // Maskelenmiş ham veri de kişisel alanları içermez.
        $rawJson = json_encode($order->raw, JSON_UNESCAPED_UNICODE);
        $this->assertIsString($rawJson);
        $this->assertStringNotContainsString('ahmet@example.com', $rawJson);
        $this->assertStringNotContainsString('05550000000', $rawJson);
        $this->assertStringNotContainsString('Ahmet Yilmaz', $rawJson);
    }

    public function test_legacy_shape_is_mapped_with_string_money(): void
    {
        $raw = [
            'OrderCode' => 'ORD-9',
            'OrderNumber' => '9',
            'Currency' => 'TRY',
            'GrandTotal' => '99.9999',
            'Status' => 'Transferred',
            'IsPaid' => '1',
            'OrderDate' => '2026-11-19 12:00:00',
            'TaxNumber' => '111222333',
            'CustomerId' => 'C-9',
            'CustomerEmail' => 'victim@example.com',
            'OrderProducts' => [
                [
                    'ProductCode' => 'L-1',
                    'ProductName' => 'Defter',
                    'Quantity' => '3',
                    'SellingPrice' => '5.2500',
                    'Vat' => '20',
                    'Discount' => '10',
                ],
            ],
        ];

        $order = TsoftMapper::order($raw);

        $this->assertSame('ORD-9', $order->externalRef);
        $this->assertSame('TRY', $order->currency);
        $this->assertSame('111222333', $order->buyerTaxId);
        $this->assertSame('C-9', $order->buyerRef);
        $this->assertTrue($order->isPaid);

        $line = $order->lines[0];
        $this->assertSame('L-1', $line->sku);
        $this->assertSame('3.0000', $line->quantity);
        $this->assertSame('5.2500', $line->unitPrice);
        $this->assertSame('20.0000', $line->taxRate);
        $this->assertSame('10.0000', $line->discountRate);

        $this->assertStringNotContainsString(
            'victim@example.com',
            (string) json_encode($order->toArray(), JSON_UNESCAPED_UNICODE),
        );
    }

    public function test_price_and_stock_keep_money_as_strings(): void
    {
        $price = TsoftMapper::price([
            'ProductCode' => 'SKU-X',
            'SellingPrice' => '19.9900',
            'CostPrice' => '10',
            'Currency' => 'TRY',
            'IsActive' => '1',
        ]);

        $this->assertSame('19.9900', $price->salePrice);
        $this->assertSame('10.0000', $price->costPrice);
        $this->assertIsString($price->salePrice);
        $this->assertTrue($price->isActive);

        $stock = TsoftMapper::stock(
            ['ProductCode' => 'SKU-X', 'Stock' => '7.5', 'version' => 3],
            'wh-1',
            'tsoft',
        );

        $this->assertSame('7.5000', $stock->quantity);
        $this->assertSame('wh-1', $stock->warehouseCode);
        $this->assertSame(3, $stock->version);
    }

    public function test_mask_redacts_sensitive_keys_recursively(): void
    {
        $masked = TsoftMapper::mask([
            'email' => 'a@example.com',
            'orderNumber' => 'ORD-1',
            'nested' => ['api_token' => 'secret-value', 'quantity' => '2'],
        ]);

        $this->assertSame('[REDACTED]', $masked['email']);
        $this->assertSame('ORD-1', $masked['orderNumber']);
        $this->assertSame('[REDACTED]', $masked['nested']['api_token']);
        $this->assertSame('2', $masked['nested']['quantity']);
    }
}
