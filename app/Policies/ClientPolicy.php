<?php

namespace App\Policies;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\User;

class ClientPolicy
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
    public function view(User $user, Client $client): bool
    {
        return $user->isAdmin() || $client->assigned_to === $user->id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Client $client): bool
    {
        return $user->isAdmin() || $client->assigned_to === $user->id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Client $client): bool
    {
        $hasLockedInvoices = $client->invoices()
            ->where('status', '!=', InvoiceStatus::DRAFT)
            ->where('status', '!=', InvoiceStatus::DRAFT->value)
            ->exists();

        if ($hasLockedInvoices) {
            return false;
        }

        return $user->isAdmin() || $client->assigned_to === $user->id;
    }

    /**
     * Determine whether the user can reassign the client.
     */
    public function reassign(User $user, Client $client): bool
    {
        return $user->isAdmin();
    }
}
