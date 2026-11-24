<?php

declare(strict_types=1);

/**
 * Betreiber-Verwaltung: Universen (Welten)/Katalogpreise/Spielerliste. Bewusst eine eigene
 * Klasse statt in GameEngine - das hier ist Betriebs-CRUD, keine Spiellogik (keine lazy
 * Zeit-Verarbeitung, keine Sperren/Transaktionen wie bei Spieleraktionen noetig).
 */
final class AdminEngine
{
    public function __construct(private PDO $pdo, private array $defaultConfig)
    {
    }

    /** Geschwindigkeit des Universums, in dem ein Spieler steckt - ersetzt die frühere globale game_speed. */
    public function getUniverseGameSpeed(int $universeId): float
    {
        $stmt = $this->pdo->prepare('SELECT game_speed FROM universes WHERE id = ?');
        $stmt->execute([$universeId]);
        $value = $stmt->fetchColumn();

        return $value !== false ? (float) $value : (float) $this->defaultConfig['app']['game_speed'];
    }

    // --- Universen -------------------------------------------------------

    public function listUniverses(): array
    {
        $rows = $this->pdo->query(
            "SELECT u.*, (SELECT COUNT(*) FROM players WHERE universe_id = u.id) AS player_count
             FROM universes u ORDER BY u.id ASC"
        )->fetchAll();

        return array_map(fn ($u) => [
            'id' => (int) $u['id'],
            'name' => $u['name'],
            'status' => $u['status'],
            'maxPlayers' => (int) $u['max_players'],
            'galaxySize' => (int) $u['galaxy_size'],
            'gameSpeed' => (float) $u['game_speed'],
            'playerCount' => (int) $u['player_count'],
            'createdAt' => $u['created_at'],
        ], $rows);
    }

