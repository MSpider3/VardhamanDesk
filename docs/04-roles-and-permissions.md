# Roles & Permissions

Two roles, enforced at the model layer (Eloquent global scopes + Laravel Policies), not just hidden UI elements.

## Admin
- Full CRUD on every Lead, Client, Invoice, Payment, and User — **except** Sent/Paid invoices, which no one (including Admin) can delete, only Draft invoices are deletable by anyone
- Can reassign both Leads and Clients between salespeople (a Client has its own `assigned_to`, independent of whichever Lead it may have originated from)
- Sees the dashboard's company-wide totals, including the company-wide overdue/today's-follow-ups sections

## Sales
- Can create Leads (auto-assigned to self) and edit/update only Leads where `assigned_to = self`
- Can add notes and set follow-ups only on own Leads
- Can convert own Qualified Leads to Clients
- Can create/view Invoices and Payments only for Clients that trace back to their own Leads
- **Cannot** see other salespeople's leads, clients, or invoices, at all — not read-only, fully hidden
- **Cannot** create/edit/delete other Users
- Dashboard shows only their own follow-ups and their own totals (not company-wide)

## Enforcement layers (defense in depth)

1. **Eloquent global scope** on Lead/Client/Invoice/Payment models — auto-filters every query by owner (`leads.assigned_to`, `clients.assigned_to`, and the owning client's `assigned_to` for Invoice/Payment) unless the acting user is Admin. This is the primary guard; even a raw `Model::all()` call respects it.
2. **Laravel Policies** on each model's `view`/`update`/`delete` actions — checked in controllers/Filament resources, covers the "acting on a specific record" case the scope alone doesn't (e.g. someone guessing another lead's URL).
3. **Filament resource-level `canViewAny`/`canEdit` etc.** — hides the UI affordances too, so Sales users don't even see controls for things they can't do.

This is what the permissions test suite (see `06-milestones-and-deliverables.md`) exists to prove: a Sales user hitting another Sales user's record — via UI, direct route, or raw query — is blocked at every layer.
