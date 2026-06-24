<?php

namespace App\Actions\Ingredients;

use Illuminate\Support\Str;

class NormalizeIngredientName
{
    /**
     * Prepare an ingredient name for display and uniqueness comparisons.
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
