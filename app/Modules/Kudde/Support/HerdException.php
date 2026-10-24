<?php

namespace App\Modules\Kudde\Support;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A request the herd refuses: an unknown tag (404), a tag already in use (409), a male asked to calve (422).
 * Thrown before anything is written, so the caller's transaction rolls back whole. The message is what the
 * phone shows.
 */
class HerdException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }

    public static function noAnimal(string $tag): self
    {
        return new self(__('kudde::ui.no_animal', ['tag' => $tag]), 404);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], $this->status);
    }
}
