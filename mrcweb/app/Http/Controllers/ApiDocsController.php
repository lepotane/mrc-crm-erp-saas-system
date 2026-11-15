<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

/**
 * API dokümanini (OpenAPI + Postman koleksiyonu) sunar — Faz 5.
 *
 * Neden kendi kontrolcümüz var: Scramble YALNIZ dev bağımlılığıdır ve yayın
 * paketi `composer install --no-dev` ile alınır .
 * Bu yüzden şema local build'de üretilir (`npm run openapi`), public/docs/
 * altındaki statik artifact'lar zip ile taşınır ve burada erişime açılır.
 *
 * Uclar :
 *   GET /api-docs        → okunabilir doküman sayfası (Redoc)
 *   GET /api-docs.json   → OpenAPI 3.1 belgesi
 *   GET /api-docs/postman → Postman koleksiyonu (v2.1)
 */
class ApiDocsController extends Controller
{
    public function index(Request $request)
    {
        $dir = $this->dir();

        abort_unless(File::exists($dir.'/index.html'), 404, __(
            'API dokümanı üretilmemiş. Önce `npm run openapi` çalıştırın.'
        ));

        return response()->file($dir.'/index.html', [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function spec(Request $request)
    {
        return $this->serveJson($this->dir().'/api.json', 'MRC API v1 OpenAPI');
    }

    public function postman(Request $request)
    {
        return $this->serveJson(
            $this->dir().'/postman_collection.json',
            'MRC API v1 Postman koleksiyonu',
            'mrc-api-v1-postman.json',
        );
    }

    protected function dir(): string
    {
        $dir = public_path(trim((string) config('api.docs.path', 'docs'), '/'));

        abort_unless(File::isDirectory($dir), 404, __(
            'API dokümanı üretilmemiş. Önce `npm run openapi` çalıştırın.'
        ));

        return $dir;
    }

    protected function serveJson(string $path, string $label, ?string $downloadAs = null)
    {
        abort_unless(File::exists($path), 404, __(
            ':label üretilmemiş. Önce `npm run openapi` çalıştırın.',
            ['label' => $label]
        ));

        // JSON artifact'lari bellekte veriliyor (api.json ~0,5 MB): BinaryFileResponse
        // testlerde govde gostermez (getContent() bos doner); masaustu istemcinin
        // `GET /api-docs.json` sozlesmesi icin duz govde daha dogrudur.
        $response = response(File::get($path), 200, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);

        return $downloadAs
            ? $response->header('Content-Disposition', 'attachment; filename="'.$downloadAs.'"')
            : $response;
    }
}
