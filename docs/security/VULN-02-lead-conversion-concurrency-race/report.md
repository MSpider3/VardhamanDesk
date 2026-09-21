# Vulnerability Report: VULN-02 — Lead Conversion Concurrency Race Condition

- **Vulnerability ID**: VULN-02
- **Severity**: High (CVSS: 7.1 - `CVSS:3.1/AV:N/AC:H/PR:L/UI:R/S:U/C:N/I:H/A:L`)
- **Status**: Fixed in v1.1.0
- **Target Component**: `app/Services/ClientService.php` & `database/migrations/`
- **Assessed Release**: v1.0.0
- **Fixed Release**: v1.1.0

---

## Summary

In VardhamanDesk v1.0.0, converting a qualified lead into a client relied on non-atomic status checks without pessimistic locking or database uniqueness constraints on `clients.lead_id`. 

If a user double-clicked the conversion button or two sales representatives submitted conversion requests simultaneously, parallel requests bypassed the application-level check (`$lead->status !== LeadStatus::QUALIFIED`). This resulted in multiple `Client` records created from a single `Lead`, duplicating notes, client records, and corrupting CRM pipeline attribution.

---

## Technical Details & Attack Path

### The Vulnerable Code (`app/Services/ClientService.php` in v1.0.0)
```php
public function convertLeadToClient(Lead $lead, array $clientData, User $actingUser): Client
{
    if ($lead->status !== LeadStatus::QUALIFIED) {
        throw new DomainException('Only qualified leads can be converted to clients.');
    }

    return DB::transaction(function () use ($lead, $clientData, $actingUser) {
        $lead->status = LeadStatus::CONVERTED;
        $lead->save();

        return Client::create([
            'lead_id' => $lead->id,
            ...
        ]);
    });
}
```

### Exploit Scenario
1. Alice (Sales Representative 1) is viewing Lead #12 (`Qualified`).
2. Due to network latency or double-submission, Request A and Request B hit the web server milliseconds apart.
3. Both Request A and Request B execute `if ($lead->status !== LeadStatus::QUALIFIED)`. Since neither transaction has committed, both threads evaluate this condition as `true`.
4. Both threads enter `DB::transaction()`. Thread A inserts Client #101 with `lead_id = 12`. Thread B inserts Client #102 with `lead_id = 12`.
5. Both transactions commit successfully.
6. Two separate client accounts now exist for the same lead, splitting invoice history and duplicating communications.

---

## Remediation & Defense in Depth

To remediate this systematically, we implemented two independent protective layers:

1. **Database-Level Hard Constraint**:
   Created migration `database/migrations/2026_09_21_140000_add_unique_lead_id_to_clients_table.php` adding a unique index on `clients.lead_id`:
   ```php
   $table->unique('lead_id');
   ```
2. **Pessimistic Row Locking (`SELECT ... FOR UPDATE`)**:
   Updated `ClientService::convertLeadToClient` to execute inside a database transaction, acquire an exclusive lock on the `Lead` row (`Lead::where('id', $lead->id)->lockForUpdate()->firstOrFail()`), re-verify status under lock, mark the lead converted immediately, and catch database duplicate key violations translating them into domain exceptions.

---

## Test Verification

Automated regression coverage added in `tests/Feature/LeadConversionConcurrencyTest.php`:
- `test('concurrent conversion attempts for the same qualified lead create exactly one client and reject the second attempt')`
- `test('clients table enforces unique constraint on lead_id at database level')`

Verified passing on both SQLite and MySQL 8.
