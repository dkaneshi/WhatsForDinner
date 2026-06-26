<?php

namespace App\Models;

use App\WeeklyPlanEntrySlot;
use App\WeeklyPlanSpecialEntry;
use Database\Factories\WeeklyPlanEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $weekly_plan_id
 * @property int|null $dish_id
 * @property WeeklyPlanSpecialEntry|null $special_entry
 * @property int $weekday
 * @property WeeklyPlanEntrySlot $slot
 * @property bool $is_leftovers
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['weekly_plan_id', 'dish_id', 'special_entry', 'weekday', 'slot', 'is_leftovers'])]
class WeeklyPlanEntry extends Model
{
    /** @use HasFactory<WeeklyPlanEntryFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'slot' => WeeklyPlanEntrySlot::class,
            'special_entry' => WeeklyPlanSpecialEntry::class,
            'is_leftovers' => 'boolean',
        ];
    }

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

    public function label(): string
    {
        if ($this->special_entry instanceof WeeklyPlanSpecialEntry) {
            return $this->special_entry->label();
        }

        if ($this->is_leftovers) {
            return __('Leftovers: :dish', ['dish' => $this->dish?->name]);
        }

        return $this->dish?->name ?? __('Unscheduled');
    }
}
