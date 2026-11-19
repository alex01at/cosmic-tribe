<?php

declare(strict_types=1);

final class Auth
{
    // Feste Realzeit-Dauer, UNABHÄNGIG von game_speed - der Sinn des Anfängerschutzes ist eine
    // echte Schonfrist für den Spieler, nicht eine am Welt-Tempo skalierte.
    private const BEGINNER_PROTECTION_HOURS = 24;

    private const USERNAME_MIN_LENGTH = 3;
    private const USERNAME_MAX_LENGTH = 20;

    public static function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Registrierung legt einen NEUEN Spieler samt Heimatbasis an. Eine bereits bekannte
     * device_id wird NIE einfach mit einem neuen Token "übernommen" - die device_id liegt neben
     * dem Token im selben localStorage des Clients, es gibt keinen legitimen Fall, in dem der
     * Token verloren geht, aber die device_id überlebt (Advisor-Review-Fund: sonst wäre das eine
     * Account-Übernahme). Ein Spieler, der sein Gerät wechselt, braucht einen eigenen
     * Wiederherstellungsweg (nicht Teil von Phase 2).
     */
    public static function register(PDO $pdo, string $deviceId, string $username): array
    {
        $deviceId = trim($deviceId);
        $username = trim($username);

        if ($deviceId === '' || strlen($deviceId) > 64) {
            Response::error('Ungültige Geräte-ID', 422);
        }
        if (strlen($username) < self::USERNAME_MIN_LENGTH || strlen($username) > self::USERNAME_MAX_LENGTH) {
            Response::error('Name muss ' . self::USERNAME_MIN_LENGTH . '-' . self::USERNAME_MAX_LENGTH . ' Zeichen lang sein', 422);
        }
        if (!preg_match('/^[\p{L}\p{N} _-]+$/u', $username)) {
            Response::error('Name enthält ungültige Zeichen', 422);
        }

        $existing = $pdo->prepare('SELECT 1 FROM players WHERE device_id = ?');
        $existing->execute([$deviceId]);
        if ($existing->fetch() !== false) {
            Response::error('Diese Geräte-ID ist bereits registriert', 409);
        }

        $pdo->beginTransaction();

        try {
            $playerStmt = $pdo->prepare('INSERT INTO players (device_id, username) VALUES (?, ?)');
            try {
                $playerStmt->execute([$deviceId, $username]);
            } catch (PDOException $e) {
                // Doppelter Name (UNIQUE) - derselbe Fang-statt-Vorab-Check wie bei Gildennamen
                // im Vorgängerprojekt, einzig verlässlich bei echter Gleichzeitigkeit.
                if ((int) $e->getCode() === 23000 || str_contains($e->getMessage(), '1062')) {
                    $pdo->rollBack();
                    Response::error('Name bereits vergeben', 409);
                }
                throw $e;
            }
            $playerId = (int) $pdo->lastInsertId();

            $token = self::generateToken();
            $pdo->prepare('INSERT INTO user_tokens (player_id, token_hash, device_label) VALUES (?, ?, ?)')
                ->execute([$playerId, self::hashToken($token), 'register']);

            [$sectorId, $position] = self::findFreeSlot($pdo);

            $protectedUntil = (new DateTimeImmutable())->modify('+' . self::BEGINNER_PROTECTION_HOURS . ' hours');
            $pdo->prepare(
                'INSERT INTO bases (player_id, sector_id, position, name, protected_until) VALUES (?, ?, ?, ?, ?)'
            )->execute([$playerId, $sectorId, $position, $username . 's Basis', $protectedUntil->format('Y-m-d H:i:s')]);
            $baseId = (int) $pdo->lastInsertId();

            $pdo->prepare("INSERT INTO base_buildings (base_id, building_type_id, level, status) VALUES (?, 1, 1, 'idle')")
                ->execute([$baseId]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return ['token' => $token];
    }

    /**
     * Zufälliger freier Slot - prüft alle VIER Belegungs-Tabellen (bases, npc_outposts,
     * asteroid_fields, nebulae), da ein Slot nur in genau einer davon stehen darf (siehe
     * Migration 001). Liest existierende Sektoren statt eine Galaxie-Größe anzunehmen, damit das
     * nicht an seed.php's GALAXY_SIZE gekoppelt ist.
     */
    private static function findFreeSlot(PDO $pdo): array
    {
        $sectors = $pdo->query('SELECT id FROM sectors ORDER BY RAND() LIMIT 100')->fetchAll(PDO::FETCH_COLUMN);
        if ($sectors === []) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::error('Galaxie ist noch nicht generiert', 500);
        }

        foreach ($sectors as $sectorId) {
            $occStmt = $pdo->prepare(
                'SELECT position FROM bases WHERE sector_id = ?
                 UNION SELECT position FROM npc_outposts WHERE sector_id = ?
                 UNION SELECT position FROM asteroid_fields WHERE sector_id = ?
                 UNION SELECT position FROM nebulae WHERE sector_id = ?'
            );
            $occStmt->execute([$sectorId, $sectorId, $sectorId, $sectorId]);
            $occupiedPositions = $occStmt->fetchAll(PDO::FETCH_COLUMN);

            for ($position = 0; $position < 8; $position++) {
                if (!in_array($position, $occupiedPositions, false)) {
                    return [(int) $sectorId, $position];
                }
            }
        }

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        Response::error('Keine freien Basis-Plätze mehr in der Galaxie', 500);
    }

    /** Resolves the player for the current request's Authorization: Bearer token, or rejects it. */
    public static function requirePlayer(PDO $pdo): array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            Response::error('Unauthorized', 401);
        }

        $tokenHash = self::hashToken($matches[1]);
        $stmt = $pdo->prepare(
            'SELECT p.* FROM players p
             JOIN user_tokens t ON t.player_id = p.id
             WHERE t.token_hash = ?'
        );
        $stmt->execute([$tokenHash]);
        $player = $stmt->fetch();

        if ($player === false) {
            Response::error('Unauthorized', 401);
        }

        $pdo->prepare('UPDATE user_tokens SET last_used_at = UTC_TIMESTAMP() WHERE token_hash = ?')
            ->execute([$tokenHash]);

        return $player;
    }
}
