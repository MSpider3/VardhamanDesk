# Plan: Start VardhamanDesk Implementation from the Canonical `docs/` Specification

## Problem
The project documentation has already been consolidated into the single active `docs/` folder:

- `docs/AGENT.md`
- `docs/01-project-overview.md`
- `docs/02-database-schema.md`
- `docs/03-gst-and-invoicing-rules.md`
- `docs/04-roles-and-permissions.md`
- `docs/05-assumptions-and-open-questions.md`
- `docs/06-milestones-and-deliverables.md`

This folder is the only binding specification for VardhamanDesk. The project currently contains no application source code or Git repository, so the next task is to scaffold the Laravel application and implement **Milestone 1 — Leads + Users** in the documented order.

## Proposed Solution

### 1. Treat `docs/` as the sole project contract
- Before writing code, read `docs/AGENT.md` and all six numbered documents.
- Do not read, revive, merge, or create alternate AI-spec folders.
- If documents disagree, `docs/05-assumptions-and-open-questions.md` wins, as required by `docs/AGENT.md`.
- Do not invent business rules. Stop and request a decision for a genuinely new ambiguity.

### 2. Initialize the application and development environment
- Initialize Git in the project root before scaffolding; use small, meaningful commits.
- Create a Laravel 13 application in the project root using the current Laravel 13-compatible PHP version.
- Configure MySQL 8 with values supplied through `.env`; never commit credentials.
- Install the current stable Filament 5 release compatible with the scaffolded Laravel version and its supplied Livewire version. Confirm compatibility through Composer rather than hard-coding unsupported package combinations.
- Install/configure Pest.
- Add and verify application authentication for the Filament panel using Laravel’s supported starter/auth mechanism.
- Do not install a permission package: roles are the documented simple `admin` / `sales` user role.
- Create a root `README.md` containing only confirmed local setup prerequisites and commands that have actually been run successfully.

### 3. Implement the shared foundation required by Milestone 1
- Add the `users.role` enum/string with only `admin` and `sales`; update the User model with role helpers/casts as needed.
- Create role-aware authorization architecture:
  - Admin has global access.
  - Sales may access only records they own.
  - Enforce policy/model-query restrictions server-side, not merely by hidden Filament controls.
- Create reusable factories and seeders for one Admin and two or three Sales users. Use development-only fake credentials documented in the README.
- Add a base authorization test proving that a Sales user cannot use the Admin-only user-management capability.

### 4. Implement the Leads domain
- Create migrations, model, factory, seeder, policy, and Filament resource for `leads` exactly as defined in `docs/02-database-schema.md`:
  - owner field: `assigned_to` referencing `users`;
  - source enum: `referral`, `bni`, `website`, `cold_call`, `event`, `other`;
  - status enum: `new`, `contacted`, `qualified`, `converted`, `lost`;
  - nullable `next_follow_up_date`;
  - normal Laravel soft deletes.
- Add indexes/foreign keys needed for owner-scoped listings and follow-up queries.
- Enforce lifecycle rules in a dedicated domain action/service or equivalent single server-side rule location:
  - Sales-created leads automatically receive the current Sales user as `assigned_to`.
  - Admin may assign/reassign leads to Sales users.
  - Any non-`converted` status may transition to another allowed status.
  - `lost` can be reopened.
  - `converted` is terminal; block all status transitions out of it.
- Ensure Sales cannot query, view, edit, delete, or reassign another Sales user’s Lead through direct URLs, mass-action requests, or resource queries.

### 5. Implement Lead Notes and follow-up synchronization
- Create `lead_notes` with `lead_id`, `created_by`, note body, and nullable `follow_up_date`, as specified in `docs/02-database-schema.md`.
- Provide notes through an ownership-protected Lead relation manager/page in Filament.
- When a note provides a follow-up date, update `leads.next_follow_up_date` transactionally according to the documented “latest note’s follow-up” rule.
- Preserve note history: do not expose destructive/edit behavior unless an explicit later decision permits it.
- Restrict note creation and viewing to the Lead’s permitted owner/Admin.

