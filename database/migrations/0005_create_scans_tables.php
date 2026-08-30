<?php

declare(strict_types=1);

return [
    "CREATE TABLE scans (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        project_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        type ENUM('url','upload') NOT NULL,
        target_url VARCHAR(2048) NULL,
        source_label VARCHAR(255) NULL,
        status ENUM('completed','failed') NOT NULL DEFAULT 'completed',
        score TINYINT UNSIGNED NULL,
        summary TEXT NULL,
        comprehensive_fix_prompt LONGTEXT NULL,
        comprehensive_fix_prompt_source ENUM('ai','template') NULL,
        error_message TEXT NULL,
        created_at DATETIME NOT NULL,
        completed_at DATETIME NULL,
        KEY scans_project_id_index (project_id),
        KEY scans_user_id_index (user_id),
        CONSTRAINT fk_scans_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
        CONSTRAINT fk_scans_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE scan_findings (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        scan_id BIGINT UNSIGNED NOT NULL,
        category VARCHAR(100) NOT NULL,
        severity ENUM('critical','high','medium','low','info') NOT NULL,
        title VARCHAR(255) NOT NULL,
        description TEXT NOT NULL,
        recommendation TEXT NULL,
        file_path VARCHAR(500) NULL,
        line_number INT UNSIGNED NULL,
        fix_prompt LONGTEXT NULL,
        fix_prompt_source ENUM('ai','template') NULL,
        created_at DATETIME NOT NULL,
        KEY scan_findings_scan_id_index (scan_id),
        KEY scan_findings_severity_index (severity),
        CONSTRAINT fk_scan_findings_scan FOREIGN KEY (scan_id) REFERENCES scans (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
