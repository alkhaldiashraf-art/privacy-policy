# Deploying SIUGOALS on Hostinger

## 1. Database

In hPanel: Databases → MySQL Databases → create a database and a user with
full privileges on it. Note the host (usually `localhost`), database name,
username, and password.

## 2. Files

Upload the repository contents to your hosting account (Git deploy, File
Manager, or FTP). **Point the domain's document root at the `public/`
directory**, not the repository root — this is required: everything
outside `public/` (source, config, migrations, storage) must not be
web-accessible. A root-level `.htaccess` denies all requests as a defense
in depth if the document root is ever misconfigured, but the correct fix
is always to set the document root to `public/`.

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

The project currently has zero runtime dependencies, and `vendor/` (just
Composer's own autoloader) is committed to the repository, so no
`composer install` step is required to deploy. If a later phase adds a
real dependency (e.g. a PDF library for the Trust Center), run
`composer install --no-dev --optimize-autoloader` and re-commit `vendor/`
before deploying to shared hosting without SSH, or run it on the server
directly if SSH is available.

## 6. Verify

- Visit `/` — should show the SIUGOALS placeholder home page.
- Visit `/privacy-policy.html` — should show the preserved privacy policy
  that used to be served at the repository root. Update any external
  registration of that URL (e.g. a Meta App Review listing) if it pointed
  at the old root path.
- Sign up for an account, create a project, edit its settings — confirms
  the database connection, sessions, and CSRF are all working.

## Known limitation for this phase

There is currently no cron/scheduler requirement and no webhook
configuration needed — those arrive with the runtime SDK, uptime engine,
and GitHub integration phases, which are not part of this build yet.
