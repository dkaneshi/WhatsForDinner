<?php

namespace App\Models;

use Database\Factories\GroceryListItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $grocery_list_id
 * @property string $name
 * @property string $normalized_name
 * @property bool $is_checked
 * @property bool $is_manual
 * @property bool $is_suppressed
 * @property array<int, int>|null $source_entry_ids
 * @property array<int, string>|null $source_labels
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['grocery_list_id', 'name', 'normalized_name', 'is_checked', 'is_manual', 'is_suppressed', 'source_entry_ids', 'source_labels'])]
class GroceryListItem extends Model
{
    /** @use HasFactory<GroceryListItemFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_checked' => 'boolean',
            'is_manual' => 'boolean',
            'is_suppressed' => 'boolean',
            'source_entry_ids' => 'array',
            'source_labels' => 'array',
        ];
    }

    /** @return BelongsTo<GroceryList, $this> */
    public function groceryList(): BelongsTo
    {
        return $this->belongsTo(GroceryList::class);
    }
}
