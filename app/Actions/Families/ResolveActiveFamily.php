<?php

namespace App\Actions\Families;

use App\Models\Family;
use App\Models\User;

class ResolveActiveFamily
{
    /**
     * Resolve the selected family, falling back to the user's first membership.
     */
    public function execute(User $user): ?Family
    {
        $currentFamily = $user->currentFamily;

        if ($currentFamily && $user->families()->whereKey($currentFamily->id)->exists()) {
            return $currentFamily;
        }

        $fallbackFamily = $user->families()->oldest('families.id')->first();

        if ($user->current_family_id !== $fallbackFamily?->id) {
            $user->forceFill(['current_family_id' => $fallbackFamily?->id])->save();
        }

        $user->setRelation('currentFamily', $fallbackFamily);

        return $fallbackFamily;
    }
}