### 6. Implement Dashboard v1
- Add an authenticated Filament dashboard with two distinct lists:
  1. **Overdue follow-ups:** `next_follow_up_date < today`, excluding terminal converted leads.
  2. **Today’s follow-ups:** `next_follow_up_date = today`, excluding terminal converted leads.
- Display overdue follow-ups before today’s follow-ups.
- Admin sees all matching Leads; Sales sees only Leads assigned to them.
- Use query-level ownership scoping and eager-load relations where needed; do not filter only after loading all records.

### 7. Seed a reproducible Milestone-1 demo dataset
- `php artisan migrate:fresh --seed` must create:
  - one Admin and two or three Sales users;
  - approximately 15 Leads distributed across the allowed sources and non-terminal statuses;
  - Leads owned by multiple Sales users;
  - notes and follow-up dates that demonstrate both overdue and today sections;
  - at least one Lost Lead that can be reopened;
  - at least one Converted Lead to prove the terminal status guard.
- Do not create clients, invoices, GST rates, payments, or PDFs yet; these belong to subsequent documented milestones.

### 8. Add the Milestone-1 Pest test suite before marking any checklist item complete
Add focused feature/unit tests covering at minimum:
- role-based access and Admin global access;
- Sales ownership scoping for Lead list/query, direct view, update, delete, and reassign attempts;
- Sales-created Lead auto-assignment;
- Admin lead reassignment;
- fixed source/status validation;
- permitted backward transitions, Lost reopening, and the block on transitions out of Converted;
- Lead Note ownership and creation;
- synchronization of a note follow-up date to `leads.next_follow_up_date`;
- overdue and today dashboard ordering and per-role scope;
- fresh migrations and seeders.

### 9. Verify and document the milestone
- Run formatting/static checks that the installed Laravel/Filament/Pest configuration supports.
- Run the targeted tests during development and the complete Pest suite before finalizing.
- Run `php artisan migrate:fresh --seed` against a clean development database.
- Start the application and manually verify the Filament panel with both an Admin and a Sales demo account.
- Mark only completed Milestone-1 boxes in `docs/06-milestones-and-deliverables.md` in the same commit as the related implementation.
- Make incremental commits only for finished logical units; do not manufacture commits.

## Recommended Tool
Antigravity — this work scaffolds a new application, creates multiple files, installs dependencies, runs Composer/NPM/Artisan commands, and needs full end-to-end testing.

## Scope
- Files likely affected:
  - Laravel application scaffold files at the project root (`composer.json`, `artisan`, `app/**`, `bootstrap/**`, `config/**`, `database/**`, `routes/**`, `resources/**`, `tests/**`)
  - `.env.example` and `.gitignore` (never a credential-bearing `.env`)
  - root `README.md`
  - `docs/06-milestones-and-deliverables.md` — only completed Milestone-1 checkbox updates
- Files intentionally not changed during this handoff:
  - `docs/01-project-overview.md` through `docs/05-assumptions-and-open-questions.md`
  - `docs/AGENT.md`
  - any Milestone-2/3 application code for Clients, Invoices, GST, Payments, PDF, or final dashboard metrics
- Multi-file? Yes.
- Requires running commands/tests? Yes: Composer, npm, Artisan migrations/seeders, Pest, and a manual Filament-panel smoke test.

## Verification
1. A fresh clone can be configured using `README.md`, then successfully run `composer install`, frontend dependency installation/build, and `php artisan migrate:fresh --seed`.
2. The project uses Laravel 13, MySQL 8, a Composer-compatible Filament 5 release, and Pest; exact installed versions are captured from Composer output.
3. An Admin can manage users and all Leads, including reassignment; a Sales user is restricted to their own Leads and notes even through direct URLs/requests.
4. A Sales-created Lead is assigned to that Sales user; converted Leads cannot transition to another status; Lost Leads can reopen.
5. Adding a follow-up note updates the owning Lead’s `next_follow_up_date` and Dashboard v1 shows overdue items before today’s, correctly scoped by role.
6. The complete Pest suite passes and `php artisan migrate:fresh --seed` succeeds on a clean database.
7. Only the completed Milestone-1 items in `docs/06-milestones-and-deliverables.md` are checked off.

## Status
- [x] Implemented
- [x] Reviewed
- [x] Tested
