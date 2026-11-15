<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Someone else held or bought the piece first. A 409 conflict, not a
 * validation error: the request was fine, the piece just isn't for sale now.
 */
class PieceUnavailableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(__('This piece is no longer available.'));
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 409);
    }
}
