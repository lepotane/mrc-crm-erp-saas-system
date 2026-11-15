<?php

namespace App\Support;

use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\RouteInfo;

/**
 * Sayfali API listelerine `page` / `per_page` sorgu parametrelerini ekler.
 *
 * Sayfalama sozlesmesi PresentsPaginator::paginateJson() ile sabit: cevap
 * {data, links, meta} ucclusudur. Controller'larda $request->query('page')
 * dogrudan okundugu icin Scramble bu parametreleri kendiliginden uretmiyor;
 * masaustu istemcinin belgeden okuyabilmesi için burada ekleniyor.
 *
 * Sayfali uc olcutu: 2xx cevabinin semasi data + links + meta UCLUSUNU iceriyor.
 * (Yalniz `meta` yetmez: rapor uclari de meta dondurur ama sayfalanmaz.)
 *
 * TUZAK: Operation::$responses indeksli bir LISTEDIR (anahtar 0,1,2), durum kodu
 * Response::$code alanindadir; $operation->responses[200] sessizce null doner.
 * Ayrica Response::$content degerleri MediaType degil Schema olabilir (surume
 * gore degisiyor), bu yuzden semalar nesne tipine gore degil serialize edip
 * dizi uzerinden taranarak inceleniyor.
 */
class PaginatedParametersExtension extends OperationExtension
{
    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        if (! $this->hasPaginatedResponse($operation)) {
            return;
        }

        $existing = [];

        foreach ($operation->parameters as $parameter) {
            if ($parameter instanceof Parameter) {
                $existing[$parameter->name] = true;
            }
        }

        if (! isset($existing['page'])) {
            $operation->parameters[] = Parameter::make('page', 'query')
                ->description('Sayfa numarasi (1 bazli, varsayilan 1).')
                ->setSchema(Schema::fromType((new IntegerType)->default(1)));
        }

        if (! isset($existing['per_page'])) {
            $operation->parameters[] = Parameter::make('per_page', 'query')
                ->description('Sayfa basi kayit (varsayilan 20, en fazla 100). Tum listeyi indirmek icin 100 degeriyle sayfalari dolasin.')
                ->setSchema(Schema::fromType((new IntegerType)->setMin(1)->setMax(100)->default(20)));
        }
    }

    private function hasPaginatedResponse(Operation $operation): bool
    {
        foreach ($operation->responses as $response) {
            if (! $response instanceof Response || (int) $response->code >= 300) {
                continue;
            }

            $array = json_decode((string) json_encode($response->toArray()), true) ?: [];

            if ($this->isPaginatedShape($array)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Semada data + links + meta uclusu var mi? (paginateJson imzasi)
     */
    private function isPaginatedShape(array $schema): bool
    {
        $properties = $schema['properties'] ?? null;

        if (is_array($properties) && isset($properties['data'], $properties['links'], $properties['meta'])) {
            return true;
        }

        foreach ($schema as $value) {
            if (is_array($value) && $this->isPaginatedShape($value)) {
                return true;
            }
        }

        return false;
    }
}
