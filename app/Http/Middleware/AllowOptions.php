<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AllowOptions
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->getMethod() === 'OPTIONS') {
            return response()->json([], 200);
        }

        return $next($request);
    }
}
