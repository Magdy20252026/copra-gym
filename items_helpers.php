<?php

function ensureItemsSchema(PDO $pdo)
{
    static $schemaReady = false;
    if ($schemaReady) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS items (
            id int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            has_quantity tinyint(1) NOT NULL DEFAULT 0,
            item_count int(11) UNSIGNED DEFAULT NULL,
            price decimal(10,2) DEFAULT NULL,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (id),
            UNIQUE KEY uniq_items_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $schemaReady = true;
}

function getAllItems(PDO $pdo)
{
    ensureItemsSchema($pdo);

    $stmt = $pdo->query("
        SELECT id, name, has_quantity, item_count, price, created_at, updated_at
        FROM items
        ORDER BY id DESC
    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
