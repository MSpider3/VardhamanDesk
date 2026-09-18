<?php

namespace App\Policies;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\User;

class InvoicePolicy
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
    public function view(User $user, Invoice $invoice): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $client = $invoice->client;

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
     * Determine whether the user can update the model.
     */
    public function update(User $user, Invoice $invoice): bool
    {
        // Locked invoices (sent, partially paid, paid) can NEVER have line items or financials updated
        $isDraft = $invoice->status instanceof InvoiceStatus
            ? $invoice->status === InvoiceStatus::DRAFT
            : $invoice->status === InvoiceStatus::DRAFT->value;

        if (! $isDraft) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        $client = $invoice->client;

        return $client && $client->assigned_to === $user->id;
    }

    /**
     * Determine whether the user can delete the model.
     * Sent/Paid invoices can NEVER be deleted by anyone, including Admin.
     */
    public function delete(User $user, Invoice $invoice): bool
    {
        $isDraft = $invoice->status instanceof InvoiceStatus
            ? $invoice->status === InvoiceStatus::DRAFT
            : $invoice->status === InvoiceStatus::DRAFT->value;

        if (! $isDraft) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        $client = $invoice->client;

        return $client && $client->assigned_to === $user->id;
    }

    /**
     * Determine whether the user can send the invoice.
     */
    public function send(User $user, Invoice $invoice): bool
    {
        return $this->update($user, $invoice);
    }

    /**
     * Determine whether the user can record payments against the invoice.
     */
    public function recordPayment(User $user, Invoice $invoice): bool
    {
        $isSent = $invoice->status === InvoiceStatus::SENT || $invoice->status === InvoiceStatus::SENT->value;
        $isPartiallyPaid = $invoice->status === InvoiceStatus::PARTIALLY_PAID || $invoice->status === InvoiceStatus::PARTIALLY_PAID->value;

        if (! $isSent && ! $isPartiallyPaid) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        $client = $invoice->client;

        return $client && $client->assigned_to === $user->id;
    }
}
