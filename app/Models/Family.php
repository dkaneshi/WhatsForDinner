<?php

namespace App\Models;

use Database\Factories\FamilyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $timezone
 * @property int $head_user_id
 * @property int|null $pending_head_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'timezone', 'head_user_id', 'pending_head_user_id'])]
class Family extends Model
{
    /** @use HasFactory<FamilyFactory> */
    use HasFactory;

    /**
     * Get the Head of the family.
     *
     * @return BelongsTo<User, $this>
     */
    public function head(): BelongsTo
    {
        return $this->belongsTo(User::class, 'head_user_id');
    }

    /**
     * Get the member who has been offered the Head role.
     *
     * @return BelongsTo<User, $this>
     */
    public function pendingHead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pending_head_user_id');
    }

    /**
     * Get the family's members.
     *
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('onboarding_checklist_dismissed_at')
            ->withTimestamps();
    }

    /**
     * Get invitations issued for this family.
     *
     * @return HasMany<FamilyInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(FamilyInvitation::class);
    }

    /** @return HasMany<Ingredient, $this> */
    public function ingredients(): HasMany
    {
        return $this->hasMany(Ingredient::class);
    }

    /** @return HasMany<Dish, $this> */
    public function dishes(): HasMany
    {
        return $this->hasMany(Dish::class);
    }

    /** @return HasMany<WeeklyPlan, $this> */
    public function weeklyPlans(): HasMany
    {
        return $this->hasMany(WeeklyPlan::class);
    }

    /**
     * Determine whether the user is the Head of the family.
     */
    public function isHead(User $user): bool
    {
        return $this->head_user_id === $user->id;
    }
}
