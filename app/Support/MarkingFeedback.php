<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class MarkingFeedback
{
    public const DETAIL = '[[add detail]]';

    public static function requireSpecificDetail(?string $feedback): void
    {
        if (stripos($feedback ?? '', self::DETAIL) !== false) {
            throw ValidationException::withMessages([
                'feedback' => 'Replace [[add detail]] with the specific feedback for this apprentice.',
            ]);
        }
    }
}
