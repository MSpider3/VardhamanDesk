# Vulnerability Report: VULN-07 — Default Administrator Credentials Provisioning in Production Mode

- **Vulnerability ID**: VULN-07
- **Severity**: High (CVSS: 8.8 - `CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:H/A:H`)
- **Status**: Fixed in v1.1.0
- **Target Component**: `app/Console/Commands/AppBootstrapCommand.php`
- **Assessed Release**: v1.0.1
- **Fixed Release**: v1.1.0

---

## Summary

In VardhamanDesk v1.0.1, the production bootstrap command (`php artisan app:bootstrap`) silently defaulted to password `'password'` when executed non-interactively in production environments without the `--password` option or an environment variable. If an operator triggered deployment automation without an explicitly injected password, a fully active and verified administrator account (`admin@vardhamandesk.local`) was created with publicly known credentials, allowing arbitrary unauthorized takeover of the application.

---

## Root Cause Analysis

In `app/Console/Commands/AppBootstrapCommand.php`, the command resolved the administrator password via:
```php
$password = $this->option('password')
    ?? env('APP_BOOTSTRAP_ADMIN_PASSWORD')
    ?? env('SEED_DEFAULT_PASSWORD', 'password');
```

In automated CI/CD deployment pipelines or non-interactive deployment hooks, `$this->input->isInteractive()` is false. If the environment variables were omitted, the command silently created the administrator account with `'password'` without emitting a warning or aborting execution, even when `app()->isProduction()` returned true.

---

## Proof of Concept & Reproduction

1. Set application environment to production:
   ```bash
   APP_ENV=production php artisan app:bootstrap --admin-email=admin@company.com --no-interaction
   ```
2. **Observed Output** (Before Fix):
   The command completed successfully with exit code 0 and created the administrator with password `'password'`.
3. Attacker connects to `/admin/login`, enters `admin@company.com` and password `password`, and gains full administrative privileges over the tenant.

---

## Remediation

In `app/Console/Commands/AppBootstrapCommand.php`, enforced mandatory password specification in production:
```php
if (app()->isProduction()) {
    if (empty($password) || $password === 'password') {
        $this->error('In production, an explicit, secure password must be provided via --password or the APP_BOOTSTRAP_ADMIN_PASSWORD / SEED_DEFAULT_PASSWORD environment variable.');

        return self::FAILURE;
    }
}
```

If running in production with no password or with the literal default `'password'`, the command immediately halts with exit code 1 and logs an explicit error, preventing inadvertent deployment with insecure credentials.

---

## Test Verification

Automated regression coverage added in `tests/Feature/AppBootstrapCommandTest.php`:
- `test('bootstrap fails in production if no password is provided or default password is used')`:
  Verifies that executing `php artisan app:bootstrap` in `APP_ENV=production` without a password fails with exit code 1 and outputs the rejection notice.

Verified passing on both SQLite and MySQL 8.
