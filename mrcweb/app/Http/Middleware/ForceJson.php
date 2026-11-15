<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * /api/* isteklerini her zaman JSON bekleyen istemciler gibi işler.
 * API misafirlerinde 401 JSON cevabını garanti eder (desktop/Web SPA).
 */
class ForceJson
{
    public function handle(Request $request, Closure $next)
    {
        if (str_starts_with($request->getPathInfo(), '/api')) {
            $request->headers->set('Accept', 'application/json');
        }

        return $next($request);
    }
}
