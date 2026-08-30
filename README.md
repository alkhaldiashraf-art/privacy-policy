# SIUGOALS

Evidence-based production-readiness auditing, monitoring and trust platform
for people who build websites and apps with AI tools. Every finding, score
and prompt is backed by a real check against the real target — nothing in
this product is simulated or hardcoded to look good in a screenshot.

## What a builder can do with it

1. **Scan a live website by URL.** Add your production URL and SIUGOALS
   runs every check it can perform read-only over HTTP: HTTPS/TLS and
   certificate expiry, security headers, mixed content, cookie flags,
   exposed `.env`/`.git` paths, SEO basics, viewport/language, images and
   form accessibility, response time and page weight, broken links, and
   common vulnerability signals (exposed stack traces, dangerous CORS,
   password fields on HTTP, outdated libraries). Results are separated into
   what the scan actually verified vs. what still needs source code or a
   live Runtime connection to check — no check is marked "ready" from a
   guess.
2. **Upload your project's code (ZIP or a connected GitHub repo).** SIUGOALS
   inspects the real files — hardcoded credentials, database URIs and
   private keys, SQL/command injection, SSRF, disabled TLS verification,
   `eval`/unsafe deserialization, DOM-injection sinks, weak password
   hashing, debug flags left on, incomplete/stubbed code — without ever
   extracting the archive into the web root, and discards the upload right
   after scanning. Every finding gets its own ready-to-paste fix prompt,
   and there's one comprehensive prompt covering everything from the latest
   scan at once — use whichever your AI coding tool needs.
3. **Connect the Runtime SDK.** A small JS snippet turns on live monitoring
   of the deployed app: sessions (device/browser/OS/country, duration),
   uncaught errors and failed network requests with stack traces, behavior
   signals (rage click, dead click, error cascade, form abandonment, error
   then exit), Core Web Vitals, session replay (opt-in, values masked,
   scripts stripped), and scheduled uptime probing with incident tracking.
   Every runtime error and open incident also gets a fix prompt, correlated
   back to the matching source-scan finding when one exists.

The **Fix Center** pulls all of the above — readiness gaps, source
findings, runtime errors, incidents, and behavior/performance signals —
into one prioritized, evidence-grounded repair prompt, plus one prompt per
individual issue.

## Everything else in the product

- **Trust Center**: a public, shareable trust page per project with a
  verified score, a downloadable PDF report, and an embeddable status
  badge (PNG/SVG).
- **Clients & billing**: per-project paid access via Stripe Connect, plus
  account-level Pro subscriptions and token top-ups (Stripe billing is
  dormant until `STRIPE_SECRET_KEY` is configured).
- **GitHub App integration**: read-only repository scanning, and a
  separate, explicitly-authorized Maintenance App that installs the
  Runtime SDK only via a reviewable pull request — SIUGOALS never pushes
  directly to a connected repository.
- **AI evidence adjudication** (optional, requires `OPENAI_API_KEY`): the
  model only promotes a check to ready/need-attention when it can cite an
  exact supplied file, page or runtime reference; unsupported claims stay
  pending. All scanned/runtime content sent to the model is treated as
  untrusted data, never as instructions.
- Multi-tenant projects with roles (owner/admin/developer/viewer), referral
  rewards, weekly digest email, in-app notifications, an admin console for
  the platform owner, and English/Arabic UI.

## Architecture

- PHP 8.1+ and MySQL/MariaDB, no framework, no Composer dependencies —
  nothing to install before deploying.
- A single front controller (`public/index.php`) with explicit routes, a
  thin `App\Core` layer (auth, sessions, CSRF, rate limiting, DB, i18n),
  and one class per concern under `App\Services`.
- **Security posture**: every outbound scan request goes through
  `SafeHttp`, which resolves and pins a public IPv4 address for every hop
  (including redirects) and rejects private/loopback/link-local targets —
  this is what makes the URL scanner and uptime prober SSRF-safe. Uploaded
  ZIPs are inspected entry-by-entry without extracting to disk, rejecting
  path traversal, symlinks, and decompression bombs. All database access
  is parameterized (PDO), all state-changing routes require a CSRF token,
  passwords use `password_hash`, sensitive project secrets are encrypted
  at rest, and every view escapes output — including scanner findings and
  runtime error text, which come from content SIUGOALS does not control.
  Session replay HTML is sanitized on both the browser and the server
  before storage, and replays render inside a fully sandboxed `<iframe>`.

## Local development

```bash
cp config/config.example.php config/config.php   # then edit it — see DEPLOYMENT.md
mysql -u root -p your_db < database/siugoals.sql
php -S 127.0.0.1:8000 -t public
```

Requires the `pdo_mysql`, `curl`, and `zip` PHP extensions. See
[DEPLOYMENT.md](DEPLOYMENT.md) for the full Hostinger deployment guide,
including how to import the database and make your own account a platform
administrator.

## Preserved from the previous repository state

This repository previously contained a single static `index.html` — a
privacy policy page for an unrelated Meta/WhatsApp-integration app owned by
Ashraf Suhel Alkhaldi. That content is preserved byte-for-byte and still
served at `/privacy-policy.html`. If that URL is registered anywhere (e.g.
a Meta App Review privacy policy URL), it keeps working unchanged.

## Honest limits

- The URL scanner is read-only over HTTP: it cannot see backend code,
  database authorization, or pages behind a login. Those need the source
  scanner or the Runtime SDK.
- Static analysis proves the presence of a pattern, not the absence of
  every vulnerability. Coverage is scoped to what was actually scanned.
- AI adjudication is optional and can be wrong; a check only changes state
  when the model cites real, supplied evidence — it never overrides a
  verified deterministic finding.
- Uptime monitoring needs the `cron/uptime.php` job actually scheduled;
  Runtime data needs the SDK installed and the live app opened at least
  once.
