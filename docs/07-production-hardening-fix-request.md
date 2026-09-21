# Production Hardening & Bug-Fix Brief — VardhamanDesk

Repo: `https://github.com/MSpider3/VardhamanDesk`. This is a work order for the AI coding agent (Antigravity), not a discussion document — every item below was verified against the actual repository (current `main`, commit `bc42b5c`), not inferred from the docs. Read `docs/AGENT.md` first; every rule in it still applies (small commits, explain every change, tests are not optional). This brief adds to, and in two places (§9, the CSP note) supersedes, what's written there.

## How to work through this

1. Fix items in the order listed — Part A first (client-reported, highest priority), then Part B (found in review).
2. **For every item: write a test that fails on the current code and passes after your fix, in the same commit as the fix.** Where an item already has partial test coverage, extend it — don't write a duplicate.
3. Run the full suite on **both** SQLite (existing default) and MySQL before considering an item done — see §11. Item #2 specifically will not reproduce on SQLite; don't trust a green SQLite run alone for it.
4. When you finish everything, write the summary described in §13 — don't skip it, and don't write it until everything else is done and tested.
5. If you hit a genuine ambiguity not resolved by this doc or `docs/05-assumptions-and-open-questions.md`, stop and ask — same rule as always.

---

## Part A — Client-reported issues

### 1. Fresh production install doesn't work

