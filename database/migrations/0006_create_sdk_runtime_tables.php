<?php

declare(strict_types=1);

return [
    "ALTER TABLE project_profiles
        ADD COLUMN sdk_public_key VARCHAR(64) NULL AFTER repository_url,
        ADD UNIQUE KEY project_profiles_sdk_public_key_unique (sdk_public_key)",

    "CREATE TABLE sdk_sessions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        project_id BIGINT UNSIGNED NOT NULL,
        session_uid VARCHAR(64) NOT NULL,
        identity VARCHAR(255) NULL,
        os VARCHAR(50) NULL,
        browser VARCHAR(50) NULL,
        headless TINYINT(1) NOT NULL DEFAULT 0,
        country_code CHAR(2) NULL,
        ip_hash CHAR(64) NULL,
        started_at DATETIME NOT NULL,
        last_seen_at DATETIME NOT NULL,
        error_count INT UNSIGNED NOT NULL DEFAULT 0,
        signal_count INT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY sdk_sessions_project_uid_unique (project_id, session_uid),
        KEY sdk_sessions_project_started_index (project_id, started_at),
        CONSTRAINT fk_sdk_sessions_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE sdk_events (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        session_id BIGINT UNSIGNED NOT NULL,
        type ENUM('pageview','signal','error') NOT NULL,
        name VARCHAR(255) NULL,
        message TEXT NULL,
        stack TEXT NULL,
        fix_prompt LONGTEXT NULL,
        fix_prompt_source ENUM('ai','template') NULL,
        occurred_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL,
        KEY sdk_events_session_id_index (session_id),
        KEY sdk_events_session_type_index (session_id, type),
        CONSTRAINT fk_sdk_events_session FOREIGN KEY (session_id) REFERENCES sdk_sessions (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
