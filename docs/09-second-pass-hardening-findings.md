# Second-Pass Audit — Findings on `ec7bb09` (Hardening Commit)

Verified by reading the actual diff against `docs/07-production-hardening-fix-request.md` and `docs/08-hardening-summary.md`'s claims — not a test run (no PHP/Composer/MySQL available in the review environment). Items 1–3, 5–7, 10 from the original brief are genuinely fixed and correctly implemented. This document covers what isn't.

## 1. `app:bootstrap` creates default credentials in "production-safe" mode — fix before anything else

`app/Console/Commands/AppBootstrapCommand.php`: with no `--password` flag and no `SEED_DEFAULT_PASSWORD` env var, the command creates a fully active (`is_active = true`), fully verified (`email_verified_at = now()`) Admin at `admin@vardhamandesk.local` with password literally `'password'`. This is the command the README will tell people to run on a fresh production box. `tests/Feature/AppBootstrapCommandTest.php` never asserts anything about the password, so this isn't caught.

**Required fix:** when `app()->isProduction()` (or more simply, always), require `--password` to be explicitly passed or `SEED_DEFAULT_PASSWORD` to be explicitly set to something other than the literal fallback `'password'` — fail the command with a clear error otherwise, rather than silently proceeding with a weak, publicly-documented default.

**Test:** running `app:bootstrap` with `APP_ENV=production` and no password source set must fail, not succeed with a default credential.

## 2. FY-backdating guard compares against the wrong reference point

`app/Services/InvoiceSendService.php::send()` rejects a Send when `strcmp($fy, FinancialYearCounter::max('financial_year')) < 0`. That compares against **whatever FY has already been used**, not against today's actual date. Consequence: one forward-dated invoice (deliberate or a data-entry mistake) permanently raises the floor, after which every subsequent invoice dated in the *real, still-open* current financial year is wrongly rejected as "backdating into a closed FY."

Their own test (`tests/Feature/InvoiceSendAndNumberingTest.php`, "Item 9") sends a 2026-27 invoice, then a rejected 2025-26 one, then a forward-dated 2027-28 one — and stops there. It never sends anything afterward, so it never notices the lockout it just created.

**Required fix:** compare against `InvoiceSendService::deriveFinancialYear(now())` (today's actual FY) instead of `FinancialYearCounter::max('financial_year')`. Reject only when the invoice's derived FY is earlier than today's real FY.

**Test:** extend the existing "Item 9" test with a fourth step — after the forward-dated 2027-28 send succeeds, send one more ordinary invoice dated in 2026-27 (today's real FY in the test's time frame) and assert it **still succeeds**. This is the exact case the current test misses.

## 3. The N+1 fix doesn't cover invoices with zero payments

`InvoicesTable.php`'s `withSum('payments as paid_amount', 'amount')` returns SQL `NULL` for an invoice with no payment rows — standard `SUM()` behavior, not automatically coalesced to `0`. `Invoice::getPaidAmountAttribute()` guards with `$this->attributes['paid_amount'] !== null`, so exactly when `paid_amount` is `NULL` (i.e., no payments yet — the normal state for a freshly Sent invoice), it falls through to the old `$this->payments()->sum('amount')` live query. The claimed O(1) scaling only holds for a page of invoices that all already have at least one payment.

`tests/Feature/InvoiceListQueryTest.php`'s "Item 4" test gives every single one of the 5 and 50 seeded invoices a `Payment::factory()->create(...)` — so it never exercises the no-payment path and passes while the regression is still there for the common case.

**Required fix:** wrap the `withSum` aggregate (or the `paid_amount` column read) in a `COALESCE(..., 0)` at the SQL level — either via `selectRaw` for `paid_amount` the same way `outstanding_amount` already does it, or by adjusting the accessor to treat a present-but-null key the same as `0` rather than falling through to a live query.

**Test:** rerun "Item 4" with a realistic mix — most invoices with zero payments, a few with some — and assert the query count is still constant. The current test's "give everything a payment" setup should be treated as a fixture bug, not left as-is.

## 4. Unique index on `clients.lead_id` doesn't account for soft deletes

`clients` uses `SoftDeletes`; `ClientPolicy::delete` permits deleting a client with no locked (non-Draft) invoices; `Lead`'s `Converted` status is enforced as permanently terminal (`Lead::booted()` throws if a Converted lead's status is changed). Put together: convert a lead → delete the resulting client while it still has no invoices (currently allowed) → the lead is now stuck forever — `Converted`, no client, and the plain unique index on `lead_id` blocks ever creating a replacement, even though the old client row is only soft-deleted, not gone.

**Required fix:** either a unique index scoped to exclude soft-deleted rows (e.g. a generated/computed column workaround for MySQL, since MySQL doesn't support Postgres-style partial unique indexes directly), or add an explicit "reconvert" path that's aware of a soft-deleted prior client for the same lead, or reconsider whether deleting a converted lead's only client should be allowed at all given this dead end.

**Test:** convert a lead, delete the resulting (invoice-free) client, then attempt to convert the same lead again — assert either a clean, handled re-conversion path, or an explicit, well-messaged rejection rather than a raw unique-constraint `QueryException`.

## 5. Minor: counter-race fix always attempts-and-catches, even for FYs that already exist

`InvoiceSendService::send()` now unconditionally calls `FinancialYearCounter::create([...])` inside a try/catch on every Send, relying on catching the duplicate-key `QueryException` for the (overwhelmingly common) case where the counter row already exists. Correct, but wasteful — every ordinary invoice send now deliberately generates and swallows a duplicate-key exception, which is likely to show up as error noise in query logs, APM/error-tracking tools, or Laravel's own exception listeners unless something specifically filters it. Prefer checking existence first (a plain `where()->exists()` or `firstOrCreate` outside the lock, falling back to the try/catch only as the genuine race-guard for the rare case both checks miss).

Not a correctness bug — low priority, but worth cleaning up in the same pass as the others above.
