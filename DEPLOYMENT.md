# Deploying SIUGOALS on Hostinger

## 1. Database

In hPanel: Databases → MySQL Databases → create a database and a user with
full privileges on it. Note the host (usually `localhost`), database name,
username, and password.

## 2. Files

Upload the repository contents to your hosting account (Git deploy, File
Manager, or FTP), using whichever of these two layouts matches what your
plan allows. Everything outside `public/` (source, config, migrations,
storage) must never be web-accessible — that's the point of both options
below. `public/index.php` auto-detects which layout is in use; no code
changes are needed either way.

**Option A — you can change the domain's Document Root** (Business/Cloud
plans, most VPS): upload the repository as-is, then point the domain's
Document Root at its `public/` folder. A root-level `.htaccess` denies
all requests as defense in depth if the document root is ever pointed at
the repository root by mistake — if you see a 403 "Access to this
resource on the server is denied" page, that's this safeguard telling you
the document root is wrong, not an application error.

**Option B — your plan fixes Document Root to `public_html`** (common on
single-site/shared plans, where there is no per-domain document root
setting to change): you cannot point `public_html` at a subfolder, so
instead:

```
home/                     (your hosting account's home directory)
├── app/                  ← src/, routes/, database/, resources/,
│                           storage/, vendor/, composer.json,
│                           composer.lock, .env  (NOT web-accessible)
└── public_html/          ← Document Root (fixed by the host)
    ├── index.php         ← copied from public/index.php
    ├── .htaccess         ← copied from public/.htaccess
    ├── assets/           ← copied from public/assets/
    └── privacy-policy.html
```

Copy `public/`'s *contents* (not the folder itself) directly into
`public_html`, and put everything else in a sibling folder named exactly
`app`, next to `public_html` — not inside it. `index.php` looks for
`src/bootstrap.php` one level above itself first (Option A layout); if
that's not there, it looks in a sibling `app/` folder next to whatever
directory it's actually running from (Option B layout) and uses whichever
it finds. If your File Manager shows `public_html/public/...` after
uploading, you've uploaded the whole repo into `public_html` — move
`public_html/public/`'s contents up into `public_html/` itself, then move
everything else in `public_html` into a new `app/` folder in the home
directory (one level above `public_html`).

## 3. Environment

Copy `.env.example` to `.env` in the repository root (**not** inside
`public/`) and fill in:

- `APP_URL` — your real domain, `https://...`
- `APP_KEY` — generate with `php -r "echo bin2hex(random_bytes(32));"`
- `APP_DEBUG=false` in production, always
- `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` from step 1
- `SESSION_SECURE_COOKIE=true` (requires HTTPS, which Hostinger provides
  free via Let's Encrypt — enable it in hPanel first)

Never commit the real `.env` file or put production secrets in the git
repository.

## 4. Database schema

**If you have SSH** (Hostinger Business/Cloud plans):

```bash
php database/migrate.php
```

Safe to run repeatedly — it only applies migrations that haven't run yet.

**If you don't have SSH** (plain shared hosting): visit
`https://yourdomain.com/install` in a browser and click "Run
installation". This endpoint runs the same migrations, then writes
`storage/installed.lock` and permanently refuses to run again — there is
no bypass. If you need to apply new migrations after a future deploy and
still have no SSH access, that will require a small controlled admin path
in a later phase; for now, request SSH-enabled hosting or run migrations
locally against a hosting-accessible DB host.

## 5. Composer dependencies

The project has zero third-party runtime dependencies, and `vendor/` (just
Composer's own autoloader) is committed to the repository, so no
`composer install` step is required to deploy. If a later phase adds a
real third-party dependency (e.g. a PDF library for the Trust Center), run
`composer install --no-dev --optimize-autoloader` and re-commit `vendor/`
before deploying to shared hosting without SSH, or run it on the server
directly if SSH is available.

The scanner does require two standard PHP extensions: `ext-curl` (URL
scanning and the optional Anthropic API call) and `ext-zip` (extracting
uploaded project ZIPs). Both are enabled by default on Hostinger's PHP
builds; verify with `php -m` if a scan fails unexpectedly.

For the upload scanner, also check `upload_max_filesize` and
`post_max_size` in your hosting's PHP configuration — they must be at
least 20M for the largest allowed upload to go through; hPanel exposes
these under Advanced → PHP Configuration on most plans.

To get AI-generated fix prompts instead of the template fallback, set
`ANTHROPIC_API_KEY` (and optionally `ANTHROPIC_MODEL`) in `.env`. This is
optional — the scanner works without it.

## 6. Verify

- Visit `/` — should show the SIUGOALS placeholder home page.
- Visit `/privacy-policy.html` — should show the preserved privacy policy
  that used to be served at the repository root. Update any external
  registration of that URL (e.g. a Meta App Review listing) if it pointed
  at the old root path.
- Sign up for an account, create a project, edit its settings — confirms
  the database connection, sessions, and CSRF are all working.

## Runtime SDK endpoint

`POST /api/collect` is a public, cross-origin endpoint (CORS enabled,
CSRF-exempt by design) that the embeddable SDK snippet
(`public/assets/js/sdk.js`, shown on each project's Sessions tab) posts
to from customer websites. It authenticates requests by a per-project
public key, not a session cookie — nothing to configure, but be aware it
is intentionally reachable without login, rate-limited per key+IP. It
also makes a best-effort outbound HTTPS call to ipapi.co for country
lookup on new sessions; no key/config needed, and a lookup failure just
leaves the country unknown rather than failing the request.

## Known limitation for this phase

There is currently no cron/scheduler requirement and no webhook
configuration needed — those arrive with the uptime engine and GitHub
integration phases, which are not part of this build yet.
