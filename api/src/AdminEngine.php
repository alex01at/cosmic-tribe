<?php

declare(strict_types=1);

/**
 * Betreiber-Verwaltung: Spielgeschwindigkeit/Katalogpreise/Spielerliste. Bewusst eine eigene
 * Klasse statt in GameEngine - das hier ist Betriebs-CRUD, keine Spiellogik (keine lazy
 * Zeit-Verarbeitung, keine Sperren/Transaktionen wie bei Spieleraktionen noetig).
 */
final class AdminEngine
{
    public function __construct(private PDO $pdo, private array $defaultConfig)
    {
    }

    public function getSettings(): array
    {
        $stmt = $this->pdo->query('SELECT setting_key, setting_value FROM game_settings');
        $overrides = [];
        foreach ($stmt->fetchAll() as $row) {
            $overrides[$row['setting_key']] = $row['setting_value'];
        }

        return [
            'gameSpeed' => isset($overrides['game_speed']) ? (float) $overrides['game_speed'] : (float) $this->defaultConfig['app']['game_speed'],
        ];
    }

    public function updateSettings(array $values): array
    {
        if (isset($values['gameSpeed'])) {
            $gameSpeed = (float) $values['gameSpeed'];
            if ($gameSpeed <= 0 || $gameSpeed > 1000) {
                Response::error('Geschwindigkeit muss zwischen 0 und 1000 liegen', 422);
            }
            $this->upsertSetting('game_speed', (string) $gameSpeed);
        }

        return $this->getSettings();
    }

    private function upsertSetting(string $key, string $value): void
    {
        $this->pdo->prepare(
            'INSERT INTO game_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        )->execute([$key, $value]);
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

    public function listPlayers(): array
    {
        $stmt = $this->pdo->query(
            "SELECT p.id, p.username, p.created_at,
                    COUNT(b.id) AS base_count,
                    COALESCE(SUM(b.titan), 0) AS total_titan,
                    COALESCE(SUM(b.deuterium), 0) AS total_deuterium,
                    COALESCE(SUM(b.credits), 0) AS total_credits
             FROM players p
             LEFT JOIN bases b ON b.player_id = p.id
             GROUP BY p.id
             ORDER BY p.created_at DESC"
        );

        return array_map(fn ($r) => [
            'id' => (int) $r['id'],
            'username' => $r['username'],
            'createdAt' => $r['created_at'],
            'baseCount' => (int) $r['base_count'],
            'totalTitan' => (int) $r['total_titan'],
            'totalDeuterium' => (int) $r['total_deuterium'],
            'totalCredits' => (int) $r['total_credits'],
        ], $stmt->fetchAll());
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
