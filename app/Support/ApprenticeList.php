<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class ApprenticeList
{
    public static function filters(array $input): array
    {
        $values = Validator::make($input, [
            'search' => ['nullable', 'string', 'max:100'],
            'group' => ['nullable', 'integer', 'min:1'],
            'programme' => ['nullable', 'integer', 'min:1'],
            'tutor' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ])->validate();
        $values['search'] = trim($values['search'] ?? '');

        return array_filter($values, fn ($value, $key) =>
            $value !== null && $value !== '' && ($key !== 'page' || (int) $value > 1), ARRAY_FILTER_USE_BOTH);
    }

    public static function returnUrl(Request $request): ?string
    {
        if (!$request->filled('list')) {
            return null;
        }

        $list = $request->validate(['list' => ['array']])['list'];

        return route('apprentices.index', self::filters($list));
    }
}
