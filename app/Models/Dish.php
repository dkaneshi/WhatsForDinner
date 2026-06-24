<?php

namespace App\Models;

use App\ProteinCategory;
use Database\Factories\DishFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $family_id
 * @property string $name
 * @property string $normalized_name
 * @property string|null $note
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Ingredient> $mainProtein
 */
#[Fillable(['family_id', 'name', 'normalized_name', 'note', 'archived_at'])]
class Dish extends Model
{
    /** @use HasFactory<DishFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Family, $this> */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /** @return BelongsToMany<Ingredient, $this> */
    public function ingredients(): BelongsToMany
    {
        return $this->belongsToMany(Ingredient::class)
            ->withPivot('is_main_protein')
            ->withTimestamps();
    }

    /** @return BelongsToMany<Ingredient, $this> */
    public function mainProtein(): BelongsToMany
    {
        return $this->ingredients()->wherePivot('is_main_protein', true);
    }

    public function proteinCategory(): ?ProteinCategory
    {
        $mainProtein = $this->relationLoaded('mainProtein')
            ? $this->mainProtein->first()
            : $this->mainProtein()->first();

        return $mainProtein?->protein_category;
    }

    public function isArchived(): bool
    {
        return ! is_null($this->archived_at);
    }

    /** @param  Builder<Dish>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /** @param  Builder<Dish>  $query */
    public function scopeArchived(Builder $query): void
    {
        $query->whereNotNull('archived_at');
    }
}
