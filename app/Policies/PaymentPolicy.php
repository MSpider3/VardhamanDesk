<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Payment $payment): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $client = $payment->invoice?->client;

        return $client && $client->assigned_to === $user->id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Payments are an immutable financial ledger; update is never permitted.
     */
    public function update(User $user, Payment $payment): bool
    {
        return false;
    }

    /**
     * Payments are an immutable financial ledger; delete is never permitted.
     */
    public function delete(User $user, Payment $payment): bool
    {
        return false;
    }
}
