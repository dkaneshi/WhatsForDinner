<?php

namespace App\Models;

use App\WeeklyPlanEntrySlot;
use Database\Factories\WeeklyPlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $family_id
 * @property Carbon $week_start_date
 * @property bool $includes_weekend
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['family_id', 'week_start_date', 'includes_weekend'])]
class WeeklyPlan extends Model
{
    /**
     * Sunday-first weekday ordering for a seven-day, weekend-enabled week.
     */
    public const WEEKEND_WEEKDAY_ORDER = [7, 1, 2, 3, 4, 5, 6];

    /**
     * Sunday-first weekday ordering for a legacy five-day week.
     */
    public const LEGACY_WEEKDAY_ORDER = [1, 2, 3, 4, 5];

    /** @use HasFactory<WeeklyPlanFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'week_start_date' => 'date',
            'includes_weekend' => 'boolean',
        ];
    }

    /**
     * Sunday-first weekday numbers covered by this plan.
     *
     * @return list<int>
     */
    public function orderedWeekdays(): array
    {
        return $this->includes_weekend
            ? self::WEEKEND_WEEKDAY_ORDER
            : self::LEGACY_WEEKDAY_ORDER;
    }

    /** @return BelongsTo<Family, $this> */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /** @return HasMany<WeeklyPlanSuggestion, $this> */
    public function suggestions(): HasMany
    {
        return $this->hasMany(WeeklyPlanSuggestion::class)->orderBy('position');
    }

    /** @return HasMany<WeeklyPlanEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(WeeklyPlanEntry::class);
    }

    /** @return HasOne<GroceryList, $this> */
    public function groceryList(): HasOne
    {
        return $this->hasOne(GroceryList::class);
    }

    public function isComplete(): bool
    {
        return $this->entries()
            ->where('slot', WeeklyPlanEntrySlot::Main)
            ->whereBetween('weekday', [1, 5])
            ->distinct('weekday')
            ->count('weekday') === 5;
    }
}
