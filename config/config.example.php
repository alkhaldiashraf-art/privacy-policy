<?php
/**
 * SIUGOALS 5.5 — Manual Hostinger Configuration
 *
 * Copy this file to config/config.php, fill in real values, then upload
 * config/config.php directly to your host. Never commit config/config.php
 * (it is gitignored) and never share it publicly.
 */
return [
    'APP_URL' => 'https://your-domain.com',
    'APP_ENV' => 'production',
    // Generate a real one with: php -r "echo bin2hex(random_bytes(32));"
    // The app refuses to boot until this no longer starts with CHANGE_THIS.
    'APP_KEY' => 'CHANGE_THIS_GENERATE_YOUR_OWN_64_CHARACTER_RANDOM_KEY',

    'DB_DRIVER' => 'mysql',
    'DB_HOST' => 'localhost',
    'DB_PORT' => '3306',
    'DB_DATABASE' => 'YOUR_DATABASE_NAME',
    'DB_USERNAME' => 'YOUR_DATABASE_USER',
    'DB_PASSWORD' => 'YOUR_DATABASE_PASSWORD',

    // Optional live acceptance switch for the project owner only. Keep false for commercial launch.
    // When temporarily true, owner validation scans/agent runs cost 0 tokens but still execute the real scanners.
    'OWNER_ACCEPTANCE_MODE' => false,
    'OWNER_TEST_MODE' => false, // legacy non-production switch
    'TOKEN_COST_READINESS_RESCAN' => 300,
    'TOKEN_COST_WEBSITE_SCAN' => 150,
    'TOKEN_COST_SOURCE_SCAN' => 250,
    'TOKEN_COST_GITHUB_SCAN' => 250,
    'TOKEN_COST_AGENT_RUN' => 120,

    // Optional account sign-in OAuth.
    'GOOGLE_OAUTH_CLIENT_ID' => '',
    'GOOGLE_OAUTH_CLIENT_SECRET' => '',
    'GITHUB_OAUTH_CLIENT_ID' => '',
    'GITHUB_OAUTH_CLIENT_SECRET' => '',

    // GitHub Apps: read-only scanning and separately authorized Maintenance write access.
    'GITHUB_APP_ID' => '',
    'GITHUB_APP_SLUG' => '',
    'GITHUB_APP_PRIVATE_KEY_BASE64' => '',
    'GITHUB_MAINT_APP_ID' => '',
    'GITHUB_MAINT_APP_SLUG' => '',
    'GITHUB_MAINT_APP_PRIVATE_KEY_BASE64' => '',

    // OpenAI is used for evidence-first source/readiness scanning and remediation agents.
    // The scanner never treats model guesses as evidence; unsupported checks stay pending.
    'OPENAI_API_KEY' => '',
    'OPENAI_MODEL' => 'gpt-4.1-mini',
    'OPENAI_SCAN_MODEL' => 'gpt-4.1-mini',
    'AI_SCAN_MAX_CHARS' => 300000,
    'AI_SCAN_MAX_FILES' => 100,
    'DEEP_SCAN_REFRESH_GITHUB' => true,

    // Privacy/storage: live session events and replays are removed by maintenance cron after this many days.
    'RUNTIME_RETENTION_DAYS' => 30,
    'UPTIME_RETENTION_DAYS' => 90,

    // Stripe billing, Connect, client checkout, and paid access.
    'STRIPE_SECRET_KEY' => '',
    'STRIPE_WEBHOOK_SECRET' => '',

    // Hostinger mail() transport for password-reset and optional alerts.
    'MAIL_ENABLED' => false,
    'MAIL_FROM_EMAIL' => 'support@siugoals.com',
    'MAIL_FROM_NAME' => 'SIUGOALS',
];
