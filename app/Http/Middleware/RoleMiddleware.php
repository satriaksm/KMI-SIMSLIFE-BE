<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string $roles): Response
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Support "admin|manager" atau "admin,manager"
        $required = preg_split('/[|,]/', $roles) ?: [];
        $required = array_values(array_filter(array_map('trim', $required)));

        // Cek role tanpa dependency ke hasAnyRole()
        $hasRole = $user->roles()->whereIn('name', $required)->exists();
        if (!$hasRole) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return $next($request);
    }
}
