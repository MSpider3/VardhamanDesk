# Vulnerability Report: VULN-04 — Cross-User Lead Conversion IDOR via Direct Service Invocation

- **Vulnerability ID**: VULN-04
- **Severity**: High (CVSS: 7.7 - `CVSS:3.1/AV:N/AC:L/PR:L/UI:N/S:U/C:N/I:H/A:L`)
- **Status**: Fixed in v1.1.0
- **Target Component**: `app/Services/ClientService.php`
- **Assessed Release**: v1.0.0
- **Fixed Release**: v1.1.0

---

## Summary

In VardhamanDesk v1.0.0, `ClientService::convertLeadToClient()` accepted an `$actingUser` argument but did not verify whether a sales representative had authorization to convert leads assigned to another sales representative. 

If invoked directly via API, internal command, or forged Livewire call, a sales representative could convert arbitrary leads belonging to other sales representatives into clients, hijacking customer accounts and pipeline attribution.

---

## Technical Details

### Actors
- **Alice**: Legitimate Sales Representative (`sales1`, user ID `2`) who owns Lead #42 (`Qualified`).
- **Mallory**: Malicious or competing Sales Representative (`sales2`, user ID `3`).
- **Admin**: System Administrator (user ID `1`).

### Flaw Mechanism
While the Filament UI conditionally hid the action button on tables based on policies, the core domain service `ClientService::convertLeadToClient()` only inspected lead status (`if ($lead->status !== LeadStatus::QUALIFIED)`). It failed to enforce:
```php
if ($actingUser->isSales() && $lead->assigned_to !== $actingUser->id) {
    throw new AuthorizationException(...);
}
```
As a result, Mallory could invoke `convertLeadToClient(Lead #42, ..., Mallory)` and convert Alice's lead without permission.

---

## Remediation

In `app/Services/ClientService.php`, added strict ownership validation both prior to transaction entry and immediately after acquiring the pessimistic row lock:

```php
if ($actingUser->isSales() && $lead->assigned_to !== $actingUser->id) {
    throw new \Illuminate\Auth\Access\AuthorizationException('You are not authorized to convert this lead.');
}

// Inside the locked transaction:
if ($actingUser->isSales() && $lockedLead->assigned_to !== $actingUser->id) {
    throw new \Illuminate\Auth\Access\AuthorizationException('You are not authorized to convert this lead.');
}
```

Admins retain global authority to convert leads on behalf of any sales representative.

---

## Test Verification

Automated regression coverage added in `tests/Feature/SecurityHardeningTest.php`:
- `test('VULN-06: sales user cannot convert a lead assigned to another sales user (IDOR)')`

Verified that sales representatives are forbidden with `AuthorizationException`, while Admins successfully convert leads across any owner. Verified on both SQLite and MySQL 8.
