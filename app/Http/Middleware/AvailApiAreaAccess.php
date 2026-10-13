<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Avail: the API counterpart of AvailAreaAccess. Runs after api.token.team, so the team is the token's.
 */
class AvailApiAreaAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $level = availAreaForApiPath($request->path());
        if ($level === null) {
            return $next($request);
        }

        $user = $request->user();
        $teamId = $user?->currentAccessToken()?->team_id;
        if (! availMeetsArea($user, $level, $teamId === null ? null : (int) $teamId)) {
            return response()->json(['message' => $level === 'admin'
                ? 'Only admins and the owner can use this endpoint.'
                : 'Only the owner can use this endpoint.'], 403);
        }

        return $next($request);
    }
}
