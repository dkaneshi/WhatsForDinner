<?php

namespace App\Models;

use Database\Factories\GroceryListFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $weekly_plan_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['weekly_plan_id'])]
class GroceryList extends Model
{
    /** @use HasFactory<GroceryListFactory> */
    use HasFactory;

    /** @return BelongsTo<WeeklyPlan, $this> */
    public function weeklyPlan(): BelongsTo
    {
        return $this->belongsTo(WeeklyPlan::class);
    }

    /** @return HasMany<GroceryListItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(GroceryListItem::class);
    }
}
