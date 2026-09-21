# Vulnerability Report: VULN-08 — Soft-Deleted Client Unique Constraint Dead-End on Lead Re-conversion

- **Vulnerability ID**: VULN-08
- **Severity**: Low / State Machine Defect (CVSS: 3.1 - `CVSS:3.1/AV:N/AC:H/PR:L/UI:N/S:U/C:N/I:N/A:L`)
- **Status**: Fixed in v1.1.0
- **Target Component**: `app/Services/ClientService.php`
- **Assessed Release**: v1.0.1
- **Fixed Release**: v1.1.0

---

## Summary

In VardhamanDesk v1.0.1, the unique index on `clients.lead_id` introduced to prevent race conditions during lead conversion did not account for Eloquent `SoftDeletes`. If an invoice-free client was soft-deleted by an administrator or sales user, the lead remained in its terminal `Converted` state (`LeadStatus::CONVERTED`). Re-attempting conversion or attempting to recreate the client resulted in an unhandled domain exception or a raw database `QueryException` (duplicate entry for unique key `clients_lead_id_unique`), leaving the lead permanently locked in an orphaned state.

---

## Root Cause Analysis

In `app/Models/Lead.php`, once a lead is converted, changing its status back to `QUALIFIED` or another state is prohibited by Eloquent model lifecycle hooks:
```php
if ($lead->isDirty('status') && $originalStatus === LeadStatus::CONVERTED) {
    throw new DomainException('A converted lead cannot be transitioned to any other status.');
}
```

Meanwhile, `app/Services/ClientService.php` queried active clients using standard global scopes (`Client::where('lead_id', $lead->id)->first()`), which excluded soft-deleted records. When attempting to insert a replacement client row into `clients`, the MySQL database engine rejected the query because MySQL's unique constraint on `lead_id` includes soft-deleted rows (`deleted_at IS NOT NULL`).

---

## Proof of Concept & Reproduction

1. Convert a qualified lead to a client:
   ```php
   $client = $clientService->convertLeadToClient($lead, $data, $salesUser);
   ```
2. Soft-delete the newly created client:
   ```php
   $client->delete();
   ```
3. Attempt to re-convert or re-provision the lead:
   ```php
   $clientService->convertLeadToClient($lead, $updatedData, $salesUser);
   ```
4. **Observed Output** (Before Fix):
   Throws `DomainException: This lead has already been converted.` or crashes with SQL unique constraint violation if bypassed, leaving the lead unusable with no active client.

---

## Remediation

In `app/Services/ClientService.php`, updated `convertLeadToClient()` to query including trashed records (`withoutGlobalScopes()->withTrashed()`):
```php
$existingClient = Client::withoutGlobalScopes()
    ->withTrashed()
    ->where('lead_id', $lead->id)
    ->first();

if ($existingClient && ! $existingClient->trashed()) {
    throw new DomainException('This lead has already been converted.');
}

if ($existingClient && $existingClient->trashed()) {
    $existingClient->restore();
    $existingClient->update($clientData);
    $existingClient->syncLeadNotesFromSourceLead();

    return $existingClient;
}
```

If a soft-deleted client exists for the lead, the service cleanly restores the existing client, updates its fields with the incoming data, synchronizes notes from the source lead, and returns the restored client instance.

---

## Test Verification

Automated regression coverage added in `tests/Feature/LeadConversionConcurrencyTest.php`:
- `test('Item 4 (Second-Pass): converting lead whose prior client was soft-deleted cleanly restores client without dead-end or unique constraint crash')`:
  Verifies that soft-deleting an invoice-free client followed by re-conversion restores the client, updates attributes, synchronizes notes, and maintains exactly 1 client record.

Verified passing on both SQLite and MySQL 8.
