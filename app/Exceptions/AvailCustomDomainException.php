<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Avail: a custom domain was refused (only Git-based apps in production may have one). Thrown by the
 * model checks that back up every place a domain can be set; the API answers 422.
 */
class AvailCustomDomainException extends RuntimeException
{
    public function render(Request $request): ?JsonResponse
    {
        if ($request->is('api/*')) {
            return response()->json([
                'message' => $this->getMessage(),
                'errors' => ['domains' => [$this->getMessage()]],
            ], 422);
        }

        return null;
    }
}