**Root cause, verified:** `DatabaseSeeder::run()` correctly refuses to run when `app()->isProduction()`, but there is *no other path* that creates a first Admin, GST rates, or company settings — those three things only exist inside that same refused seeder. Separately, and compounding it: `User::canAccessPanel()` requires `email_verified_at !== null`, but `php artisan make:filament-user` (Laravel/Filament's own documented bootstrap command) does not set `email_verified_at` on the user it creates. So even if someone manually runs that command against a fresh production database, the resulting user is created successfully, the command reports success, and that user still cannot log in. Two dead ends stacked on top of each other.

There is also **no Filament resource for `GstRate` or `CompanySetting` anywhere in the app** — confirmed, there's no `app/Filament/Resources/GstRates/` or `.../CompanySettings/` at all. So even once someone *can* log in, there's no in-app way to add GST rates or fill in company/bank details. Right now the only way to populate either table is direct DB access or `php artisan tinker`.

**Required fix:**
- Add a custom Artisan command (e.g. `php artisan app:bootstrap`) that is **safe to run in production** and idempotent (safe to re-run): creates the first Admin (prompting for or accepting name/email/password, or reading them from env vars like `SEED_DEFAULT_PASSWORD` already used elsewhere), setting `email_verified_at` and `is_active` correctly so the account can log in immediately; seeds the four `gst_rates` rows if the table is empty; seeds a placeholder `company_settings` row if none exists. Document it as the required first step in the README's production section (the README currently only documents `migrate:fresh --seed`, which is dev-only).
- Add minimal Filament resources for `GstRate` and `CompanySetting`, Admin-only (`viewAny`/`create`/`update` gated to `isAdmin()`, matching the pattern already used in `UserPolicy`), so an Admin can maintain both after go-live without shell access. `CompanySetting` is a singleton — the resource should behave like one (edit the existing row, not a list of creatable records; disallow deleting the only row).

**Test:** a feature test that runs the new command against an empty database (no seeder), then asserts: the created admin has `email_verified_at` set and `canAccessPanel()` returns true; `gst_rates` has exactly the four expected rows; `company_settings` has one row. Also a test that running the command twice doesn't duplicate the admin, rates, or settings row.

### 2. Invoices page crashes on MySQL

**Root cause, verified:** in `app/Filament/Resources/Invoices/Tables/InvoicesTable.php`, the `paid_amount` and `outstanding_amount` columns are marked `->sortable()`, but neither is a real column — both are PHP accessors on the `Invoice` model (`getPaidAmountAttribute()`, `getOutstandingAmountAttribute()`) computed from the `payments` relationship. Filament's `sortable()` pushes an `ORDER BY` clause straight to SQL using the column name; SQLite is lenient enough to not error the way MySQL's stricter column resolution does in this configuration, which is exactly why it slipped through — the existing tests only run on SQLite (see §11).

**Required fix:** stop treating these as sortable virtual columns. The clean fix is §4 below — once `paid_amount`/`outstanding_amount` are pulled into the main query as real aggregate expressions (not accessors), they become genuinely sortable and this bug and the N+1 bug share one fix.

**Test:** a Feature test running against the **MySQL** connection (see §11) that creates several invoices with varying payment states, sorts the Filament table by each of Paid and Balance (ascending and descending), and asserts no exception and correct ordering. This must be proven to fail against the current code on MySQL before the fix (it will pass "by accident" on SQLite, which is the whole point being made).

### 3. PHP version mismatch

**Root cause, verified:** `composer.json` declares `"php": "^8.3"`, but `composer.lock` has multiple Symfony 8.x components (`symfony/console`, `symfony/http-foundation`, `symfony/http-kernel`, `symfony/routing`, `symfony/mailer`, `symfony/process`, and others) pinned to versions requiring `"php": ">=8.4.1"`. `composer install --no-dev` (or any install honoring the lock file) will fail on a PHP 8.3 server with a platform requirement error.

**Required fix:** since the lock file is what's actually being run against, and it was generated correctly against real package constraints, **raise `composer.json`'s `require.php` to `^8.4`** to match reality, rather than downgrading the lock — downgrading would mean re-resolving a different dependency graph than what's been tested against throughout this project. Update the README's stated PHP requirement to match. Run `composer validate --strict` as part of this fix and fix anything else it flags.

**Test:** not a Pest test — add a CI step (or a documented manual step if no CI exists yet, see §11) that runs `composer check-platform-reqs` and fails the build if it reports a mismatch. Confirm it currently fails against `composer.json` on a PHP 8.3 runtime and passes after the version bump.

### 4. Invoice list gets slower with more data

**Root cause, verified:** confirmed by reading the same two accessors as in #2 — `getPaidAmountAttribute()` runs `$this->payments()->sum('amount')` (one query), and `getOutstandingAmountAttribute()` calls `(float) $this->paid_amount`, which **re-triggers the same accessor and re-runs the same query** rather than reusing the value. That's two `SUM` queries per row, per page render, neither cached, growing linearly with the number of invoices shown — matching the reported 31-for-5 / 53-for-50 pattern.

**Required fix:** compute `paid_amount` and `outstanding_amount` as SQL aggregates in the table's base query (`withSum('payments as paid_amount', 'amount')` or equivalent, with `outstanding_amount` derived as `total - paid_amount` either as a second aggregate/subquery or computed in PHP from the one query result — not from a second query). This also directly fixes #2, since a `withSum` alias is a real selected column and genuinely sortable. Update the two accessors on the `Invoice` model to prefer an already-loaded aggregate when present (so code elsewhere that calls `$invoice->paid_amount` on a single, non-list-loaded record still works without requiring callers to remember to eager-load).

**Test:** a test asserting the invoice list query count is constant (e.g. via `DB::enableQueryLog()` or `assertQueryCountLessThan`) regardless of whether 5 or 50 invoices are rendered — assert it's the same small number in both cases, not just "fewer than before." This test should fail against current code (query count scales with row count) and pass after.

### 5. A lead can be converted twice

**Root cause, verified:** `ClientService::convertLeadToClient()` checks `$lead->client()->exists()` *before* opening the `DB::transaction()`, with no row lock — a classic check-then-act race. Worse, there is **no unique constraint at all** on `clients.lead_id` in the migration (`database/migrations/2026_09_18_120004_create_clients_table.php`), so even a same-request retry or an app-level bug elsewhere has nothing stopping it at the database level. Two conversion requests arriving close together can both pass the existence check and both create a client for the same lead.

**Required fix, two layers (defense in depth, matching how the rest of this codebase is built):**
- **Database-level guard:** add a unique index on `clients.lead_id`. Since `lead_id` is nullable and the table is soft-deleted, use a conditional/partial unique index if your MySQL version and Laravel's schema builder support it cleanly (e.g. a unique index that only applies where `deleted_at IS NULL`), or, if that's not practical, enforce uniqueness via a `lockForUpdate()` on the `Lead` row inside the transaction *plus* a plain unique index on `lead_id` and let the second concurrent request fail on the DB constraint (catch the `QueryException`/unique-violation and translate it into the existing `DomainException` message, so the user-facing behavior doesn't change, just the safety under concurrency).
- **Application-level guard:** move the existence check inside the transaction, after acquiring `Lead::lockForUpdate()` on the specific lead row, so the second concurrent request blocks until the first commits and then sees the already-converted state.

**Test:** a test that fires two `convertLeadToClient()` calls concurrently (or simulates the race deterministically — e.g. start a transaction, assert a lock is held, attempt a second call and assert it's blocked/rejected) against the same Qualified lead, and asserts only one `Client` row ever exists for that lead. This is exactly the kind of test that's easy to write in a way that doesn't actually exercise the race — make sure it genuinely fails against current code, not just re-tests the existing (already-passing) sequential check.

---

## Part B — Additional issues found in review

### 6. The financial-year counter has the same race-condition shape as #5

**Found while reviewing `InvoiceSendService::send()`.** The very first invoice sent in a brand-new financial year calls `FinancialYearCounter::firstOrCreate(['financial_year' => $fy], ['last_sequence' => 0])` *before* the subsequent `lockForUpdate()` query. `firstOrCreate` is select-then-insert, not atomic. If two Send actions race on the very first invoice of a new FY (realistically: the first business day of April, or the first invoice ever), both can pass the "not found" check and both attempt to insert the same primary key (`financial_year`), producing a duplicate-key exception or a deadlock instead of a clean sequential allocation. This is the same underlying bug pattern as #5, just on a different table — worth fixing with the same lens rather than treating it as unrelated.

**Required fix:** replace `firstOrCreate` + separate locked `where()` with a single atomic upsert (e.g. `INSERT ... ON DUPLICATE KEY UPDATE` via `DB::statement` or Laravel's `upsert()`, or catch the unique-violation from a plain `create()` and fall through to the locked read) so counter bootstrap for a new FY can't race the same way.

**Test:** a test that simulates two concurrent Send actions where the target financial year has no existing counter row, and asserts both succeed with sequential, non-colliding numbers rather than one throwing.

### 7. No way to manage GST rates or company settings after go-live

Already covered as part of the fix for #1 (add Admin-only Filament resources for `GstRate` and `CompanySetting`), but calling it out separately because it's not just a bootstrap problem — it's an ongoing operability gap. The GST Council has changed the slab schedule before (see `docs/03-gst-and-invoicing-rules.md`) and can again; right now, responding to that requires direct database access, which is exactly the kind of thing this app should let an Admin do safely. Make sure the fix for #1 includes this as a genuine CRUD resource, not just a one-time seed.

### 8. AI chat history is a curated summary, not actual logs

`docs/ai-history/INDEX.md` and the three milestone files under `docs/ai-history/` are a written narrative summary of what was built and why (commit references, test counts, architectural principles) — not transcripts of the actual AI conversations. The client explicitly asked for the real chat logs, not the summary. Export the actual conversation history (from whatever tool was used to build this — Antigravity's own session/export mechanism, if it has one) and commit it, keeping the existing summary docs as a helpful index *on top of*, not instead of, the raw logs. If the tool has no export feature, say so plainly in the final summary (§13) rather than silently leaving this unresolved.

### 9. Invoices can be backdated into an already-closed financial year — recommendation

Confirmed: `invoice_date` on a Draft is a free-form date picker with no minimum-date restriction (`app/Filament/Resources/Invoices/Schemas/InvoiceForm.php`), and `InvoiceSendService::deriveFinancialYear()` derives the allocated FY straight from that user-editable date at Send time. Nothing stops a Draft dated, say, 15 March 2026 from being sent months later, after the business has already issued dozens of `2026-27` invoices — it would grab the next number from the *2025-26* sequence instead, inserting a new invoice into a financial year whose GST returns have likely already been filed.

**Recommendation: implement this rule.** At Send time, compare the invoice's derived FY against the **highest financial year that already has a counter row** (i.e., the most recent FY any invoice has ever been sent into). If the derived FY is earlier than that, reject the Send action with a clear error (e.g. "This invoice's date falls in an already-closed financial year (2025-26); update the invoice date to fall within 2026-27, or contact an admin"). Allow sending into the *current* FY or, if genuinely needed, a *future* FY (e.g. an invoice deliberately dated for next week that happens to cross April 1 — that's a forward date, not a backdating risk, and shouldn't be blocked). Don't build a special admin override for this in v1 — a genuine need to backdate across a closed FY is rare enough, and consequential enough, that it should go through an accountant correcting the books directly rather than a button in the app. This reuses the `financial_year_counters` table that already exists, so it's a small, contained change, not a new subsystem.

**Test:** a test that sends one invoice into FY 2026-27 (establishing that FY as "current"), then attempts to send a second Draft dated in FY 2025-26, and asserts the second Send is rejected with the FY-boundary error. Also test that a Draft dated in a *future* FY sends successfully (to confirm the fix doesn't over-block).

### 10. The Content-Security-Policy header doesn't meaningfully restrict anything

`app/Http/Middleware/SecurityHeaders.php` sets `Content-Security-Policy: default-src 'self' 'unsafe-inline' 'unsafe-eval' data: https:;`. The `https:` wildcard on `default-src` permits loading scripts, styles, and other resources from *any* HTTPS origin, and `'unsafe-inline'`/`'unsafe-eval'` permit inline and dynamically-evaluated scripts — between the three, this header provides close to none of the injection protection a CSP is meant to provide, while still giving the impression one is in place. This isn't in the client's list, but it's a real gap worth closing in the same pass as the other security-adjacent items here.

**Required fix:** tighten the policy to what Filament/Livewire actually need (Filament/Livewire does need some inline-script and inline-style allowance to function, so this can't go to a bare `'self'`, but it can drop the open `https:` wildcard on `default-src` and scope `script-src`/`style-src`/`connect-src` deliberately instead of leaving everything under one permissive `default-src`). Test in a real browser (not just automated tests) that the admin panel still fully functions after tightening — Livewire's polling/websocket connections and Filament's asset loading are the most likely things to break from an overly strict rewrite.

**Test:** not easily unit-testable in the same red/green way as the others — instead, add an assertion in `SecurityHardeningTest.php` (already exists) that the CSP header does *not* contain a bare `https:` source, so a future regression back to the wildcard is caught, plus a manual verification note in §13 that the panel was checked in a browser after the change.

---

## Part C — Cross-cutting requirements

### 11. Run the test suite on MySQL too

`phpunit.xml` hardcodes `DB_CONNECTION=sqlite` / `DB_DATABASE=:memory:` for every test run — this is why #2 wasn't caught. Add a second config (e.g. `phpunit.mysql.xml`, extending or duplicating the existing one with `DB_CONNECTION=mysql` pointed at a real or containerized MySQL 8 instance) and a corresponding Composer/README script (e.g. `composer test:mysql`) so both the default fast SQLite run and a MySQL run are easy to invoke. Document in the README that MySQL is the deployment target and the MySQL test run is the one that matters for sign-off, not the SQLite one. If a CI pipeline doesn't exist yet, add one (GitHub Actions is the natural fit given the repo's already on GitHub) that runs both.

### 12. Every fix needs a red/green test, no exceptions

Restating the instruction at the top of this doc because it's the one most likely to get skipped under time pressure: for each numbered item above, the test must be shown to fail against the code as it exists right now (before your fix) and pass after. A test that only checks the fixed behavior without ever having been run against the broken code isn't proof of anything — it's just as likely to be testing the wrong thing as the right thing.

### 13. Final summary note

When everything above is fixed and tested, write a short note (as a new file, `docs/08-hardening-summary.md`, not just a chat message) covering, for each item: what was actually wrong (one or two sentences, plain language), what changed, and why that specific approach was chosen over alternatives where there was a real choice to make (e.g. why `composer.json` was bumped rather than the lock file downgraded in #3; why a hard FY-boundary block rather than an override in #9). Flag explicitly anything left incomplete or any place a judgment call was made without a clear right answer — same spirit as `docs/05-assumptions-and-open-questions.md`.
