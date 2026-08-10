<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSeller
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->canSell()) {
            return response()->json(['message' => 'Seller access is required.'], 403);
        }

        return $next($request);
    }
}
