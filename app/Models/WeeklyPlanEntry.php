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
 * @property string|null $dish_snapshot_name
 * @property string|null $dish_snapshot_note
 * @property array<int, array{name: string, is_main_protein: bool}>|null $dish_snapshot_ingredients
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['weekly_plan_id', 'dish_id', 'special_entry', 'weekday', 'slot', 'is_leftovers', 'dish_snapshot_name', 'dish_snapshot_note', 'dish_snapshot_ingredients'])]
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
            'dish_snapshot_ingredients' => 'array',
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

        $dishName = $this->dish_snapshot_name ?? $this->dish->name ?? __('Unscheduled');

        if ($this->is_leftovers) {
            return __('Leftovers: :dish', ['dish' => $dishName]);
        }

        return $dishName;
    }

    /**
     * @return list<string>
     */
    public function ingredientNames(): array
    {
        if (is_array($this->dish_snapshot_ingredients)) {
            return array_values(array_filter(
                array_map(
                    fn (array $ingredient): string => $ingredient['name'],
                    $this->dish_snapshot_ingredients,
                ),
            ));
        }

        if (! $this->dish instanceof Dish) {
            return [];
        }

        return array_values($this->dish->ingredients
            ->sortBy('name')
            ->map(fn (Ingredient $ingredient): string => $ingredient->name)
            ->values()
            ->all());
    }
}
