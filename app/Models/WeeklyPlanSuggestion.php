<?php

namespace App\Models;

use Database\Factories\WeeklyPlanSuggestionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $weekly_plan_id
 * @property int $dish_id
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['weekly_plan_id', 'dish_id', 'position'])]
class WeeklyPlanSuggestion extends Model
{
    /** @use HasFactory<WeeklyPlanSuggestionFactory> */
    use HasFactory;

    /** @return BelongsTo<WeeklyPlan, $this> */
    public function weeklyPlan(): BelongsTo
    {
        return $this->belongsTo(WeeklyPlan::class);
    }

    /** @return BelongsTo<Dish, $this> */
    public function dish(): BelongsTo
    {
        return $this->belongsTo(Dish::class);
    }
}
