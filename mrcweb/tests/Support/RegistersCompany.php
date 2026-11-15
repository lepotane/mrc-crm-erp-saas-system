<?php

namespace Tests\Support;

use Illuminate\Testing\TestResponse;

/**
 * Testlerde kiracı (firma) bağlamı kurmak için ortak helper'lar.
 *
 * Neden trait: izolasyon testleri her zaman İKİ firma ister (A'nın kaydına B
 * erişir mi?). Bu kurulum her test dosyasında kopyalanmıştı (register + headers
 * deseninin 8 ayrı kopyası var); yeni testler buradan yürüsün ki ikinci firma
 * kurulumu tek yerde ve tutarlı kalsın.
 *
 * `register()` bilinçli olarak `POST /auth/register` kullanır: firma + varsayılan
 * depo/birim + Admin rolü + `current_company_id` bağlamını KURAN tek gerçek akış
 * odur. Model create ile elle bağlam kurmak, production akışından sapma riski
 * doğurur (deposuz/birimsiz firma testte görünmez, üretimde karşına çıkar).
 */
trait RegistersCompany
{
    /**
     * Yeni bir firma + sahibi (Admin) kurar; token ve header'larıyla döner.
     *
     * @return array{token: string, headers: array<string,string>, company_id: int, user_id: int, response: TestResponse}
     */
    protected function register(string $email, string $companyName): array
    {
        $response = $this->apiPost('/api/v1/auth/register', [
            'name' => $companyName.' Sahibi',
            'email' => $email,
            'password' => 'parola1234',
            'company_name' => $companyName,
        ]);

        $response->assertCreated();

        return [
            'token' => (string) $response->json('token'),
            'headers' => ['Authorization' => 'Bearer '.$response->json('token')],
            'company_id' => (int) $response->json('company.id'),
            'user_id' => (int) $response->json('user.id'),
            'response' => $response,
        ];
    }

    /** Firmanın varsayılan deposunun id'si (register akışı `Ana Depo`yu açar). */
    protected function defaultWarehouseId(array $ctx): int
    {
        $list = $this->apiGet('/api/v1/warehouses', $ctx['headers'])->assertOk()->json('warehouses');

        return (int) ($list[0]['id'] ?? 0);
    }

    /** Firmanın ilk biriminin id'si (yoksa yeni bir birim açar). */
    protected function unitId(array $ctx, string $code = 'kg'): int
    {
        $list = $this->apiGet('/api/v1/units', $ctx['headers'])->assertOk()->json('units') ?? [];

        foreach ($list as $u) {
            if (($u['code'] ?? null) === $code) {
                return (int) $u['id'];
            }
        }

        return (int) $this->apiPost('/api/v1/units', ['name' => ucfirst($code), 'code' => $code], $ctx['headers'])
            ->assertCreated()->json('unit.id');
    }
}
