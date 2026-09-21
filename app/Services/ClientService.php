<?php

namespace App\Services;

use App\Enums\IndianState;
use App\Enums\LeadStatus;
use App\Models\Client;
use App\Models\Lead;
use App\Models\User;
use App\Rules\ValidGstin;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClientService
{
    /**
     * Convert a Qualified lead into a Client.
     *
     * @param  array<string, mixed>  $clientData
     */
    public function convertLeadToClient(Lead $lead, array $clientData, User $actingUser): Client
    {
        if ($actingUser->isSales() && $lead->assigned_to !== $actingUser->id) {
            throw new AuthorizationException('You are not authorized to convert this lead.');
        }

        $currentStatus = $lead->status instanceof LeadStatus
            ? $lead->status
            : LeadStatus::tryFrom($lead->status);

        $existingClient = Client::withoutGlobalScopes()
            ->withTrashed()
            ->where('lead_id', $lead->id)
            ->first();

        // If an active (non-deleted) client already exists, reject
        if ($existingClient && ! $existingClient->trashed()) {
            throw new DomainException('This lead has already been converted.');
        }

        if ($currentStatus === LeadStatus::CONVERTED && ! ($existingClient && $existingClient->trashed())) {
            throw new DomainException('This lead has already been converted.');
        }

        // If lead is not qualified AND has no soft-deleted client to restore, reject
        if ($currentStatus !== LeadStatus::QUALIFIED && ! ($existingClient && $existingClient->trashed())) {
            throw new DomainException('Only qualified leads can be converted to clients.');
        }

        $state = $clientData['state'] ?? null;
        $gstin = $clientData['gstin'] ?? null;

        if (! empty($gstin)) {
            $gstinErrors = ValidGstin::check($gstin, $state);
            if (! empty($gstinErrors)) {
                throw ValidationException::withMessages(['gstin' => $gstinErrors]);
            }
        }

        try {
            return DB::transaction(function () use ($lead, $clientData, $actingUser, $state, $gstin) {
                /** @var Lead $lockedLead */
                $lockedLead = Lead::where('id', $lead->id)->lockForUpdate()->firstOrFail();

                if ($actingUser->isSales() && $lockedLead->assigned_to !== $actingUser->id) {
                    throw new AuthorizationException('You are not authorized to convert this lead.');
                }

                $existingLockedClient = Client::withoutGlobalScopes()
                    ->withTrashed()
                    ->where('lead_id', $lockedLead->id)
                    ->lockForUpdate()
                    ->first();

                if ($existingLockedClient && ! $existingLockedClient->trashed()) {
                    throw new DomainException('This lead has already been converted.');
                }

                $lockedStatus = $lockedLead->status instanceof LeadStatus
                    ? $lockedLead->status
                    : LeadStatus::tryFrom($lockedLead->status);

                if ($lockedStatus === LeadStatus::CONVERTED && ! ($existingLockedClient && $existingLockedClient->trashed())) {
                    throw new DomainException('This lead has already been converted.');
                }

                if ($lockedStatus !== LeadStatus::QUALIFIED && ! ($existingLockedClient && $existingLockedClient->trashed())) {
                    throw new DomainException('Only qualified leads can be converted to clients.');
                }

                // Mark lead converted under the lock so concurrent readers immediately see CONVERTED status
                $lockedLead->status = LeadStatus::CONVERTED;
                $lockedLead->save();

                $resolvedState = IndianState::fromCodeOrName($state)?->value ?? $state;

                if ($existingLockedClient && $existingLockedClient->trashed()) {
                    $existingLockedClient->restore();
                    $existingLockedClient->update([
                        'assigned_to' => $lockedLead->assigned_to,
                        'name' => $clientData['name'] ?? $lockedLead->name,
                        'company' => $clientData['company'] ?? $lockedLead->company,
                        'phone' => $clientData['phone'] ?? $lockedLead->phone,
                        'email' => $clientData['email'] ?? $lockedLead->email,
                        'billing_address' => $clientData['billing_address'],
                        'state' => $resolvedState,
                        'gstin' => $gstin,
                    ]);

                    return $existingLockedClient;
                }

                $client = Client::create([
                    'lead_id' => $lockedLead->id,
                    'assigned_to' => $lockedLead->assigned_to,
                    'created_by' => $actingUser->id,
                    'name' => $clientData['name'] ?? $lockedLead->name,
                    'company' => $clientData['company'] ?? $lockedLead->company,
                    'phone' => $clientData['phone'] ?? $lockedLead->phone,
                    'email' => $clientData['email'] ?? $lockedLead->email,
                    'billing_address' => $clientData['billing_address'],
                    'state' => $resolvedState,
                    'gstin' => $gstin,
                ]);

                return $client;
            });
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'Duplicate entry') || str_contains($e->getMessage(), 'UNIQUE constraint failed') || $e->getCode() === '23000') {
                throw new DomainException('This lead has already been converted.', 0, $e);
            }

            throw $e;
        }
    }

    /**
     * Create a direct client with no originating lead.
     *
     * @param  array<string, mixed>  $data
     */
    public function createDirectClient(array $data, User $actingUser): Client
    {
        $state = $data['state'] ?? null;
        $gstin = $data['gstin'] ?? null;

        if (! empty($gstin)) {
            $gstinErrors = ValidGstin::check($gstin, $state);
            if (! empty($gstinErrors)) {
                throw ValidationException::withMessages(['gstin' => $gstinErrors]);
            }
        }

        $assignedTo = $actingUser->isSales()
            ? $actingUser->id
            : ($data['assigned_to'] ?? $actingUser->id);

        $resolvedState = IndianState::fromCodeOrName($state)?->value ?? $state;

        return Client::create([
            'lead_id' => null,
            'assigned_to' => $assignedTo,
            'created_by' => $actingUser->id,
            'name' => $data['name'],
            'company' => $data['company'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'billing_address' => $data['billing_address'],
            'state' => $resolvedState,
            'gstin' => $gstin,
        ]);
    }
}
