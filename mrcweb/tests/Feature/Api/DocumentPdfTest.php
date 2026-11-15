<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\V1\DocumentPdfController;
use App\Models\Company;
use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentPdfTest extends TestCase
{
    use RefreshDatabase;

    protected function registerUser(array $overrides = []): array
    {
        $payload = array_merge([
            'name' => 'Sahip Kullanıcı',
            'email' => 'sahip@firma-a.test',
            'password' => 'parola1234',
            'company_name' => 'Firma A',
        ], $overrides);

        $response = $this->apiPost('/api/v1/auth/register', $payload);

        return ['response' => $response, 'token' => $response->json('token')];
    }

    protected function headersFrom(array $registration): array
    {
        return ['Authorization' => "Bearer {$registration['token']}"];
    }

    protected function createParty(array $headers, string $name = 'Müşteri Ltd', array $overrides = []): int
    {
        return $this->apiPost('/api/v1/parties', array_merge(['name' => $name], $overrides), $headers)
            ->assertCreated()->json('party.id');
    }

    protected function createIssuedInvoice(array $headers, int $partyId, array $itemOverrides = [], array $docOverrides = []): array
    {
        $doc = $this->apiPost('/api/v1/documents', array_merge([
            'type' => 'invoice',
            'party_id' => $partyId,
            'items' => [array_merge(
                ['name' => 'Danışmanlık', 'quantity' => 2, 'unit_price' => 100, 'tax_rate' => 20],
                $itemOverrides
            )],
        ], $docOverrides), $headers)->assertCreated()->json('document');

        $this->apiPost("/api/v1/documents/{$doc['id']}/status", ['status' => 'issued'], $headers)->assertOk();

        return $doc;
    }

    public function test_fatura_pdf_200_ve_pdf_govdesi_doner(): void
    {
        $owner = $this->registerUser();
        $headers = $this->headersFrom($owner);
        $partyId = $this->createParty($headers);
        $doc = $this->createIssuedInvoice($headers, $partyId);

        $res = $this->apiGet("/api/v1/documents/{$doc['id']}/pdf", $headers)->assertOk();

        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $body = $res->getContent();
        $this->assertStringStartsWith('%PDF-', $body);
        $this->assertGreaterThan(2000, strlen($body));
        $this->assertStringContainsString(
            'attachment; filename="'.$doc['serial'].'.pdf"',
            $res->headers->get('Content-Disposition')
        );
    }

    public function test_taslak_belge_pdf_de_uretilir(): void
    {
        $owner = $this->registerUser();
        $headers = $this->headersFrom($owner);
        $partyId = $this->createParty($headers);

        $docId = $this->apiPost('/api/v1/documents', [
            'type' => 'quote',
            'party_id' => $partyId,
            'items' => [['name' => 'Keşif Bedeli', 'quantity' => 1, 'unit_price' => 50]],
        ], $headers)->assertCreated()->json('document.id');

        $res = $this->apiGet("/api/v1/documents/{$docId}/pdf", $headers)->assertOk();

        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-', $res->getContent());
    }

    public function test_turkce_karakterli_belge_pdf_hata_vermez(): void
    {
        $owner = $this->registerUser();
        $headers = $this->headersFrom($owner);
        $partyId = $this->createParty($headers, 'Şirket ĞÜÇİÖŞÜ');

        $doc = $this->createIssuedInvoice(
            $headers,
            $partyId,
            ['name' => 'Çay Ödüllü Ürün — İskonto'],
            ['notes' => 'Türkçe karakterler: ğüçşıöİ']
        );

        $res = $this->apiGet("/api/v1/documents/{$doc['id']}/pdf", $headers)->assertOk();

        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-', $res->getContent());
    }

    public function test_pdf_tenant_izolasyonu(): void
    {
        $a = $this->registerUser(['company_name' => 'A']);
        $headersA = $this->headersFrom($a);
        $partyA = $this->createParty($headersA);
        $doc = $this->createIssuedInvoice($headersA, $partyA);

        $b = $this->registerUser(['email' => 'sahip-b@firma-b.test', 'company_name' => 'B']);
        $headersB = $this->headersFrom($b);

        $this->apiGet("/api/v1/documents/{$doc['id']}/pdf", $headersB)->assertNotFound();
        $this->apiGet("/api/v1/documents/{$doc['id']}/pdf", $headersA)->assertOk();
    }

    public function test_pdf_inline_ve_attachment_basliklari(): void
    {
        $owner = $this->registerUser();
        $headers = $this->headersFrom($owner);
        $partyId = $this->createParty($headers);
        $doc = $this->createIssuedInvoice($headers, $partyId);

        $inline = $this->apiGet("/api/v1/documents/{$doc['id']}/pdf?inline=1", $headers)->assertOk();
        $this->assertSame('inline', $inline->headers->get('Content-Disposition'));

        $attachment = $this->apiGet("/api/v1/documents/{$doc['id']}/pdf", $headers)->assertOk();
        $this->assertSame(
            'attachment; filename="'.$doc['serial'].'.pdf"',
            $attachment->headers->get('Content-Disposition')
        );
    }

    public function test_ingilizce_dilde_pdf_uretilir(): void
    {
        $owner = $this->registerUser();
        $headers = $this->headersFrom($owner);
        $partyId = $this->createParty($headers);
        $doc = $this->createIssuedInvoice($headers, $partyId);

        $this->apiPatch('/api/v1/me/locale', ['locale' => 'en'], $headers)->assertOk();

        $res = $this->apiGet("/api/v1/documents/{$doc['id']}/pdf", $headers)->assertOk();

        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-', $res->getContent());
    }

    public function test_kismi_odemeli_belge_pdf_hata_vermez(): void
    {
        $owner = $this->registerUser();
        $headers = $this->headersFrom($owner);
        $partyId = $this->createParty($headers);
        $doc = $this->createIssuedInvoice($headers, $partyId);

        $this->apiPost('/api/v1/payments', [
            'party_id' => $partyId, 'direction' => 'in', 'amount' => 40,
            'document_id' => $doc['id'], 'method' => 'cash',
        ], $headers)->assertCreated();

        $res = $this->apiGet("/api/v1/documents/{$doc['id']}/pdf", $headers)->assertOk();

        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-', $res->getContent());
        // mali belge önbelleğe düşmemeli (ortak bilgisayar / proxy)
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
    }

    public function test_taslan_odemeli_belge_pdf_hata_vermez(): void
    {
        $owner = $this->registerUser();
        $headers = $this->headersFrom($owner);
        $partyId = $this->createParty($headers);
        $doc = $this->createIssuedInvoice($headers, $partyId); // 2×100 + %20 KDV → genel toplam 240

        // v1 bilinçli sınırı: belgeye bağlı ödemede taşma engellenmez.
        // PDF bu durumda patlamamalı ve „Fazla ödeme" satırıyla üretilmeli.
        $this->apiPost('/api/v1/payments', [
            'party_id' => $partyId, 'direction' => 'in', 'amount' => 300,
            'document_id' => $doc['id'], 'method' => 'cash',
        ], $headers)->assertCreated();

        $this->assertSame('paid', $this->apiGet("/api/v1/documents/{$doc['id']}", $headers)->assertOk()->json('document.status'));

        $res = $this->apiGet("/api/v1/documents/{$doc['id']}/pdf", $headers)->assertOk();
        $this->assertStringStartsWith('%PDF-', $res->getContent());
        $this->assertGreaterThan(2000, strlen($res->getContent()));

        // aşırı tahsilatta eksi bakiye yerine „Fazla ödeme" + mutlak değer gösterilir
        $html = view('pdf.document', (new DocumentPdfController())->payload(
            Document::findOrFail($doc['id'])
        ))->render();
        $this->assertStringContainsString('Fazla ödeme', $html);
        $this->assertStringNotContainsString('-60,00', $html);
        $this->assertStringContainsString('60,00', $html);
    }

    public function test_pdf_sablonu_turkce_yazimi_ve_bicimleri_korur(): void
    {
        $owner = $this->registerUser();
        $headers = $this->headersFrom($owner);
        $partyId = $this->createParty($headers, 'Çiğköfteci Şükrü Gıda — ĞÜÇİÖŞÜ');
        $doc = $this->createIssuedInvoice($headers, $partyId, [
            'name' => 'Yerinde Servis — Öğütme Ücreti (ĞÜÇİÖŞÜ)',
        ], ['notes' => 'Türkçe not: ğüçşıöİ', 'due_date' => '2026-12-15', 'issue_date' => '2026-10-30']);

        $html = view('pdf.document', (new DocumentPdfController())->payload(
            Document::findOrFail($doc['id'])
        ))->render();

        // dompdf text-transform:uppercase'ü Türkçe kurallarıyla uygulamıyor
        // („Belge BILGILERI" üretiyordu) → şablon CSS'te büyük harfe çevirme olmamalı.
        $this->assertStringNotContainsString('text-transform', $html);

        // Başlıklar ve etiketler Türkçe yazımıyla görünür
        foreach (['Cari', 'Belge bilgileri', 'Notlar', 'İskonto', 'Genel toplam', 'Ödeme durumu', 'Birim fiyat'] as $etiket) {
            $this->assertStringContainsString($etiket, $html, "etiket kayıp: {$etiket}");
        }

        // Kullanıcı verisi ve not Türkçe karakterlerle bozulmadan gelir
        $this->assertStringContainsString('ĞÜÇİÖŞÜ', $html);
        $this->assertStringContainsString('Şükrü', $html);
        $this->assertStringContainsString('ğüçşıöİ', $html);

        // tr biçim: tarih ve sayı
        $this->assertStringContainsString('30 Eki 2026', $html);
        $this->assertStringContainsString('15 Ara 2026', $html);
        $this->assertStringContainsString('240,00', $html);

        // companies.country varsayılanı „TR": adres/şehir yokken başına satır olmasın
        $this->assertStringNotContainsString('>TR<', $html);

        // kullanıcı verisi kaçışlı basılır
        $xss = $this->createIssuedInvoice($headers, $partyId, ['name' => '<script>alert(1)</script>']);
        $xssHtml = view('pdf.document', (new DocumentPdfController())->payload(
            Document::findOrFail($xss['id'])
        ))->render();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $xssHtml);
        $this->assertStringContainsString('&lt;script&gt;', $xssHtml);
    }

    public function test_ingilizce_dil_pdf_sablonu_ingilizce_etiket_uretir(): void
    {
        $owner = $this->registerUser();
        $headers = $this->headersFrom($owner);
        $partyId = $this->createParty($headers);
        // tarih sabitlenir: „bugünün tarihi”ne bağlı doğrulama yarın kırılırdı
        $doc = $this->createIssuedInvoice($headers, $partyId, [], ['issue_date' => '2026-10-30']);

        $this->apiPatch('/api/v1/me/locale', ['locale' => 'en'], $headers)->assertOk();
        app()->setLocale('en');

        $html = view('pdf.document', (new DocumentPdfController())->payload(
            Document::findOrFail($doc['id'])
        ))->render();

        $this->assertStringContainsString('Document details', $html);
        $this->assertStringContainsString('Discount', $html);
        $this->assertStringContainsString('Grand total', $html);
        // İngilizce biçim: tarih ve sayı ayracı
        $this->assertStringContainsString('Oct 30, 2026', $html);
        $this->assertStringContainsString('240.00', $html);
    }

    public function test_pdf_sablonu_vergi_dairesi_ve_firma_logosunu_basir(): void
    {
        $owner = $this->registerUser();
        $headers = $this->headersFrom($owner);
        $partyId = $this->createParty($headers);
        $doc = $this->createIssuedInvoice($headers, $partyId);

        $this->apiPatch('/api/v1/company', [
            'tax_number' => '1234567890',
            'tax_office' => 'Malatya Vergi Dairesi',
        ], $headers)->assertOk();

        $html = view('pdf.document', (new DocumentPdfController())->payload(
            Document::findOrFail($doc['id'])
        ))->render();

        $this->assertStringContainsString('Vergi dairesi: Malatya Vergi Dairesi', $html);
        $this->assertStringContainsString('Vergi no: 1234567890', $html);
        // logosuz firmada <img> üretilmez (boş kutu/bozuk resim çıkmaz)
        $this->assertStringNotContainsString('<img ', $html);

        // 1×1 PNG: public disk’e yazılır, payload gerçek diske baktığı için fake kullanılmaz
        $logoPath = 'logos/pdf-test-logo.png';
        Storage::disk('public')->put($logoPath, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ));

        $companyId = $this->apiGet('/api/v1/company', $headers)->json('company.id');
        Company::findOrFail($companyId)->forceFill(['logo_path' => $logoPath])->save();

        try {
            $withLogo = view('pdf.document', (new DocumentPdfController())->payload(
                Document::findOrFail($doc['id'])
            ))->render();

            $this->assertStringContainsString('<img ', $withLogo);
            // dompdf uzak kaynak çekmez: kök, yerel dosya yolu olmalı
            $this->assertStringContainsString('logos/pdf-test-logo.png', $withLogo);
            $this->assertStringNotContainsString('http://', $withLogo);
        } finally {
            Storage::disk('public')->delete($logoPath);
        }

        // dosya silinince belge yine üretilir (logo satırı düşer, patlamaz)
        $afterDelete = view('pdf.document', (new DocumentPdfController())->payload(
            Document::findOrFail($doc['id'])
        ))->render();
        $this->assertStringNotContainsString('<img ', $afterDelete);
        $this->assertStringContainsString('Vergi dairesi: Malatya Vergi Dairesi', $afterDelete);
    }

    public function test_ingilizce_dilde_vergi_dairesi_etiketi_ingilizce_basilir(): void
    {
        $owner = $this->registerUser();
        $headers = $this->headersFrom($owner);
        $partyId = $this->createParty($headers);
        $doc = $this->createIssuedInvoice($headers, $partyId);

        $this->apiPatch('/api/v1/company', ['tax_office' => 'Malatya Tax Office'], $headers)->assertOk();
        $this->apiPatch('/api/v1/me/locale', ['locale' => 'en'], $headers)->assertOk();
        app()->setLocale('en');

        $html = view('pdf.document', (new DocumentPdfController())->payload(
            Document::findOrFail($doc['id'])
        ))->render();

        $this->assertStringContainsString('Tax office: Malatya Tax Office', $html);
        $this->assertStringNotContainsString('Vergi dairesi:', $html);
    }
}
