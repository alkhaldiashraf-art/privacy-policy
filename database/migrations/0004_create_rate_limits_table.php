<?php

declare(strict_types=1);

return [
    "CREATE TABLE rate_limits (
        identifier VARCHAR(255) NOT NULL,
        action VARCHAR(100) NOT NULL,
        attempts INT UNSIGNED NOT NULL DEFAULT 0,
        window_start DATETIME NOT NULL,
        PRIMARY KEY (identifier, action)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
