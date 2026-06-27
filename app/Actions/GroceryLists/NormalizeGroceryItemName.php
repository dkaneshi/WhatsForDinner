<?php

namespace App\Actions\GroceryLists;

use Illuminate\Support\Str;

class NormalizeGroceryItemName
{
    /**
     * Normalize grocery item names for duplicate detection.
     */
    public function execute(string $name): string
    {
        return Str::of($name)->squish()->lower()->toString();
    }
}
