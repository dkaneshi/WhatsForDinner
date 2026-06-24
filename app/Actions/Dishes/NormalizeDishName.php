<?php

namespace App\Actions\Dishes;

use Illuminate\Support\Str;

class NormalizeDishName
{
    /**
     * Prepare a dish name for display and uniqueness comparisons.
     *
     * @return array{name: string, normalized_name: string}
     */
    public function execute(string $name): array
    {
        $displayName = Str::squish($name);

        return [
            'name' => $displayName,
            'normalized_name' => Str::lower($displayName),
        ];
    }
}
