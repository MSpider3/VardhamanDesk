# AI History: Milestone 1 — Leads and Users

## Objectives & Scope
- Implement authentication, role-based redirection, and user management for Admin and Sales roles.
- Create Lead and LeadNote schema, models, policies, and Filament resources.
- Enforce strict ownership scoping where Sales representatives see only their assigned leads, while Admins have global visibility.
- Provide Admin capability to reassign lead ownership.
- Build Dashboard v1 featuring an Overdue Follow-ups widget positioned above a Today's Follow-ups widget, scoped per user.

## Key Decisions & Architecture
1. **Role Enum**: Implemented `App\Enums\UserRole` (`admin`, `sales`). Used a simple column on `users` rather than heavyweight RBAC packages to adhere to simplicity principles.
2. **Terminal Lead Status**: Defined `App\Enums\LeadStatus` with `converted` as a strictly terminal state. Leads can move freely between `new`, `contacted`, `qualified`, and `lost`, but once `converted`, the status cannot change.
3. **Lead Notes & Follow-up Dates**:
   - Model observer/hooks ensure that adding a note with a `follow_up_date` automatically updates `leads.next_follow_up_date`.
4. **Dashboard v1 Widget Ordering**:
   - `OverdueFollowUpsWidget` assigned `$sort = 1`.
   - `TodayFollowUpsWidget` assigned `$sort = 2`.

## Verification & Outcomes
- 33 Pest tests created and passing across auth, lead CRUD, ownership scoping, note sync, and dashboard visibility.
- Tested manually via Filament panel at `/admin`.
