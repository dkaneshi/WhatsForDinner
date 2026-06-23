<?php

namespace App\Models;

use Database\Factories\FamilyInvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $family_id
 * @property int $invited_by_user_id
 * @property string $email
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $declined_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'family_id',
    'invited_by_user_id',
    'email',
    'token_hash',
    'expires_at',
    'accepted_at',
    'declined_at',
    'revoked_at',
])]
#[Hidden(['token_hash'])]
class FamilyInvitation extends Model
{
    /** @use HasFactory<FamilyInvitationFactory> */
    use HasFactory;

    /**
     * Get the family this invitation belongs to.
     *
     * @return BelongsTo<Family, $this>
     */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /**
     * Get the user who issued this invitation.
     *
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function isPending(): bool
    {
        return is_null($this->accepted_at)
            && is_null($this->declined_at)
            && is_null($this->revoked_at)
            && $this->expires_at->isFuture();
    }

    public function isFor(User $user): bool
    {
        return Str::lower($this->email) === Str::lower($user->email);
    }

    public static function findForToken(string $token): ?self
    {
        return self::query()
            ->where('token_hash', self::hashToken($token))
            ->first();
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'declined_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
