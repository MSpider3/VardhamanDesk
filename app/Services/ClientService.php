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

        if ($currentStatus === LeadStatus::CONVERTED || $lead->client()->exists()) {
            throw new DomainException('This lead has already been converted.');
        }

        if ($currentStatus !== LeadStatus::QUALIFIED) {
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

                $lockedStatus = $lockedLead->status instanceof LeadStatus
                    ? $lockedLead->status
                    : LeadStatus::tryFrom($lockedLead->status);

                if ($lockedLead->client()->exists() || $lockedStatus === LeadStatus::CONVERTED) {
                    throw new DomainException('This lead has already been converted.');
                }

                if ($lockedStatus !== LeadStatus::QUALIFIED) {
                    throw new DomainException('Only qualified leads can be converted to clients.');
                }

                // Mark lead converted under the lock so concurrent readers immediately see CONVERTED status
                $lockedLead->status = LeadStatus::CONVERTED;
                $lockedLead->save();

                $resolvedState = IndianState::fromCodeOrName($state)?->value ?? $state;

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
