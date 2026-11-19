<?php

declare(strict_types=1);

final class Database
{
    private static ?PDO $instance = null;

    public static function connection(): PDO
    {
        if (self::$instance === null) {
            $config = require __DIR__ . '/../config/config.php';
            $db = $config['db'];
            $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset={$db['charset']}";
            self::$instance = new PDO($dsn, $db['user'], $db['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            // PHP läuft in UTC; ohne das hier würde die Verbindung die lokale
            // Server-Zeitzone verwenden und jeder started_at/finishes_at/last_tick_at-Vergleich
            // (Bauzeiten, Flugzeiten, Produktion) wäre je nach Zeitzonen-Differenz falsch.
            self::$instance->exec("SET time_zone = '+00:00'");
        }

        return self::$instance;
    }
}
