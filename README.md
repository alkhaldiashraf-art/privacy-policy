# SIUGOALS

Evidence-based production-readiness auditing and trust platform.

This repository is being rebuilt from scratch. What follows is an honest
account of what actually works today versus what the full product
specification calls for — see `SPEC.md`-equivalent notes in project history
for the complete 103-section directive. **Nothing below is faked**: every
listed feature is backed by real server-side logic and a real database.

## What's implemented (Phase 0/1: architecture foundation)

- PHP 8.1+ / MySQL, no framework, Composer PSR-4 autoloading, zero runtime
  dependencies (so `vendor/` can be committed and no `composer install` is
  required on shared hosting).
- Custom lightweight router, request/response handling, PDO database layer
  (prepared statements only, `PDO::ATTR_EMULATE_PREPARES` off).
- Sessions with hardened cookie flags (`HttpOnly`, `SameSite=Lax`, `Secure`
  when served over HTTPS), CSRF tokens enforced on every POST.
- Security headers on every response: CSP, `X-Frame-Options`,
  `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, HSTS
  when HTTPS.
- DB-backed rate limiting (row-locked counters, safe across PHP-FPM
  workers), applied to login and signup.
- Real authentication: signup, login, logout. Passwords hashed with
  `password_hash` (bcrypt). Generic "invalid email or password" on login
  failure (no user enumeration); rate-limited.
- Projects with real multi-tenancy: `project_members` with roles
  (`owner`, `admin`, `developer`, `viewer`). Every project route re-checks
  membership server-side — there is no way to view or edit another
  account's project by guessing an ID (tested; see below).
- Project **General** settings tab (name, URL, tagline), editable by
  `owner`/`admin` only, read-only for `developer`/`viewer`.
- Audit logging (`audit_logs`) for signup, login, login failures, logout,
  project creation, and settings changes.
- A locking one-time web installer at `/install` (runs migrations, then
  writes `storage/installed.lock` and refuses forever after — no
  `?force` or similar bypass), for shared-hosting setups without SSH.
- `database/migrate.php` CLI runner for setups that do have SSH/Composer
  access. Migrations are additive-only SQL files tracked in
  `schema_migrations`; already-applied migrations are always skipped.
- Design tokens and a base component library (buttons, cards, forms,
  badges, alerts, empty states) matching the SIUGOALS visual spec
  (off-white/white/dark palette, Inter body font, Anton display font,
  semantic button colors), plus the authenticated sidebar shell.
- **URL scanner**: from a project's Scan tab, fetch a real live URL
  (SSRF-hardened: hostnames are resolved and validated as public IPs, and
  curl is pinned to that exact IP, before every request including
  redirects) and run real checks — HTTPS/TLS and certificate expiry,
  security headers (HSTS, CSP, X-Frame-Options, etc.), cookie flags,
  exposed `.env`/`.git` paths, SEO basics (title/meta description/OG
  tags), mobile viewport and basic accessibility (alt text, form labels,
  link text), performance signals (response time, page size,
  compression, render-blocking scripts), and a handful of common
  vulnerability signals (exposed stack traces, dangerous CORS, outdated
  jQuery, password fields on HTTP). Every finding is a genuine match
  against the real response — nothing here is simulated.
- **Project code scanner**: upload a `.zip` of a project (or a single
  source file) and it's scanned with rule-based static analysis for
  hardcoded secrets/credentials, dangerous PHP/JS patterns (`eval`,
  SQL built by string concatenation, LFI-prone `include`, unescaped
  output, `innerHTML` from a variable, tokens in `localStorage`, etc.),
  and basic mobile/maintainability issues. ZIP extraction is guarded
  against zip-slip path traversal and zip bombs (size/file-count caps),
  and extracted files are deleted immediately after the scan.
- **AI fix prompts**: every finding gets a ready-to-paste fix prompt, plus
  one comprehensive prompt covering all findings at once. When
  `ANTHROPIC_API_KEY` is configured, these are genuinely written by the
  Anthropic API from the real finding data; with no key configured, the
  scan still runs and prompts fall back to a clearly-labelled
  deterministic template instead of silently pretending to be AI-written.

## What is intentionally NOT built yet

Everything else in the full SIUGOALS specification is future work, not a
missing feature that's been faked in the UI:

- Public (unauthenticated) URL production-audit scanner for signed-out
  visitors, and its guided questions/report; import-on-signup flow
- GitHub repository scanner (only URL and direct file/ZIP upload exist
  so far)
- Evidence engine / readiness checks / readiness score / findings drawer
  / quick questions / recheck engine
- Trust Center, public trust pages, PDF reports, certificates
- Runtime SDK, heartbeats, uptime monitoring, error monitoring, session
  replay
- Clients / paid access, billing, usage tracking, referrals,
  notifications
- AI agents and maintenance-mode repo write access
- Arabic/RTL localization

The home page, project overview page, and nav explicitly say "not built
yet" rather than showing empty/fake versions of these.

## Preserved from the previous repository state

This repo previously contained a single static `index.html` — a privacy
policy page for an unrelated Meta/WhatsApp-integration app owned by
Ashraf Suhel Alkhaldi. That content is preserved and still served, now at
`/privacy-policy.html` (the document root moves to `public/` — see
`DEPLOYMENT.md`). If that URL is registered anywhere (e.g. a Meta App
Review privacy policy URL), update it to point at the new path.

## Local development

```bash
composer install         # only needed if you add real dependencies later
cp .env.example .env      # fill in DB credentials
php database/migrate.php  # or use /install in the browser
php -S 127.0.0.1:8000 -t public public/index.php
```

## Testing performed this session

Ran against a real MariaDB instance (not mocked): signup, login (including
wrong-password + rate-limit lockout at the configured threshold), logout,
CSRF rejection on a token-less POST, project creation, settings update,
and an explicit two-user IDOR test — a second account was confirmed to
receive a 404 (not project data, not a leak) both viewing and attempting
to POST to a project it is not a member of. The one-time installer was
confirmed to lock after first use with no query-string bypass. All PHP
files pass `php -l`.
