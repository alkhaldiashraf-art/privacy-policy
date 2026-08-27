<?php

declare(strict_types=1);

return [
    "CREATE TABLE audit_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NULL,
        project_id BIGINT UNSIGNED NULL,
        action VARCHAR(100) NOT NULL,
        metadata JSON NULL,
        ip_address VARCHAR(45) NULL,
        created_at DATETIME NOT NULL,
        KEY audit_logs_user_id_index (user_id),
        KEY audit_logs_project_id_index (project_id),
        KEY audit_logs_action_index (action),
        CONSTRAINT fk_audit_logs_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
        CONSTRAINT fk_audit_logs_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
