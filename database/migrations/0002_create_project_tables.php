<?php

declare(strict_types=1);

return [
    "CREATE TABLE projects (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        owner_id BIGINT UNSIGNED NOT NULL,
        name VARCHAR(255) NOT NULL,
        url VARCHAR(2048) NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        KEY projects_owner_id_index (owner_id),
        CONSTRAINT fk_projects_owner FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE project_members (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        project_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        role ENUM('owner','admin','developer','viewer') NOT NULL DEFAULT 'viewer',
        created_at DATETIME NOT NULL,
        UNIQUE KEY project_members_unique (project_id, user_id),
        KEY project_members_user_id_index (user_id),
        CONSTRAINT fk_project_members_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
        CONSTRAINT fk_project_members_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE project_profiles (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        project_id BIGINT UNSIGNED NOT NULL,
        tagline VARCHAR(255) NULL,
        toolchain VARCHAR(100) NULL,
        hosting_platform VARCHAR(100) NULL,
        database_platform VARCHAR(100) NULL,
        ai_provider VARCHAR(100) NULL,
        repository_url VARCHAR(255) NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY project_profiles_project_unique (project_id),
        CONSTRAINT fk_project_profiles_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
