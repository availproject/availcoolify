<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Avail: refuses pages reserved for the owner or for admins (availAreaRoutes()). Registered as Livewire
 * persistent middleware too, so actions sent from such a page are checked against the page's route.
 */
class AvailAreaAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $level = availAreaForRoute($request->route()?->getName());
        if ($level !== null && $request->user() && ! availMeetsArea($request->user(), $level)) {
            abort(403, $level === 'admin'
                ? 'Only admins and the owner can open this page.'
                : 'Only the owner can open this page.');
        }

        return $next($request);
    }
}
