<?php

namespace App\Policies;

use App\Models\CompanySetting;
use App\Models\User;

class CompanySettingPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, CompanySetting $companySetting): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can create models (singleton: disallowed).
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, CompanySetting $companySetting): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can delete the model (singleton: disallowed).
     */
    public function delete(User $user, CompanySetting $companySetting): bool
    {
        return false;
    }
}