    public function createUniverse(string $name, int $maxPlayers, int $galaxySize, float $gameSpeed): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 60) {
            Response::error('Name muss 1-60 Zeichen haben', 422);
        }
        if ($maxPlayers < 1 || $maxPlayers > 100000) {
            Response::error('Max. Spieler muss zwischen 1 und 100000 liegen', 422);
        }
        if ($galaxySize < 5 || $galaxySize > 60) {
            Response::error('Rastergröße muss zwischen 5 und 60 liegen', 422);
        }
        if ($gameSpeed <= 0 || $gameSpeed > 1000) {
            Response::error('Geschwindigkeit muss zwischen 0 und 1000 liegen', 422);
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'INSERT INTO universes (name, max_players, galaxy_size, game_speed) VALUES (?, ?, ?, ?)'
            )->execute([$name, $maxPlayers, $galaxySize, $gameSpeed]);
            $universeId = (int) $this->pdo->lastInsertId();

            GalaxyGenerator::generate($this->pdo, $universeId, $galaxySize);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->listUniverses();
    }

    public function updateUniverse(int $id, array $fields): array
    {
        $status = (string) ($fields['status'] ?? '');
        if (!in_array($status, ['open', 'closed'], true)) {
            Response::error('Ungültiger Status', 422);
        }
        $maxPlayers = (int) ($fields['maxPlayers'] ?? 0);
        if ($maxPlayers < 1 || $maxPlayers > 100000) {
            Response::error('Max. Spieler muss zwischen 1 und 100000 liegen', 422);
        }
        $gameSpeed = (float) ($fields['gameSpeed'] ?? 0);
        if ($gameSpeed <= 0 || $gameSpeed > 1000) {
            Response::error('Geschwindigkeit muss zwischen 0 und 1000 liegen', 422);
        }
        $name = trim((string) ($fields['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 60) {
            Response::error('Name muss 1-60 Zeichen haben', 422);
        }

        $existsStmt = $this->pdo->prepare('SELECT 1 FROM universes WHERE id = ?');
        $existsStmt->execute([$id]);
        if ($existsStmt->fetch() === false) {
            Response::error('Universum nicht gefunden', 404);
        }

        $this->pdo->prepare(
            'UPDATE universes SET name = ?, status = ?, max_players = ?, game_speed = ? WHERE id = ?'
        )->execute([$name, $status, $maxPlayers, $gameSpeed, $id]);

        return $this->listUniverses();
    }

    public function getCatalog(): array
    {
        $buildingTypes = $this->pdo->query('SELECT * FROM building_types ORDER BY id')->fetchAll();
        $buildingLevels = $this->pdo->query('SELECT * FROM building_levels ORDER BY building_type_id, level')->fetchAll();
        $shipTypes = $this->pdo->query('SELECT * FROM ship_types ORDER BY id')->fetchAll();

        return [
            'buildingTypes' => array_map(fn ($b) => [
                'id' => (int) $b['id'], 'name' => $b['name'], 'effect' => $b['effect'],
            ], $buildingTypes),
            'buildingLevels' => array_map(fn ($l) => [
                'buildingTypeId' => (int) $l['building_type_id'],
                'level' => (int) $l['level'],
                'costTitan' => (int) $l['cost_titan'],
                'costDeuterium' => (int) $l['cost_deuterium'],
                'costCredits' => (int) $l['cost_credits'],
                'buildSeconds' => (int) $l['build_seconds'],
                'effectAmount' => (int) $l['effect_amount'],
            ], $buildingLevels),
            'shipTypes' => array_map(fn ($s) => [
                'id' => (int) $s['id'], 'name' => $s['name'],
                'attack' => (int) $s['attack'], 'shield' => (int) $s['shield'], 'hull' => (int) $s['hull'],
                'speed' => (int) $s['speed'], 'cargoCapacity' => (int) $s['cargo_capacity'],
                'costTitan' => (int) $s['cost_titan'], 'costDeuterium' => (int) $s['cost_deuterium'], 'costCredits' => (int) $s['cost_credits'],
                'buildSeconds' => (int) $s['build_seconds'],
                'minShipyardLevel' => (int) $s['min_shipyard_level'],
            ], $shipTypes),
        ];
    }

    public function updateBuildingLevel(int $buildingTypeId, int $level, array $fields): array
    {
        $stmt = $this->pdo->prepare(
            'UPDATE building_levels SET cost_titan = ?, cost_deuterium = ?, cost_credits = ?, build_seconds = ?, effect_amount = ?
             WHERE building_type_id = ? AND level = ?'
        );
        $stmt->execute([
            (int) $fields['costTitan'], (int) $fields['costDeuterium'], (int) $fields['costCredits'],
            (int) $fields['buildSeconds'], (int) $fields['effectAmount'],
            $buildingTypeId, $level,
        ]);
        if ($stmt->rowCount() === 0) {
            Response::error('Gebäude-Level nicht gefunden', 404);
        }

        return $this->getCatalog();
    }

    public function updateShipType(int $shipTypeId, array $fields): array
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ship_types SET attack = ?, shield = ?, hull = ?, speed = ?, cargo_capacity = ?,
                cost_titan = ?, cost_deuterium = ?, cost_credits = ?, build_seconds = ?, min_shipyard_level = ?
             WHERE id = ?'
        );
        $stmt->execute([
            (int) $fields['attack'], (int) $fields['shield'], (int) $fields['hull'],
            (int) $fields['speed'], (int) $fields['cargoCapacity'],
            (int) $fields['costTitan'], (int) $fields['costDeuterium'], (int) $fields['costCredits'],
            (int) $fields['buildSeconds'], (int) $fields['minShipyardLevel'],
            $shipTypeId,
        ]);
        if ($stmt->rowCount() === 0) {
            Response::error('Schiffstyp nicht gefunden', 404);
        }

        return $this->getCatalog();
    }

    public function listPlayers(?int $universeId = null): array
    {
        $sql = "SELECT p.id, p.universe_id, p.username, p.created_at,
                       COUNT(b.id) AS base_count,
                       COALESCE(SUM(b.titan), 0) AS total_titan,
                       COALESCE(SUM(b.deuterium), 0) AS total_deuterium,
                       COALESCE(SUM(b.credits), 0) AS total_credits
                FROM players p
                LEFT JOIN bases b ON b.player_id = p.id";
        $params = [];
        if ($universeId !== null) {
            $sql .= ' WHERE p.universe_id = ?';
            $params[] = $universeId;
        }
        $sql .= ' GROUP BY p.id ORDER BY p.created_at DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map(fn ($r) => [
            'id' => (int) $r['id'],
            'universeId' => (int) $r['universe_id'],
            'username' => $r['username'],
            'createdAt' => $r['created_at'],
            'baseCount' => (int) $r['base_count'],
            'totalTitan' => (int) $r['total_titan'],
            'totalDeuterium' => (int) $r['total_deuterium'],
            'totalCredits' => (int) $r['total_credits'],
        ], $stmt->fetchAll());
    }

    /** Basen eines Spielers mit ihren Rohstoffen - Grundlage für den Basen-Editor im Panel. */
    public function getPlayerBases(int $playerId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, titan, deuterium, credits FROM bases WHERE player_id = ? ORDER BY id ASC'
        );
        $stmt->execute([$playerId]);

        return array_map(fn ($b) => [
            'id' => (int) $b['id'],
            'name' => $b['name'],
            'titan' => (int) $b['titan'],
            'deuterium' => (int) $b['deuterium'],
            'credits' => (int) $b['credits'],
        ], $stmt->fetchAll());
    }

    /** Admin-Direktbearbeitung der Rohstoffe einer Basis - unabhängig vom normalen Spielfluss. */
    public function updateBaseResources(int $baseId, int $titan, int $deuterium, int $credits): array
    {
        if ($titan < 0 || $deuterium < 0 || $credits < 0) {
            Response::error('Rohstoffe dürfen nicht negativ sein', 422);
        }

        $stmt = $this->pdo->prepare('UPDATE bases SET titan = ?, deuterium = ?, credits = ? WHERE id = ?');
        $stmt->execute([$titan, $deuterium, $credits, $baseId]);

        $checkStmt = $this->pdo->prepare('SELECT player_id FROM bases WHERE id = ?');
        $checkStmt->execute([$baseId]);
        $playerId = $checkStmt->fetchColumn();
        if ($playerId === false) {
            Response::error('Basis nicht gefunden', 404);
        }

        return $this->getPlayerBases((int) $playerId);
    }

    // Loescht einen Spieler samt allem, was an ihm/seinen Basen haengt - dieselbe
    // Aufraeum-Reihenfolge wie das Testdaten-Cleanup in tests.php (FK-sicher: Flotten vor
    // Spieler, Basis-Unterzeilen vor Basen).
    public function deletePlayer(int $playerId): void
    {
        $this->pdo->beginTransaction();
        try {
            $baseIds = $this->pdo->prepare('SELECT id FROM bases WHERE player_id = ?');
            $baseIds->execute([$playerId]);
            $baseIds = array_column($baseIds->fetchAll(), 'id');

            $fleetIds = $this->pdo->prepare('SELECT id FROM fleets WHERE player_id = ?');
            $fleetIds->execute([$playerId]);
            $fleetIds = array_column($fleetIds->fetchAll(), 'id');

            if ($fleetIds) {
                $ph = implode(',', array_fill(0, count($fleetIds), '?'));
                $this->pdo->prepare("DELETE FROM fleet_ships WHERE fleet_id IN ($ph)")->execute($fleetIds);
                $this->pdo->prepare("DELETE FROM fleets WHERE id IN ($ph)")->execute($fleetIds);
            }

            $this->pdo->prepare('DELETE FROM combat_reports WHERE attacker_player_id = ? OR defender_player_id = ?')
                ->execute([$playerId, $playerId]);
            $this->pdo->prepare('DELETE FROM alliance_members WHERE player_id = ?')->execute([$playerId]);
            $this->pdo->prepare('DELETE FROM alliances WHERE leader_player_id = ?')->execute([$playerId]);

            if ($baseIds) {
                $ph = implode(',', array_fill(0, count($baseIds), '?'));
                $this->pdo->prepare("DELETE FROM base_build_queue WHERE base_id IN ($ph)")->execute($baseIds);
                $this->pdo->prepare("DELETE FROM base_ship_queue WHERE base_id IN ($ph)")->execute($baseIds);
                $this->pdo->prepare("DELETE FROM base_buildings WHERE base_id IN ($ph)")->execute($baseIds);
                $this->pdo->prepare("DELETE FROM base_ships WHERE base_id IN ($ph)")->execute($baseIds);
                $this->pdo->prepare("DELETE FROM bases WHERE id IN ($ph)")->execute($baseIds);
            }

            $this->pdo->prepare('DELETE FROM user_tokens WHERE player_id = ?')->execute([$playerId]);
            $this->pdo->prepare('DELETE FROM players WHERE id = ?')->execute([$playerId]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
