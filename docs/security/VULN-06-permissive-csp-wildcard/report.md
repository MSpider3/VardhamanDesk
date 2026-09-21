# Vulnerability Report: VULN-06 — Permissive Content-Security-Policy (CSP) Wildcard Bypass

- **Vulnerability ID**: VULN-06
- **Severity**: Medium (CVSS: 6.1 - `CVSS:3.1/AV:N/AC:L/PR:N/UI:R/S:C/C:L/I:L/A:N`)
- **Status**: Fixed in v1.1.0
- **Target Component**: `app/Http/Middleware/SecurityHeaders.php`
- **Assessed Release**: v1.0.0
- **Fixed Release**: v1.1.0

---

## Summary

In VardhamanDesk v1.0.0, the `SecurityHeaders` middleware configured a Content Security Policy header with a broad HTTPS wildcard:
```http
Content-Security-Policy: default-src 'self' 'unsafe-inline' 'unsafe-eval' data: https:;
```
The inclusion of `https:` on `default-src` permitted loading scripts, stylesheets, iframes, fonts, media, and opening outbound WebSocket/fetch connections to *any* arbitrary external HTTPS origin on the internet. Combined with `'unsafe-inline'` and `'unsafe-eval'`, this negated the primary cross-site scripting (XSS) and data exfiltration defenses CSP is designed to provide.

---

## Technical Impact

1. **Unrestricted Script / Resource Injection**: Any injection point could load malicious third-party scripts from arbitrary attacker-controlled HTTPS domains (e.g. `https://evil.com/payload.js`).
2. **Data Exfiltration**: Malicious JavaScript or injected tags could connect to arbitrary remote servers via `fetch()`, `XMLHttpRequest`, or WebSockets without policy violation reports or browser blocking.
3. **Framing & Clickjacking**: Because `frame-ancestors` was not defined, the site could be embedded into malicious framing sites unless saved by `X-Frame-Options` (which some legacy clients ignore if CSP is parsed).

---

## Remediation

In `app/Http/Middleware/SecurityHeaders.php`:
1. Stripped the open `https:` source entirely.
2. Explicitly scoped individual directives (`script-src`, `style-src`, `font-src`, `img-src`, `connect-src`).
3. Added `frame-ancestors 'none'` to block iframe embedding at the CSP level.
4. Added HTTP Strict Transport Security (`Strict-Transport-Security: max-age=31536000; includeSubDomains`) for all secure and production requests.

### Hardened Header
```http
Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; font-src 'self' data:; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none';
Strict-Transport-Security: max-age=31536000; includeSubDomains
X-Frame-Options: DENY
X-Content-Type-Options: nosniff
Referrer-Policy: same-origin
Permissions-Policy: geolocation=(), microphone=()
```

---

## Test Verification

Automated regression coverage in `tests/Feature/SecurityHardeningTest.php`:
- `test('Item 10: CSP header is tightened and does not contain open https: wildcard source')`
- `test('HTTP security headers (X-Frame-Options, X-Content-Type-Options, Referrer-Policy, HSTS) are strictly configured')`

Live browser dogfood testing confirmed that Filament Admin and Livewire components operate without console CSP errors. Verified on both SQLite and MySQL 8.
