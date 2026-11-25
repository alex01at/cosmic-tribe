<?php

declare(strict_types=1);

/**
 * Einfacher, betreiberweiter Wortfilter (siehe Migration 012) - bewusst nur ein case-insensitiver
 * Teilstring-Abgleich, kein Leetspeak-/Unicode-Normalisieren. Das fängt die meisten Fälle, ohne
 * durch Übernormalisierung legitime Wörter zu blockieren (z.B. ein Substring-Treffer in einem
 * harmlosen längeren Wort) - bei Bedarf passt der Betreiber die Liste im Admin-Panel selbst an.
 */
final class ProfanityFilter
{
    /** Gibt das zuerst gefundene verbotene Wort zurück, oder null, wenn nichts gefunden wurde. */
    public static function findBannedWord(PDO $pdo, string $text): ?string
    {
        $words = $pdo->query('SELECT word FROM banned_words')->fetchAll(PDO::FETCH_COLUMN);
        if ($words === []) {
            return null;
        }

        $haystack = mb_strtolower($text);
        foreach ($words as $word) {
            if ($word !== '' && str_contains($haystack, mb_strtolower($word))) {
                return $word;
            }
        }

        return null;
    }

    /** Lehnt die Anfrage ab (422), falls $text ein verbotenes Wort enthält - loggt den Versuch. */
    public static function assertClean(PDO $pdo, string $text, string $context, ?int $universeId = null): void
    {
        $hit = self::findBannedWord($pdo, $text);
        if ($hit === null) {
            return;
        }

        EventLog::log($pdo, 'moderation', "Blockiert ({$context}): enthielt verbotenen Begriff „{$hit}“. Text: „{$text}“", $universeId);
        Response::error('Enthält einen nicht erlaubten Begriff', 422);
    }
}
