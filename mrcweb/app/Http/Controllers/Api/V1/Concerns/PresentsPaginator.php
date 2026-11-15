<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * API v1 pagination sözleşmesi (PLAN §6): liste cevapları
 * { data, links, meta{current_page,from,last_page,per_page,to,total} }.
 *
 * Laravel'in düz paginator serileştirmesi (total kök seviyede) yerine
 * meta bloğuyla normalleştirir; masaüstü istemci ve SPA aynı sözleşmeyi
 * kullanır. Testler data/meta yollarıyla sabitlenmiştir.
 */
trait PresentsPaginator
{
    protected function paginateJson(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => collect($paginator->items())->values(),
            'links' => $paginator->linkCollection(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'from' => $paginator->firstItem(),
                'last_page' => $paginator->lastPage(),
                'per_page' => (int) $paginator->perPage(),
                'to' => $paginator->lastItem(),
                'total' => $paginator->total(),
            ],
        ];
    }
}
