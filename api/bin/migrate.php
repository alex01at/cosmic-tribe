<?php

declare(strict_types=1);

require __DIR__ . '/../src/Database.php';

$pdo = Database::connection();

$pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
    filename VARCHAR(120) PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$applied = $pdo->query('SELECT filename FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);

$files = glob(__DIR__ . '/../migrations/*.sql');
sort($files);

foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $applied, true)) {
        continue;
    }

    echo "Wende an: $name\n";
    $sql = file_get_contents($file);
    $pdo->exec($sql);
    $pdo->prepare('INSERT INTO schema_migrations (filename) VALUES (?)')->execute([$name]);
}

echo "Fertig.\n";
