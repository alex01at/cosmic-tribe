<?php

declare(strict_types=1);

/**
 * Durchsuchbares Ereignis-Log fürs Admin-Panel (siehe Migration 010). Schreibt NIE innerhalb der
 * Spieler-Transaktion - ein Logeintrag darf ein Spielerkommando nicht zum Scheitern bringen, nur
 * weil z.B. der message-Text zu lang war. Fehler beim Loggen selbst werden deshalb verschluckt.
 */
final class EventLog
{
    public static function log(PDO $pdo, string $category, string $message, ?int $universeId = null): void
    {
        try {
            $pdo->prepare('INSERT INTO event_log (universe_id, category, message) VALUES (?, ?, ?)')
                ->execute([$universeId, $category, mb_substr($message, 0, 500)]);
        } catch (Throwable $e) {
            // Logging darf nie die eigentliche Anfrage zum Absturz bringen.
        }
    }
}
