# Deploying SIUGOALS on Hostinger

## 1. Create the database

In hPanel: **Databases → MySQL Databases** → create a database and a user
with full privileges on it. Note the host (usually `localhost`), database
name, username, and password.

Open **phpMyAdmin**, select the new (empty) database, go to **Import**, and
import `database/siugoals.sql`. This one file creates the complete schema
plus reference data (plans, quick questions, the migration ledger) — there
is nothing else to import for a fresh install.

## 2. Configure the app

Copy `config/config.example.php` to `config/config.php` and edit it:

- `APP_URL` — your real domain, `https://...`
- `APP_KEY` — generate one with `php -r "echo bin2hex(random_bytes(32));"`
  and paste it in. The app refuses to start while this still begins with
  `CHANGE_THIS`, so you cannot forget this step.
- `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` from step 1.

Everything else in that file (OAuth, GitHub Apps, OpenAI, Stripe, mail) is
optional — leave those keys blank and the corresponding feature just stays
off; the core scanner, Fix Center and Runtime SDK all work without any of
them.

**Never commit `config/config.php`** — it's already in `.gitignore` because
it will hold real secrets once you fill it in. Upload it to your host
directly (FTP/File Manager), separately from your git history.

## 3. Upload the files

You have two options, depending on your Hostinger plan:

**Option A — point the domain's document root at `public/` (recommended
when your plan allows it).** Upload the whole repository, then in hPanel
set the domain's document root to the `public/` folder. This keeps `app/`,
`config/`, `database/`, `storage/`, and `cron/` completely outside the web
server's reach — the strongest setup, since there's nothing an
`.htaccess` mistake could ever expose.

**Option B — upload everything into `public_html/` (most basic shared
hosting plans; you can't change the document root).** Upload the entire
repository as-is into `public_html`. The root `.htaccess` routes every
request through `public/index.php` and explicitly denies direct access to
`app/`, `database/`, `storage/`, `config/`, `cron/`, and any `.sql`/`.log`
file — but this relies on Apache actually honoring `.htaccess`
(`AllowOverride All`, which Hostinger enables by default). Option A has no
such dependency, so prefer it whenever you can.

Either way, make sure `storage/logs/` and `storage/temp/` are writable by
PHP.

## 4. PHP requirements

Needs `ext-pdo_mysql`, `ext-curl`, and `ext-zip` — all enabled by default
on Hostinger's PHP builds; verify with `php -m` if something fails.

For the code/ZIP scanner (uploads up to 100 MB), raise `upload_max_filesize`
and `post_max_size` to at least `100M` under hPanel → Advanced → PHP
Configuration.

## 5. Schedule the cron jobs (Hostinger → Advanced → Cron Jobs)

| Job | Schedule | Command |
|---|---|---|
| Uptime probes | every 5 minutes | `php /home/USER/public_html/cron/uptime.php` |
| Retention/token cycle maintenance | daily | `php /home/USER/public_html/cron/maintenance.php` |
| Weekly digest email | weekly | `php /home/USER/public_html/cron/notifications.php` |

Adjust the path to wherever the repository actually lives on your account
(it's the same regardless of whether you chose option A or B above, since
these scripts are invoked directly by the PHP CLI, not through the web
server). Without the uptime cron, Runtime sessions still work, but the
Uptime chart and incident tracking never update.

## 6. Verify the install

Visit `https://your-domain.com/health` — it should report:

```json
{"ok": true, "schemaReady": true, "schemaIssues": []}
```

If `schemaIssues` is non-empty, the database import didn't fully complete —
re-check step 1.

Then sign up for an account at `/signup`, create a project, and run a
Website Scan to confirm the database connection, sessions, and CSRF are
all working end to end.

## 7. Make yourself a platform administrator (optional)

The database ships with **no** default admin account on purpose — a shared
or well-known credential in a public schema file is a real security risk,
so there's nothing to remember to change. After you've signed up normally
through the site, promote your own account with one statement in
phpMyAdmin's SQL tab:

```sql
INSERT INTO platform_admins (user_id)
SELECT id FROM users WHERE email = 'you@example.com';
```

This unlocks `/admin` (platform-wide stats, users, projects, audit log) for
that account only.

## 8. Install the Runtime SDK on the monitored app

Each project's **Runtime SDK** tab (`/builds/<slug>/dashboard/connect`)
shows a copy-paste snippet keyed to that project, e.g.:

```html
<script type="module">
  import('https://your-domain.com/sdk/launchkit.js?v=5.5.0')
    .then(({ init }) => init({ buildSlug: 'your-project-slug' }))
    .catch((error) => console.error('[SIUGOALS] SDK failed to start', error));
</script>
```

Paste it before `</body>` on the monitored site, open that site once, then
click **Verify** on the Connect page. If verification fails, check
`window.SIUGOALS_DIAGNOSTICS` in the monitored site's browser console — it
records exactly which step failed (wrong project key, origin mismatch,
network error, etc.). If the project's source is on GitHub with the
Maintenance GitHub App connected, SIUGOALS can instead open a pull request
that adds the snippet for you — it never edits the repository directly.

## Optional integrations

All of these are off by default and every core feature works without them:

- **`OPENAI_API_KEY`** — turns on the AI evidence pass for source scans and
  the overall readiness review (per-finding fix prompts work either way;
  without a key they're written from a deterministic template instead of
  the model).
- **`STRIPE_SECRET_KEY` / `STRIPE_WEBHOOK_SECRET`** — turns on Pro
  subscriptions, token top-ups, and per-project paid client access via
  Stripe Connect. Set the webhook endpoint to
  `https://your-domain.com/webhooks/stripe`.
- **`GOOGLE_OAUTH_CLIENT_ID/SECRET`, `GITHUB_OAUTH_CLIENT_ID/SECRET`** —
  "Sign in with Google/GitHub" on the login page.
- **`GITHUB_APP_ID/SLUG/PRIVATE_KEY_BASE64`** (read-only scanning) and the
  separate **`GITHUB_MAINT_APP_*`** (SDK-install pull requests) — two
  distinct GitHub Apps by design, so read access and write access are
  never granted by the same installation.
- **`MAIL_ENABLED`, `MAIL_FROM_EMAIL`, `MAIL_FROM_NAME`** — password-reset
  emails and the weekly digest, sent via PHP's `mail()`.
