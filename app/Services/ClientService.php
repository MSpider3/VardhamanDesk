<?php

namespace App\Services;

use App\Enums\IndianState;
use App\Enums\LeadStatus;
use App\Models\Client;
use App\Models\Lead;
use App\Models\User;
use App\Rules\ValidGstin;
use DomainException;
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
        $currentStatus = $lead->status instanceof LeadStatus
            ? $lead->status
            : LeadStatus::tryFrom($lead->status);

        if ($currentStatus !== LeadStatus::QUALIFIED) {
            throw new DomainException('Only qualified leads can be converted to clients.');
        }

        if ($lead->client()->exists() || $currentStatus === LeadStatus::CONVERTED) {
            throw new DomainException('This lead has already been converted.');
        }

        $state = $clientData['state'] ?? null;
        $gstin = $clientData['gstin'] ?? null;

        if (! empty($gstin)) {
            $gstinErrors = ValidGstin::check($gstin, $state);
            if (! empty($gstinErrors)) {
                throw ValidationException::withMessages(['gstin' => $gstinErrors]);
            }
        }

        return DB::transaction(function () use ($lead, $clientData, $actingUser, $state, $gstin) {
            $resolvedState = IndianState::fromCodeOrName($state)?->value ?? $state;

            $client = Client::create([
                'lead_id' => $lead->id,
                'assigned_to' => $lead->assigned_to,
                'created_by' => $actingUser->id,
                'name' => $clientData['name'] ?? $lead->name,
                'company' => $clientData['company'] ?? $lead->company,
                'phone' => $clientData['phone'] ?? $lead->phone,
                'email' => $clientData['email'] ?? $lead->email,
                'billing_address' => $clientData['billing_address'],
                'state' => $resolvedState,
                'gstin' => $gstin,
            ]);

            $lead->status = LeadStatus::CONVERTED;
            $lead->save();

            return $client;
        });
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
