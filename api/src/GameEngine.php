<?php

declare(strict_types=1);

final class GameEngine
{
    private const TICK_SECONDS = 300; // vor game_speed-Skalierung
    private const MAX_BUILDING_LEVEL = 5;
    private const MAX_BUILD_QUEUE_LENGTH = 5; // aktiver Baujob + wartend zusammen, je Basis

    // Flache Grundproduktion UNABHÄNGIG vom Raffinerie-/Sammler-Level (Advisor-Review-Fund: ohne
    // das softlockt sich JEDE mögliche erste Bau-Reihenfolge selbst, weil die Start-Rohstoffe
    // nicht reichen, um die jeweils ANDERE Produktionsart je zu bezahlen - das Spiel wäre am
    // ersten Tag für jeden neuen Spieler unspielbar gewesen).
    private const BASE_TITAN_PER_TICK = 10;
    private const BASE_DEUTERIUM_PER_TICK = 8;

    private const PLUNDER_MAX_PERCENT = 50; // vom aktuellen Bestand, UND gedeckelt durch Frachtraum
    private const NPC_LOOT_REGEN_SECONDS = 3600; // vor game_speed-Skalierung

    private const TRAVEL_SECONDS_PER_DISTANCE_AT_SPEED_100 = 600; // vor game_speed-Skalierung, Platzhalter
    private const DEUTERIUM_FUEL_PER_SHIP_PER_DISTANCE = 2;

    public function __construct(private PDO $pdo, private float $gameSpeed)
    {
    }

    private function scaledSeconds(int $baseSeconds): int
    {
        return max(1, (int) round($baseSeconds / $this->gameSpeed));
    }

    // ============================================================
    // Globale Flotten-Auflösung - MUSS am Anfang JEDER Anfrage laufen (lesend oder schreibend,
    // für JEDEN Spieler), nicht nur für den gerade angemeldeten Spieler. Sonst könnte Spieler B
    // (Verteidiger) zwischen dem fälligen Angriffs-Zeitpunkt T1 und seinem nächsten eigenen
    // Request bei T2 noch Dinge tun, die eigentlich NACH der Schlacht hätten passieren müssen
    // (Ressourcen ausgeben, die geplündert worden wären; Schiffe aus dem Hangar wegschicken, die
    // eigentlich verteidigt hätten) - siehe Advisor-Review.
    // ============================================================
    public function resolveGlobalArrivals(): void
    {
        $lockAcquired = (bool) $this->pdo->query("SELECT GET_LOCK('starforge_fleet_resolution', 5)")->fetchColumn();
        if (!$lockAcquired) {
            // Eine andere Anfrage löst gerade auf - nicht blockierend warten, deren Ergebnis ist
            // committed, bevor unsere Anfrage fertig ist (siehe README-Hinweis zu dieser
            // Vereinfachung).
            return;
        }

        try {
            $due = $this->pdo->query(
                "SELECT id FROM fleets WHERE status IN ('outbound', 'returning') AND arrives_at <= UTC_TIMESTAMP()
                 ORDER BY arrives_at ASC, id ASC"
            )->fetchAll(PDO::FETCH_COLUMN);

            foreach ($due as $fleetId) {
                $this->resolveFleetArrival((int) $fleetId);
            }
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('starforge_fleet_resolution')");
        }
    }

    private function resolveFleetArrival(int $fleetId): void
    {
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare('SELECT * FROM fleets WHERE id = ? FOR UPDATE');
            $stmt->execute([$fleetId]);
            $fleet = $stmt->fetch();

            // Zwischen dem Einsammeln der fälligen IDs und hier könnte sich (theoretisch) der
            // Status schon geändert haben - defensiv nochmal prüfen, bevor irgendetwas passiert.
            if ($fleet === false || !in_array($fleet['status'], ['outbound', 'returning'], true)) {
                $this->pdo->commit();

                return;
            }

            if ($fleet['status'] === 'returning') {
                $this->resolveReturn($fleet);
            } else {
                $this->resolveOutboundArrival($fleet);
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function resolveReturn(array $fleet): void
    {
        $shipsStmt = $this->pdo->prepare('SELECT ship_type_id, quantity FROM fleet_ships WHERE fleet_id = ?');
        $shipsStmt->execute([$fleet['id']]);
        $ships = $shipsStmt->fetchAll();

        // origin_sector_id/origin_position des Spielers - der Fleet-Ursprung ist immer eine
        // eigene Basis (Kolonisieren/Plündern starten nur von dort), kein Fremdziel.
        $baseStmt = $this->pdo->prepare(
            'SELECT id FROM bases WHERE sector_id = ? AND position = ? AND player_id = ? FOR UPDATE'
        );
        $baseStmt->execute([$fleet['origin_sector_id'], $fleet['origin_position'], $fleet['player_id']]);
        $baseId = $baseStmt->fetchColumn();

        if ($baseId !== false) {
            $this->pdo->prepare(
                'UPDATE bases SET titan = titan + ?, deuterium = deuterium + ?, credits = credits + ? WHERE id = ?'
            )->execute([$fleet['cargo_titan'], $fleet['cargo_deuterium'], $fleet['cargo_credits'], $baseId]);

            foreach ($ships as $s) {
                if ((int) $s['quantity'] <= 0) {
                    continue;
                }
                $this->pdo->prepare(
                    'INSERT INTO base_ships (base_id, ship_type_id, quantity) VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)'
                )->execute([$baseId, $s['ship_type_id'], $s['quantity']]);
            }
        }
        // Falls die Heimatbasis in der Zwischenzeit nicht mehr existiert (in Phase 2 nicht
        // möglich, Basen werden nie gelöscht) gehen Fracht/Schiffe verloren statt den Request
        // mit einem Fehler abzubrechen - defensiv für eine spätere Phase mit Basis-Verlust.

        $this->pdo->prepare('DELETE FROM fleet_ships WHERE fleet_id = ?')->execute([$fleet['id']]);
        $this->pdo->prepare('DELETE FROM fleets WHERE id = ?')->execute([$fleet['id']]);
    }

    private function resolveOutboundArrival(array $fleet): void
    {
        $shipsStmt = $this->pdo->prepare(
            'SELECT fs.ship_type_id, fs.quantity, st.* FROM fleet_ships fs
             JOIN ship_types st ON st.id = fs.ship_type_id
             WHERE fs.fleet_id = ?'
        );
        $shipsStmt->execute([$fleet['id']]);
        $fleetShips = array_map(fn ($r) => ['type' => $r, 'quantity' => (int) $r['quantity']], $shipsStmt->fetchAll());

        switch ($fleet['mission']) {
            case 'station':
                $this->resolveStationArrival($fleet, $fleetShips);
                break;
            case 'transport':
                $this->resolveTransportArrival($fleet);
                break;
            case 'colonize':
                $this->resolveColonizeArrival($fleet, $fleetShips);
                break;
            case 'attack':
            case 'plunder':
                $this->resolveCombatArrival($fleet, $fleetShips);
                break;
        }
    }

    private function resolveStationArrival(array $fleet, array $fleetShips): void
    {
        $baseStmt = $this->pdo->prepare(
            'SELECT id FROM bases WHERE sector_id = ? AND position = ? AND player_id = ? FOR UPDATE'
        );
        $baseStmt->execute([$fleet['target_sector_id'], $fleet['target_position'], $fleet['player_id']]);
        $baseId = $baseStmt->fetchColumn();

        if ($baseId !== false) {
            foreach ($fleetShips as $s) {
                $this->pdo->prepare(
                    'INSERT INTO base_ships (base_id, ship_type_id, quantity) VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)'
                )->execute([$baseId, $s['type']['id'], $s['quantity']]);
            }
        }
        // Zielbasis existiert nicht mehr / gehört nicht mehr dem Spieler: Schiffe gehen verloren
        // statt die Auflösung abzubrechen (Basen werden in Phase 2 nie gelöscht, praktisch nie
        // der Fall) - keine Rückreise bei 'station', laut Missionstabelle im Advisor-Review.

        $this->pdo->prepare('DELETE FROM fleet_ships WHERE fleet_id = ?')->execute([$fleet['id']]);
        $this->pdo->prepare('DELETE FROM fleets WHERE id = ?')->execute([$fleet['id']]);
    }

    private function resolveTransportArrival(array $fleet): void
    {
        $baseStmt = $this->pdo->prepare(
            'SELECT id FROM bases WHERE sector_id = ? AND position = ? AND player_id = ? FOR UPDATE'
        );
        $baseStmt->execute([$fleet['target_sector_id'], $fleet['target_position'], $fleet['player_id']]);
        $baseId = $baseStmt->fetchColumn();

        if ($baseId !== false) {
            $this->pdo->prepare(
                'UPDATE bases SET titan = titan + ?, deuterium = deuterium + ?, credits = credits + ? WHERE id = ?'
            )->execute([$fleet['cargo_titan'], $fleet['cargo_deuterium'], $fleet['cargo_credits'], $baseId]);
        }

        // Fracht ist jetzt geliefert - die Flotte tritt die Rückreise LEER an (Fracht wird NICHT
        // ein zweites Mal beim Zurückkehren gutgeschrieben, siehe resolveReturn()).
        $travelSeconds = $this->travelSeconds($fleet);
        $this->pdo->prepare(
            "UPDATE fleets SET status = 'returning', cargo_titan = 0, cargo_deuterium = 0, cargo_credits = 0, arrives_at = ?
             WHERE id = ?"
        )->execute([
            (new DateTimeImmutable($fleet['arrives_at']))->modify("+{$travelSeconds} seconds")->format('Y-m-d H:i:s'),
            $fleet['id'],
        ]);
    }

    private function resolveColonizeArrival(array $fleet, array $fleetShips): void
    {
        // Alle vier Belegungs-Tabellen prüfen (siehe Migration 001) - Basis-vs-Basis-Rennen fängt
        // zusätzlich noch die UNIQUE(sector_id, position)-Constraint beim INSERT ab.
        $occupiedStmt = $this->pdo->prepare(
            'SELECT
                (SELECT COUNT(*) FROM bases WHERE sector_id = ? AND position = ?) +
                (SELECT COUNT(*) FROM npc_outposts WHERE sector_id = ? AND position = ?) +
                (SELECT COUNT(*) FROM nebulae WHERE sector_id = ? AND position = ?) AS occupied'
        );
        $occupiedStmt->execute([
            $fleet['target_sector_id'], $fleet['target_position'],
            $fleet['target_sector_id'], $fleet['target_position'],
            $fleet['target_sector_id'], $fleet['target_position'],
        ]);
        $occupied = (int) $occupiedStmt->fetchColumn();

        $succeeded = false;
        if ($occupied === 0) {
            try {
                $playerStmt = $this->pdo->prepare('SELECT username FROM players WHERE id = ?');
                $playerStmt->execute([$fleet['player_id']]);
                $username = $playerStmt->fetchColumn();

                // Asteroidenfeld-Bonus übernehmen und die Zeile dabei löschen (siehe Migration
                // 003-Kommentar - der Slot gehört danach nur noch der neuen Basis).
                $bonusStmt = $this->pdo->prepare(
                    'SELECT bonus_percent FROM asteroid_fields WHERE sector_id = ? AND position = ?'
                );
                $bonusStmt->execute([$fleet['target_sector_id'], $fleet['target_position']]);
                $bonus = (int) ($bonusStmt->fetchColumn() ?: 0);

                $this->pdo->prepare(
                    'INSERT INTO bases (player_id, sector_id, position, name, resource_bonus_percent) VALUES (?, ?, ?, ?, ?)'
                )->execute([
                    $fleet['player_id'], $fleet['target_sector_id'], $fleet['target_position'],
                    $username . 's Kolonie', $bonus,
                ]);
                $newBaseId = (int) $this->pdo->lastInsertId();

                $this->pdo->prepare("INSERT INTO base_buildings (base_id, building_type_id, level, status) VALUES (?, 1, 1, 'idle')")
                    ->execute([$newBaseId]);

                $this->pdo->prepare('DELETE FROM asteroid_fields WHERE sector_id = ? AND position = ?')
                    ->execute([$fleet['target_sector_id'], $fleet['target_position']]);

                // Überlebende Eskorte (alles außer dem verbrauchten Kolonieschiff, identifiziert
                // über den Katalog-Namen) verteidigt ab sofort die neue Kolonie.
                foreach ($fleetShips as $s) {
                    if ($s['type']['name'] === 'Kolonieschiff') {
                        continue; // verbraucht
                    }
                    $this->pdo->prepare(
                        'INSERT INTO base_ships (base_id, ship_type_id, quantity) VALUES (?, ?, ?)
                         ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)'
                    )->execute([$newBaseId, $s['type']['id'], $s['quantity']]);
                }

                $succeeded = true;
            } catch (PDOException $e) {
                // UNIQUE(sector_id, position) hat zugeschlagen - ein paralleler Kolonisierungs-
                // versuch war schneller. Kolonieschiff gilt trotzdem als verbraucht (siehe unten).
                $succeeded = false;
            }
        }

        // Fehlgeschlagene Kolonisierung: bewusste Vereinfachung für Phase 2 - das Kolonieschiff
        // UND die Eskorte gehen verloren (keine Rückreise), statt eine komplexere
        // Teil-Rückgabe-Logik zu bauen. Kann in einer späteren Phase verfeinert werden.
        $this->pdo->prepare('DELETE FROM fleet_ships WHERE fleet_id = ?')->execute([$fleet['id']]);
        $this->pdo->prepare('DELETE FROM fleets WHERE id = ?')->execute([$fleet['id']]);
    }

    private function resolveCombatArrival(array $fleet, array $attackerShips): void
    {
        $targetBaseStmt = $this->pdo->prepare('SELECT * FROM bases WHERE sector_id = ? AND position = ? FOR UPDATE');
        $targetBaseStmt->execute([$fleet['target_sector_id'], $fleet['target_position']]);
        $targetBase = $targetBaseStmt->fetch();

        $arrivesAt = new DateTimeImmutable($fleet['arrives_at']);
        $loot = ['titan' => 0, 'deuterium' => 0, 'credits' => 0];
        $defenderPlayerId = null;
        $reportShieldBonus = 0;
        $defenderShips = [];

        if ($targetBase !== false) {
            // Basis GENAU auf den Ankunftszeitpunkt vorspulen, nicht auf "jetzt" - sonst würde
            // ein Verteidiger, der zwischen Angriff und seinem nächsten Login etwas baut, dem
            // Angreifer rückwirkend eine spätere (oder frühere) Basis-Stufe zeigen, als es beim
            // tatsächlichen Gefecht der Fall war (Advisor-Review-Fund, zentraler Punkt).
            $this->advanceBaseTo((int) $targetBase['id'], $arrivesAt);
            $targetBaseStmt->execute([$fleet['target_sector_id'], $fleet['target_position']]);
            $targetBase = $targetBaseStmt->fetch();

            $defenderPlayerId = (int) $targetBase['player_id'];

            $shieldStmt = $this->pdo->prepare(
                "SELECT bb.level FROM base_buildings bb
                 JOIN building_types bt ON bt.id = bb.building_type_id
                 WHERE bb.base_id = ? AND bt.effect = 'shield'"
            );
            $shieldStmt->execute([$targetBase['id']]);
            $shieldLevel = (int) ($shieldStmt->fetchColumn() ?: 0);
            if ($shieldLevel > 0) {
                $amountStmt = $this->pdo->prepare(
                    "SELECT effect_amount FROM building_levels bml
                     JOIN building_types bt ON bt.id = bml.building_type_id
                     WHERE bt.effect = 'shield' AND bml.level = ?"
                );
                $amountStmt->execute([$shieldLevel]);
                $reportShieldBonus = (int) $amountStmt->fetchColumn();
            }

            $defShipsStmt = $this->pdo->prepare(
                'SELECT bs.ship_type_id, bs.quantity, st.* FROM base_ships bs
                 JOIN ship_types st ON st.id = bs.ship_type_id
                 WHERE bs.base_id = ? AND bs.quantity > 0'
            );
            $defShipsStmt->execute([$targetBase['id']]);
            $defenderShips = array_map(fn ($r) => ['type' => $r, 'quantity' => (int) $r['quantity']], $defShipsStmt->fetchAll());
        } else {
            $npcStmt = $this->pdo->prepare('SELECT * FROM npc_outposts WHERE sector_id = ? AND position = ? FOR UPDATE');
            $npcStmt->execute([$fleet['target_sector_id'], $fleet['target_position']]);
            $npc = $npcStmt->fetch();

            if ($npc !== false) {
                $defenderShips = CombatResolver::npcDefenseAsShip((int) $npc['defense_strength']);
            }
        }

        $combat = CombatResolver::resolve($attackerShips, $defenderShips, $reportShieldBonus, $fleet['energy_preset']);

        // Verteidiger-Verluste gelten IMMER (auch bei defender_win/draw - er hat trotzdem
        // Schaden genommen), nicht nur bei einem Angreifer-Sieg - das war ein echter Fehler im
        // ersten Entwurf: ohne das hätte ein Verteidiger, der knapp gewinnt, NIE Verluste
        // gehabt, obwohl CombatResolver eine echte defenderLossRatio > 0 ausweisen kann.
        if ($targetBase !== false) {
            $this->applyLossRatio((int) $targetBase['id'], null, $combat['defenderLossRatio']);
        }

        if ($combat['outcome'] === 'attacker_win') {
            if ($targetBase !== false) {
                $loot['titan'] = min(
                    (int) round($targetBase['titan'] * self::PLUNDER_MAX_PERCENT / 100),
                    (int) $targetBase['titan']
                );
                $loot['deuterium'] = min(
                    (int) round($targetBase['deuterium'] * self::PLUNDER_MAX_PERCENT / 100),
                    (int) $targetBase['deuterium']
                );
                $loot['credits'] = min(
                    (int) round($targetBase['credits'] * self::PLUNDER_MAX_PERCENT / 100),
                    (int) $targetBase['credits']
                );
                $loot = $this->capLootByCargo($loot, $attackerShips);

                $this->pdo->prepare('UPDATE bases SET titan = titan - ?, deuterium = deuterium - ?, credits = credits - ? WHERE id = ?')
                    ->execute([$loot['titan'], $loot['deuterium'], $loot['credits'], $targetBase['id']]);
            } elseif (isset($npc)) {
                $loot['titan'] = (int) round($npc['loot_titan'] * $this->npcLootAvailability($npc));
                $loot['deuterium'] = (int) round($npc['loot_deuterium'] * $this->npcLootAvailability($npc));
                $loot['credits'] = (int) round($npc['loot_credits'] * $this->npcLootAvailability($npc));
                $loot = $this->capLootByCargo($loot, $attackerShips);

                $this->pdo->prepare('UPDATE npc_outposts SET last_raided_at = UTC_TIMESTAMP() WHERE id = ?')
                    ->execute([$npc['id']]);
            }
        }
        // Unentschieden/Verteidiger-Sieg: kein Loot (siehe CombatResolver-Kommentar zu 'draw'),
        // nur die oben schon angewendeten Verluste auf beiden Seiten.

        $this->applyLossRatioToFleet($fleet['id'], $combat['attackerLossRatio']);

        $this->pdo->prepare(
            'INSERT INTO combat_reports
                (attacker_player_id, defender_player_id, sector_id, position, outcome, loot_titan, loot_deuterium, loot_credits, report_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $fleet['player_id'], $defenderPlayerId, $fleet['target_sector_id'], $fleet['target_position'],
            $combat['outcome'], $loot['titan'], $loot['deuterium'], $loot['credits'],
            json_encode($combat['rounds']),
        ]);

        // Rückflug antreten - IMMER (auch bei Niederlage/Unentschieden, falls noch Schiffe übrig
        // sind; resolveReturn() liefert dann einfach 0 Fracht + die restlichen Schiffe).
        $travelSeconds = $this->travelSeconds($fleet);
        $this->pdo->prepare(
            "UPDATE fleets SET status = 'returning', cargo_titan = ?, cargo_deuterium = ?, cargo_credits = ?, arrives_at = ?
             WHERE id = ?"
        )->execute([
            $loot['titan'], $loot['deuterium'], $loot['credits'],
            $arrivesAt->modify("+{$travelSeconds} seconds")->format('Y-m-d H:i:s'),
            $fleet['id'],
        ]);
    }

    private function npcLootAvailability(array $npc): float
    {
        if ($npc['last_raided_at'] === null) {
            return 1.0;
        }
        $elapsed = time() - (new DateTimeImmutable($npc['last_raided_at']))->getTimestamp();
        $regenSeconds = $this->scaledSeconds(self::NPC_LOOT_REGEN_SECONDS);

        return min(1.0, max(0.0, $elapsed / $regenSeconds));
    }

    private function capLootByCargo(array $loot, array $attackerShips): array
    {
        $cargoCapacity = 0;
        foreach ($attackerShips as $s) {
            $cargoCapacity += (int) $s['type']['cargo_capacity'] * $s['quantity'];
        }
        $total = $loot['titan'] + $loot['deuterium'] + $loot['credits'];
        if ($total <= $cargoCapacity || $total === 0) {
            return $loot;
        }
        $factor = $cargoCapacity / $total;

        return [
            'titan' => (int) floor($loot['titan'] * $factor),
            'deuterium' => (int) floor($loot['deuterium'] * $factor),
            'credits' => (int) floor($loot['credits'] * $factor),
        ];
    }

    /** Verteilt Verluste gleichmäßig (proportional) auf alle Schiffstypen EINER Basis. */
    private function applyLossRatio(int $baseId, ?int $unused, float $lossRatio): void
    {
        if ($lossRatio <= 0.0) {
            return;
        }
        $stmt = $this->pdo->prepare('SELECT ship_type_id, quantity FROM base_ships WHERE base_id = ? FOR UPDATE');
        $stmt->execute([$baseId]);
        foreach ($stmt->fetchAll() as $row) {
            $newQty = (int) floor((int) $row['quantity'] * (1 - $lossRatio));
            $this->pdo->prepare('UPDATE base_ships SET quantity = ? WHERE base_id = ? AND ship_type_id = ?')
                ->execute([$newQty, $baseId, $row['ship_type_id']]);
        }
    }

    private function applyLossRatioToFleet(int $fleetId, float $lossRatio): void
    {
        if ($lossRatio <= 0.0) {
            return;
        }
        $stmt = $this->pdo->prepare('SELECT ship_type_id, quantity FROM fleet_ships WHERE fleet_id = ? FOR UPDATE');
        $stmt->execute([$fleetId]);
        foreach ($stmt->fetchAll() as $row) {
            $newQty = (int) floor((int) $row['quantity'] * (1 - $lossRatio));
            $this->pdo->prepare('UPDATE fleet_ships SET quantity = ? WHERE fleet_id = ? AND ship_type_id = ?')
                ->execute([$newQty, $fleetId, $row['ship_type_id']]);
        }
    }

    private function travelSeconds(array $fleet): int
    {
        $originStmt = $this->pdo->prepare('SELECT x, y FROM sectors WHERE id = ?');
        $originStmt->execute([$fleet['origin_sector_id']]);
        $origin = $originStmt->fetch();
        $targetStmt = $this->pdo->prepare('SELECT x, y FROM sectors WHERE id = ?');
        $targetStmt->execute([$fleet['target_sector_id']]);
        $target = $targetStmt->fetch();

        $distance = sqrt((($target['x'] ?? 0) - ($origin['x'] ?? 0)) ** 2 + (($target['y'] ?? 0) - ($origin['y'] ?? 0)) ** 2);

        $speedStmt = $this->pdo->prepare('SELECT MIN(st.speed) FROM fleet_ships fs JOIN ship_types st ON st.id = fs.ship_type_id WHERE fs.fleet_id = ?');
        $speedStmt->execute([$fleet['id']]);
        $speed = max(1, (int) ($speedStmt->fetchColumn() ?: 50));

        $baseSeconds = (int) round($distance * self::TRAVEL_SECONDS_PER_DISTANCE_AT_SPEED_100 * (100 / $speed));

        return $this->scaledSeconds(max(60, $baseSeconds));
    }

    // ============================================================
    // Basis auf einen bestimmten Zeitpunkt vorspulen: Produktion abrechnen UND die Bau-
    // Warteschlange in VIRTUELLER Zeit verketten (dasselbe Muster wie applyCompletedModules() im
    // Vorgängerprojekt, hier auf Mehr-Rohstoff-Kosten erweitert).
    // ============================================================
    public function advanceBaseTo(int $baseId, DateTimeImmutable $asOf): void
    {
        // Eigene Transaktion NUR, wenn noch keine offen ist (Muster aus dem Vorgängerprojekt) -
        // so kann diese Methode sowohl eigenständig (aus state()) als auch als Teil einer
        // größeren, vom Aufrufer schon offenen Transaktion (queueBuilding(), dispatchFleet())
        // verwendet werden, ohne doppelt zu committen/zu verschachteln.
        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            // KEIN Vorab-Settle hier auf $asOf - das würde die GESAMTE Zeitspanne sofort zum
            // ALTEN (Vor-Level-Up) Satz abrechnen, bevor die Schleife unten überhaupt die Chance
            // hatte, fällige Level-Ups zwischenzuschalten (genau der Fehler, den die
            // Pro-Abschnitt-Abrechnung in der Schleife vermeiden soll). Settlement passiert NUR
            // hier drin (pro abgeschlossenem Level) und einmal ganz am Ende für den Rest-Zeitraum.
            while (true) {
                $stmt = $this->pdo->prepare(
                    "SELECT bb.*, bt.effect FROM base_buildings bb
                     JOIN building_types bt ON bt.id = bb.building_type_id
                     WHERE bb.base_id = ? AND bb.status = 'building' FOR UPDATE"
                );
                $stmt->execute([$baseId]);
                $active = $stmt->fetch();

                if ($active === false) {
                    break;
                }

                $finishesAt = new DateTimeImmutable($active['finishes_at']);
                if ($finishesAt > $asOf) {
                    break;
                }

                // Produktion bis GENAU zum alten Fertigstellungszeitpunkt abrechnen, BEVOR das
                // neue (höhere) Produktions-Level gilt - sonst würde ein vergangener Zeitraum
                // rückwirkend zum neuen, höheren Satz vergütet (dieselbe Lektion wie beim
                // Lagerhaus im Vorgängerprojekt).
                $this->settleProduction($baseId, $finishesAt);

                $newLevel = (int) $active['level'] + 1;
                $this->pdo->prepare(
                    "UPDATE base_buildings SET level = ?, status = 'idle', started_at = NULL, finishes_at = NULL
                     WHERE base_id = ? AND building_type_id = ?"
                )->execute([$newLevel, $baseId, $active['building_type_id']]);

                $nextStmt = $this->pdo->prepare('SELECT * FROM base_build_queue WHERE base_id = ? ORDER BY id ASC LIMIT 1 FOR UPDATE');
                $nextStmt->execute([$baseId]);
                $next = $nextStmt->fetch();

                if ($next === false) {
                    break;
                }

                $nextSecondsStmt = $this->pdo->prepare('SELECT build_seconds FROM building_levels WHERE building_type_id = ? AND level = ?');
                $nextSecondsStmt->execute([$next['building_type_id'], $next['target_level']]);
                $nextBuildSeconds = $this->scaledSeconds((int) $nextSecondsStmt->fetchColumn());
                $nextFinishesAt = $finishesAt->modify("+{$nextBuildSeconds} seconds");

                $this->startOrUpdateBuilding($baseId, (int) $next['building_type_id'], $finishesAt, $nextFinishesAt);
                $this->pdo->prepare('DELETE FROM base_build_queue WHERE id = ?')->execute([$next['id']]);
            }

            // Rest-Zeitraum (von der letzten Level-Up-Grenze, oder vom alten last_tick_at, falls
            // gar kein Level-Up fällig war) zum JETZT GÜLTIGEN (ggf. gerade erhöhten) Satz
            // abrechnen.
            $this->settleProduction($baseId, $asOf);
            $this->advanceShipQueue($baseId, $asOf);

            if ($ownTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    // ============================================================
    // Werft-Warteschlange (Schiffsbau) - siehe Migration 004-Kommentar. Dieselbe
    // Virtuelle-Zeit-Verkettung wie bei base_build_queue, aber FIFO ueber Stueckzahl: die erste
    // Zeile (kleinste id) ist der aktive Auftrag, nachfolgende sind erst vorgemerkt.
    // ============================================================
    private function advanceShipQueue(int $baseId, DateTimeImmutable $asOf): void
    {
        while (true) {
            $stmt = $this->pdo->prepare('SELECT * FROM base_ship_queue WHERE base_id = ? ORDER BY id ASC LIMIT 1 FOR UPDATE');
            $stmt->execute([$baseId]);
            $job = $stmt->fetch();

            if ($job === false) {
                break;
            }

            if ($job['started_at'] === null) {
                // Vorheriger Auftrag ist gerade fertig geworden (oder dies ist der allererste) -
                // dieser Auftrag startet GENAU jetzt in virtueller Zeit, nicht bei "jetzt real".
                $startedAt = $asOf;
                $finishesAt = $startedAt->modify('+' . (int) $job['unit_build_seconds'] . ' seconds');
                $this->pdo->prepare('UPDATE base_ship_queue SET started_at = ?, finishes_at = ? WHERE id = ?')
                    ->execute([$startedAt->format('Y-m-d H:i:s'), $finishesAt->format('Y-m-d H:i:s'), $job['id']]);
                $job['started_at'] = $startedAt->format('Y-m-d H:i:s');
                $job['finishes_at'] = $finishesAt->format('Y-m-d H:i:s');
            }

            $finishesAt = new DateTimeImmutable($job['finishes_at']);
            if ($finishesAt > $asOf) {
                break;
            }

            $this->pdo->prepare(
                'INSERT INTO base_ships (base_id, ship_type_id, quantity) VALUES (?, ?, 1)
                 ON DUPLICATE KEY UPDATE quantity = quantity + 1'
            )->execute([$baseId, $job['ship_type_id']]);

            $remaining = (int) $job['quantity'] - 1;
            if ($remaining <= 0) {
                $this->pdo->prepare('DELETE FROM base_ship_queue WHERE id = ?')->execute([$job['id']]);
            } else {
                $nextFinishesAt = $finishesAt->modify('+' . (int) $job['unit_build_seconds'] . ' seconds');
                $this->pdo->prepare('UPDATE base_ship_queue SET quantity = ?, started_at = ?, finishes_at = ? WHERE id = ?')
                    ->execute([$remaining, $finishesAt->format('Y-m-d H:i:s'), $nextFinishesAt->format('Y-m-d H:i:s'), $job['id']]);
            }
        }
    }

    private const MAX_SHIP_QUEUE_LENGTH = 5; // offene Auftraege (nicht Stueckzahl) je Basis
    private const MAX_SHIP_ORDER_QUANTITY = 500;

    public function queueShip(int $playerId, int $baseId, int $shipTypeId, int $quantity): array
    {
        if ($quantity < 1 || $quantity > self::MAX_SHIP_ORDER_QUANTITY) {
            Response::error('Ungueltige Stueckzahl', 422);
        }

        $this->pdo->beginTransaction();

        try {
            $this->lockOwnBase($playerId, $baseId);
            $this->advanceBaseTo($baseId, new DateTimeImmutable());
            $base = $this->lockOwnBase($playerId, $baseId); // frisch nach advanceBaseTo() erneut lesen

            $shipyardStmt = $this->pdo->prepare("SELECT level FROM base_buildings WHERE base_id = ? AND building_type_id = 4");
            $shipyardStmt->execute([$baseId]);
            $shipyardLevel = (int) ($shipyardStmt->fetchColumn() ?: 0);
            if ($shipyardLevel < 1) {
                $this->rollbackIfOpen();
                Response::error('Werft muss zuerst gebaut werden', 422);
            }

            $queuedCountStmt = $this->pdo->prepare('SELECT COUNT(*) FROM base_ship_queue WHERE base_id = ?');
            $queuedCountStmt->execute([$baseId]);
            if ((int) $queuedCountStmt->fetchColumn() >= self::MAX_SHIP_QUEUE_LENGTH) {
                $this->rollbackIfOpen();
                Response::error('Werft-Warteschlange ist voll', 422);
            }

            $typeStmt = $this->pdo->prepare('SELECT * FROM ship_types WHERE id = ?');
            $typeStmt->execute([$shipTypeId]);
            $type = $typeStmt->fetch();
            if ($type === false) {
                $this->rollbackIfOpen();
                Response::error('Schiffstyp existiert nicht', 404);
            }

            $totalTitan = (int) $type['cost_titan'] * $quantity;
            $totalDeuterium = (int) $type['cost_deuterium'] * $quantity;
            $totalCredits = (int) $type['cost_credits'] * $quantity;

            if ($totalTitan > (int) $base['titan'] || $totalDeuterium > (int) $base['deuterium'] || $totalCredits > (int) $base['credits']) {
                $this->rollbackIfOpen();
                Response::error('Nicht genug Rohstoffe', 422);
            }

            $this->pdo->prepare('UPDATE bases SET titan = titan - ?, deuterium = deuterium - ?, credits = credits - ? WHERE id = ?')
                ->execute([$totalTitan, $totalDeuterium, $totalCredits, $baseId]);

            $unitBuildSeconds = $this->scaledSeconds((int) $type['build_seconds']);

            $this->pdo->prepare(
                'INSERT INTO base_ship_queue (base_id, ship_type_id, quantity, unit_cost_titan, unit_cost_deuterium, unit_cost_credits, unit_build_seconds)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([$baseId, $shipTypeId, $quantity, $type['cost_titan'], $type['cost_deuterium'], $type['cost_credits'], $unitBuildSeconds]);

            // Falls das der einzige Auftrag ist, startet advanceShipQueue() ihn beim naechsten
            // Aufruf automatisch (started_at ist NULL -> "aktiv, aber noch nicht gestartet").
            $this->advanceBaseTo($baseId, new DateTimeImmutable());

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->rollbackIfOpen();
            throw $e;
        }

        return $this->baseView($baseId);
    }

    public function cancelQueuedShip(int $playerId, int $baseId): array
    {
        $this->pdo->beginTransaction();

        try {
            $this->lockOwnBase($playerId, $baseId);

            $stmt = $this->pdo->prepare('SELECT * FROM base_ship_queue WHERE base_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $stmt->execute([$baseId]);
            $last = $stmt->fetch();

            if ($last === false) {
                $this->rollbackIfOpen();
                Response::error('Werft-Warteschlange ist leer', 422);
            }

            $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM base_ship_queue WHERE base_id = ?');
            $countStmt->execute([$baseId]);
            if ((int) $countStmt->fetchColumn() === 1) {
                // Einziger Eintrag = der gerade aktiv laufende Auftrag (erste Zeile) - der kann
                // nicht storniert werden, analog zum laufenden Gebaeude-Ausbau in Phase 2.
                $this->rollbackIfOpen();
                Response::error('Laufender Bauauftrag kann nicht storniert werden', 422);
            }

            $refundTitan = (int) $last['unit_cost_titan'] * (int) $last['quantity'];
            $refundDeuterium = (int) $last['unit_cost_deuterium'] * (int) $last['quantity'];
            $refundCredits = (int) $last['unit_cost_credits'] * (int) $last['quantity'];

            $this->pdo->prepare('UPDATE bases SET titan = titan + ?, deuterium = deuterium + ?, credits = credits + ? WHERE id = ?')
                ->execute([$refundTitan, $refundDeuterium, $refundCredits, $baseId]);
            $this->pdo->prepare('DELETE FROM base_ship_queue WHERE id = ?')->execute([$last['id']]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->rollbackIfOpen();
            throw $e;
        }

        return $this->baseView($baseId);
    }

    private function settleProduction(int $baseId, DateTimeImmutable $asOf): void
    {
        $stmt = $this->pdo->prepare('SELECT * FROM bases WHERE id = ? FOR UPDATE');
        $stmt->execute([$baseId]);
        $base = $stmt->fetch();
        if ($base === false) {
            return;
        }

        $lastTick = new DateTimeImmutable($base['last_tick_at']);
        if ($lastTick >= $asOf) {
            return;
        }
        $tickSeconds = $this->scaledSeconds(self::TICK_SECONDS);
        $ticks = intdiv($asOf->getTimestamp() - $lastTick->getTimestamp(), $tickSeconds);
        if ($ticks <= 0) {
            return;
        }

        $titanRate = self::BASE_TITAN_PER_TICK + $this->productionEffectAmount($baseId, 'titan_production');
        $deuteriumRate = self::BASE_DEUTERIUM_PER_TICK + $this->productionEffectAmount($baseId, 'deuterium_production');
        $bonusFactor = 1 + ((int) $base['resource_bonus_percent'] / 100);

        $titanGain = (int) round($ticks * $titanRate * $bonusFactor);
        $deuteriumGain = (int) round($ticks * $deuteriumRate * $bonusFactor);

        $newLastTick = $lastTick->modify('+' . ($ticks * $tickSeconds) . ' seconds');

        $this->pdo->prepare('UPDATE bases SET titan = titan + ?, deuterium = deuterium + ?, last_tick_at = ? WHERE id = ?')
            ->execute([$titanGain, $deuteriumGain, $newLastTick->format('Y-m-d H:i:s'), $baseId]);
    }

    private function productionEffectAmount(int $baseId, string $effect): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT bl.effect_amount FROM base_buildings bb
             JOIN building_types bt ON bt.id = bb.building_type_id
             JOIN building_levels bl ON bl.building_type_id = bb.building_type_id AND bl.level = bb.level
             WHERE bb.base_id = ? AND bt.effect = ? AND bb.level > 0"
        );
        $stmt->execute([$baseId, $effect]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function startOrUpdateBuilding(int $baseId, int $buildingTypeId, DateTimeImmutable $startedAt, DateTimeImmutable $finishesAt): void
    {
        $existing = $this->pdo->prepare('SELECT 1 FROM base_buildings WHERE base_id = ? AND building_type_id = ?');
        $existing->execute([$baseId, $buildingTypeId]);

        if ($existing->fetch() !== false) {
            $this->pdo->prepare(
                "UPDATE base_buildings SET status = 'building', started_at = ?, finishes_at = ?
                 WHERE base_id = ? AND building_type_id = ?"
            )->execute([$startedAt->format('Y-m-d H:i:s'), $finishesAt->format('Y-m-d H:i:s'), $baseId, $buildingTypeId]);
        } else {
            $this->pdo->prepare(
                "INSERT INTO base_buildings (base_id, building_type_id, level, status, started_at, finishes_at)
                 VALUES (?, ?, 0, 'building', ?, ?)"
            )->execute([$baseId, $buildingTypeId, $startedAt->format('Y-m-d H:i:s'), $finishesAt->format('Y-m-d H:i:s')]);
        }
    }

    // ============================================================
    // Gebäude-Ausbau (Level + Warteschlange, identisches Muster wie im Vorgängerprojekt)
    // ============================================================
    public function queueBuilding(int $playerId, int $baseId, int $buildingTypeId): array
    {
        $this->pdo->beginTransaction();

        try {
            $base = $this->lockOwnBase($playerId, $baseId);
            $this->advanceBaseTo($baseId, new DateTimeImmutable());

            $pendingCount = $this->pendingBuildCount($baseId);
            if ($pendingCount >= self::MAX_BUILD_QUEUE_LENGTH) {
                $this->rollbackIfOpen();
                Response::error('Warteschlange ist voll', 422);
            }

            $targetLevel = $this->projectedLevel($baseId, $buildingTypeId) + 1;
            if ($targetLevel > self::MAX_BUILDING_LEVEL) {
                $this->rollbackIfOpen();
                Response::error('Maximales Level bereits erreicht', 422);
            }

            if ($buildingTypeId !== 1) { // 1 = Kommandobrücke
                $hqProjected = $this->projectedLevel($baseId, 1);
                if ($targetLevel > $hqProjected) {
                    $this->rollbackIfOpen();
                    Response::error('Kommandobrücke muss zuerst auf dieses Level ausgebaut werden', 422);
                }
            }

            $levelStmt = $this->pdo->prepare('SELECT * FROM building_levels WHERE building_type_id = ? AND level = ?');
            $levelStmt->execute([$buildingTypeId, $targetLevel]);
            $levelRow = $levelStmt->fetch();
            if ($levelRow === false) {
                $this->rollbackIfOpen();
                Response::error('Level existiert nicht', 404);
            }

            $base = $this->lockOwnBase($playerId, $baseId); // frisch nach advanceBaseTo() erneut lesen
            if ((int) $levelRow['cost_titan'] > (int) $base['titan']
                || (int) $levelRow['cost_deuterium'] > (int) $base['deuterium']
                || (int) $levelRow['cost_credits'] > (int) $base['credits']) {
                $this->rollbackIfOpen();
                Response::error('Nicht genug Rohstoffe', 422);
            }

            $this->pdo->prepare('UPDATE bases SET titan = titan - ?, deuterium = deuterium - ?, credits = credits - ? WHERE id = ?')
                ->execute([$levelRow['cost_titan'], $levelRow['cost_deuterium'], $levelRow['cost_credits'], $baseId]);

            $activeStmt = $this->pdo->prepare("SELECT 1 FROM base_buildings WHERE base_id = ? AND status = 'building'");
            $activeStmt->execute([$baseId]);
            $hasActiveBuild = $activeStmt->fetch() !== false;

            if (!$hasActiveBuild) {
                $buildSeconds = $this->scaledSeconds((int) $levelRow['build_seconds']);
                $now = new DateTimeImmutable();
                $this->startOrUpdateBuilding($baseId, $buildingTypeId, $now, $now->modify("+{$buildSeconds} seconds"));
            } else {
                $this->pdo->prepare(
                    'INSERT INTO base_build_queue (base_id, building_type_id, target_level, cost_titan_paid, cost_deuterium_paid, cost_credits_paid)
                     VALUES (?, ?, ?, ?, ?, ?)'
                )->execute([$baseId, $buildingTypeId, $targetLevel, $levelRow['cost_titan'], $levelRow['cost_deuterium'], $levelRow['cost_credits']]);
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->rollbackIfOpen();
            throw $e;
        }

        return $this->baseView($baseId);
    }

    public function cancelQueuedBuilding(int $playerId, int $baseId): array
    {
        $this->pdo->beginTransaction();

        try {
            $this->lockOwnBase($playerId, $baseId);

            $stmt = $this->pdo->prepare('SELECT * FROM base_build_queue WHERE base_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $stmt->execute([$baseId]);
            $last = $stmt->fetch();

            if ($last === false) {
                $this->rollbackIfOpen();
                Response::error('Warteschlange ist leer', 422);
            }

            $this->pdo->prepare('UPDATE bases SET titan = titan + ?, deuterium = deuterium + ?, credits = credits + ? WHERE id = ?')
                ->execute([$last['cost_titan_paid'], $last['cost_deuterium_paid'], $last['cost_credits_paid'], $baseId]);
            $this->pdo->prepare('DELETE FROM base_build_queue WHERE id = ?')->execute([$last['id']]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->rollbackIfOpen();
            throw $e;
        }

        return $this->baseView($baseId);
    }

    private function pendingBuildCount(int $baseId): int
    {
        $activeStmt = $this->pdo->prepare("SELECT COUNT(*) FROM base_buildings WHERE base_id = ? AND status = 'building'");
        $activeStmt->execute([$baseId]);
        $queuedStmt = $this->pdo->prepare('SELECT COUNT(*) FROM base_build_queue WHERE base_id = ?');
        $queuedStmt->execute([$baseId]);

        return (int) $activeStmt->fetchColumn() + (int) $queuedStmt->fetchColumn();
    }

    private function projectedLevel(int $baseId, int $buildingTypeId): int
    {
        $stmt = $this->pdo->prepare('SELECT level, status FROM base_buildings WHERE base_id = ? AND building_type_id = ?');
        $stmt->execute([$baseId, $buildingTypeId]);
        $row = $stmt->fetch();

        $level = $row === false ? 0 : (int) $row['level'];
        $projected = ($row !== false && $row['status'] === 'building') ? $level + 1 : $level;

        $queuedStmt = $this->pdo->prepare('SELECT MAX(target_level) FROM base_build_queue WHERE base_id = ? AND building_type_id = ?');
        $queuedStmt->execute([$baseId, $buildingTypeId]);
        $queuedMax = $queuedStmt->fetchColumn();

        return max($projected, (int) ($queuedMax ?: 0));
    }

    // ============================================================
    // Flottenversand
    // ============================================================

    /** @param array<int, int> $shipComposition ship_type_id => quantity */
    public function dispatchFleet(
        int $playerId,
        int $baseId,
        array $shipComposition,
        int $targetSectorId,
        int $targetPosition,
        string $mission,
        string $energyPreset
    ): array {
        $shipComposition = array_filter($shipComposition, fn ($q) => $q > 0);
        if ($shipComposition === []) {
            Response::error('Keine Schiffe ausgewählt', 422);
        }
        if (!in_array($mission, ['attack', 'plunder', 'station', 'colonize', 'transport'], true)) {
            Response::error('Unbekannte Missionsart', 422);
        }
        if (!in_array($energyPreset, ['shields', 'weapons', 'speed'], true)) {
            Response::error('Unbekanntes Energie-Preset', 422);
        }

        $this->pdo->beginTransaction();

        try {
            $base = $this->lockOwnBase($playerId, $baseId);
            $this->advanceBaseTo($baseId, new DateTimeImmutable());
            $base = $this->lockOwnBase($playerId, $baseId); // frisch nach advanceBaseTo() erneut lesen

            $ownShipsStmt = $this->pdo->prepare('SELECT ship_type_id, quantity FROM base_ships WHERE base_id = ? FOR UPDATE');
            $ownShipsStmt->execute([$baseId]);
            $ownShips = [];
            foreach ($ownShipsStmt->fetchAll() as $r) {
                $ownShips[(int) $r['ship_type_id']] = (int) $r['quantity'];
            }
            foreach ($shipComposition as $shipTypeId => $qty) {
                if (($ownShips[$shipTypeId] ?? 0) < $qty) {
                    $this->rollbackIfOpen();
                    Response::error('Nicht genug Schiffe dieses Typs im Hangar', 422);
                }
            }

            $typesStmt = $this->pdo->prepare('SELECT * FROM ship_types WHERE id IN (' . implode(',', array_fill(0, count($shipComposition), '?')) . ')');
            $typesStmt->execute(array_keys($shipComposition));
            $shipTypes = [];
            foreach ($typesStmt->fetchAll() as $r) {
                $shipTypes[(int) $r['id']] = $r;
            }

            $this->assertValidMissionTarget($playerId, $mission, $targetSectorId, $targetPosition, $shipComposition, $shipTypes);

            $sectorStmt = $this->pdo->prepare('SELECT x, y FROM sectors WHERE id = ?');
            $sectorStmt->execute([$base['sector_id']]);
            $originCoords = $sectorStmt->fetch();
            $sectorStmt->execute([$targetSectorId]);
            $targetCoords = $sectorStmt->fetch();
            if ($targetCoords === false) {
                $this->rollbackIfOpen();
                Response::error('Zielsektor existiert nicht', 404);
            }
            $distance = sqrt((($targetCoords['x'] ?? 0) - ($originCoords['x'] ?? 0)) ** 2 + (($targetCoords['y'] ?? 0) - ($originCoords['y'] ?? 0)) ** 2);

            $shipCount = array_sum($shipComposition);
            $fuelCost = (int) ceil($distance * $shipCount * self::DEUTERIUM_FUEL_PER_SHIP_PER_DISTANCE);
            if ($fuelCost > (int) $base['deuterium']) {
                $this->rollbackIfOpen();
                Response::error('Nicht genug Deuterium für diesen Flug', 422);
            }

            $slowestSpeed = PHP_INT_MAX;
            foreach ($shipComposition as $shipTypeId => $qty) {
                $slowestSpeed = min($slowestSpeed, (int) $shipTypes[$shipTypeId]['speed']);
            }
            $baseSeconds = (int) round($distance * self::TRAVEL_SECONDS_PER_DISTANCE_AT_SPEED_100 * (100 / max(1, $slowestSpeed)));
            $travelSeconds = $this->scaledSeconds(max(60, $baseSeconds));

            $this->pdo->prepare('UPDATE bases SET deuterium = deuterium - ? WHERE id = ?')->execute([$fuelCost, $baseId]);
            foreach ($shipComposition as $shipTypeId => $qty) {
                $this->pdo->prepare('UPDATE base_ships SET quantity = quantity - ? WHERE base_id = ? AND ship_type_id = ?')
                    ->execute([$qty, $baseId, $shipTypeId]);
            }

            $now = new DateTimeImmutable();
            $this->pdo->prepare(
                "INSERT INTO fleets
                    (player_id, origin_sector_id, origin_position, target_sector_id, target_position, mission, energy_preset, status, departed_at, arrives_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'outbound', ?, ?)"
            )->execute([
                $playerId, $base['sector_id'], $base['position'], $targetSectorId, $targetPosition, $mission, $energyPreset,
                $now->format('Y-m-d H:i:s'), $now->modify("+{$travelSeconds} seconds")->format('Y-m-d H:i:s'),
            ]);
            $fleetId = (int) $this->pdo->lastInsertId();

            foreach ($shipComposition as $shipTypeId => $qty) {
                $this->pdo->prepare('INSERT INTO fleet_ships (fleet_id, ship_type_id, quantity) VALUES (?, ?, ?)')
                    ->execute([$fleetId, $shipTypeId, $qty]);
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->rollbackIfOpen();
            throw $e;
        }

        return ['fleetId' => $fleetId, 'arrivesInSeconds' => $travelSeconds];
    }

    /** @param array<int, int> $shipComposition */
    private function assertValidMissionTarget(
        int $playerId,
        string $mission,
        int $targetSectorId,
        int $targetPosition,
        array $shipComposition,
        array $shipTypes
    ): void {
        $baseStmt = $this->pdo->prepare('SELECT * FROM bases WHERE sector_id = ? AND position = ?');
        $baseStmt->execute([$targetSectorId, $targetPosition]);
        $targetBase = $baseStmt->fetch();

        $npcStmt = $this->pdo->prepare('SELECT 1 FROM npc_outposts WHERE sector_id = ? AND position = ?');
        $npcStmt->execute([$targetSectorId, $targetPosition]);
        $hasNpc = $npcStmt->fetch() !== false;

        switch ($mission) {
            case 'attack':
            case 'plunder':
                if ($targetBase !== false && (int) $targetBase['player_id'] === $playerId) {
                    $this->rollbackIfOpen();
                    Response::error('Die eigene Basis kann nicht angegriffen werden', 422);
                }
                if ($targetBase === false && !$hasNpc) {
                    $this->rollbackIfOpen();
                    Response::error('Dort gibt es nichts anzugreifen', 422);
                }
                if ($targetBase !== false && $targetBase['protected_until'] !== null
                    && new DateTimeImmutable($targetBase['protected_until']) > new DateTimeImmutable()) {
                    $this->rollbackIfOpen();
                    Response::error('Ziel steht noch unter Anfängerschutz', 422);
                }
                // Eigener Anfängerschutz endet, sobald man selbst angreift - verhindert, dass
                // Schutz zum dauerhaften Unverwundbarkeits-Exploit wird, während man selbst raidet.
                if ($targetBase !== false) {
                    $this->pdo->prepare('UPDATE bases SET protected_until = NULL WHERE player_id = ?')->execute([$playerId]);
                }
                break;
            case 'station':
            case 'transport':
                if ($targetBase === false || (int) $targetBase['player_id'] !== $playerId) {
                    $this->rollbackIfOpen();
                    Response::error('Ziel muss eine eigene Basis sein', 422);
                }
                break;
            case 'colonize':
                if ($targetBase !== false || $hasNpc) {
                    $this->rollbackIfOpen();
                    Response::error('Zielfeld ist bereits besetzt', 422);
                }
                $hasColonyShip = false;
                foreach ($shipComposition as $shipTypeId => $qty) {
                    if ($shipTypes[$shipTypeId]['name'] === 'Kolonieschiff' && $qty > 0) {
                        $hasColonyShip = true;
                        break;
                    }
                }
                if (!$hasColonyShip) {
                    $this->rollbackIfOpen();
                    Response::error('Kolonisierung braucht mindestens ein Kolonieschiff', 422);
                }
                break;
        }
    }

    // ============================================================
    // Sicht
    // ============================================================
    public function state(array $player): array
    {
        $this->resolveGlobalArrivals();

        $basesStmt = $this->pdo->prepare('SELECT id FROM bases WHERE player_id = ?');
        $basesStmt->execute([$player['id']]);
        $baseIds = $basesStmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($baseIds as $baseId) {
            $this->advanceBaseTo((int) $baseId, new DateTimeImmutable());
        }

        $bases = array_map(fn ($id) => $this->baseView((int) $id), $baseIds);

        $fleetsStmt = $this->pdo->prepare(
            "SELECT f.*, os.x AS origin_x, os.y AS origin_y, ts.x AS target_x, ts.y AS target_y
             FROM fleets f
             JOIN sectors os ON os.id = f.origin_sector_id
             JOIN sectors ts ON ts.id = f.target_sector_id
             WHERE f.player_id = ?
             ORDER BY f.arrives_at ASC"
        );
        $fleetsStmt->execute([$player['id']]);
        $fleets = array_map(function ($f) {
            return [
                'id' => (int) $f['id'],
                'mission' => $f['mission'],
                'status' => $f['status'],
                'originSector' => ['x' => (int) $f['origin_x'], 'y' => (int) $f['origin_y']],
                'targetSector' => ['x' => (int) $f['target_x'], 'y' => (int) $f['target_y']],
                'arrivesAtEpoch' => $this->toEpoch($f['arrives_at']),
            ];
        }, $fleetsStmt->fetchAll());

        // Eingehende FEINDLICHE Flotten gegen eigene Basen - fuer die Sensor-/Angriffswarnung im
        // Frontend. Bewusst NUR Ankunftszeit + Angreifername, NIE die Flottenzusammensetzung
        // (Advisor-Review: das waere ein Informationsvorteil, den der echte Langstreckensensor in
        // einer spaeteren Phase erst verdienen muesste).
        $incomingStmt = $this->pdo->prepare(
            "SELECT f.arrives_at, f.target_sector_id, f.target_position, p.username AS attacker_name
             FROM fleets f
             JOIN players p ON p.id = f.player_id
             JOIN bases b ON b.sector_id = f.target_sector_id AND b.position = f.target_position
             WHERE b.player_id = ? AND f.status = 'outbound' AND f.mission IN ('attack', 'plunder') AND f.player_id != ?"
        );
        $incomingStmt->execute([$player['id'], $player['id']]);
        $incoming = array_map(fn ($f) => [
            'attackerName' => $f['attacker_name'],
            'targetSectorId' => (int) $f['target_sector_id'],
            'targetPosition' => (int) $f['target_position'],
            'arrivesAtEpoch' => $this->toEpoch($f['arrives_at']),
        ], $incomingStmt->fetchAll());

        $reportsStmt = $this->pdo->prepare(
            // id DESC als Tie-Breaker: resolved_at hat nur Sekunden-Genauigkeit, zwei
            // Auflösungen (z.B. Rückschlag kurz nach dem ersten Angriff) können denselben
            // Zeitstempel bekommen - ohne den Tie-Breaker wäre die Reihenfolge dann undefiniert
            // und "neuester Bericht zuerst" nicht verlässlich (beim Testen gefunden: ein Report
            // mit 0 Beute erschien VOR einem mit echter Beute, weil beide zur selben Sekunde
            // aufgelöst wurden).
            'SELECT * FROM combat_reports WHERE attacker_player_id = ? OR defender_player_id = ? ORDER BY resolved_at DESC, id DESC LIMIT 20'
        );
        $reportsStmt->execute([$player['id'], $player['id']]);
        $reports = array_map(fn ($r) => [
            'id' => (int) $r['id'],
            'attackerPlayerId' => (int) $r['attacker_player_id'],
            'defenderPlayerId' => $r['defender_player_id'] !== null ? (int) $r['defender_player_id'] : null,
            'outcome' => $r['outcome'],
            'lootTitan' => (int) $r['loot_titan'],
            'lootDeuterium' => (int) $r['loot_deuterium'],
            'lootCredits' => (int) $r['loot_credits'],
            'resolvedAtEpoch' => $this->toEpoch($r['resolved_at']),
        ], $reportsStmt->fetchAll());

        return [
            'serverNow' => time(),
            'player' => ['id' => (int) $player['id'], 'username' => $player['username']],
            'bases' => $bases,
            'fleets' => $fleets,
            'incomingHostileFleets' => $incoming,
            'combatReports' => $reports,
            'gameSpeed' => $this->gameSpeed,
            'catalog' => $this->catalog(),
        ];
    }

    /** Statische Katalogdaten (unabhaengig vom Spieler) - der Client braucht Schiffskosten/-werte,
     * um die Werft-UI und Flottenzusammenstellung zu bauen, ohne sie hart zu verdrahten. */
    private function catalog(): array
    {
        $shipTypes = $this->pdo->query('SELECT * FROM ship_types ORDER BY id')->fetchAll();

        return [
            'shipTypes' => array_map(fn ($s) => [
                'id' => (int) $s['id'],
                'name' => $s['name'],
                'description' => $s['description'],
                'attack' => (int) $s['attack'],
                'shield' => (int) $s['shield'],
                'hull' => (int) $s['hull'],
                'speed' => (int) $s['speed'],
                'cargoCapacity' => (int) $s['cargo_capacity'],
                'costTitan' => (int) $s['cost_titan'],
                'costDeuterium' => (int) $s['cost_deuterium'],
                'costCredits' => (int) $s['cost_credits'],
                'buildSeconds' => $this->scaledSeconds((int) $s['build_seconds']),
                'isFlagship' => (bool) $s['is_flagship'],
            ], $shipTypes),
        ];
    }

    /**
     * Sektor-Fenster um (cx,cy) fuer die Weltkarte - gibt NUR das zurueck, was ein Spieler legitim
     * sehen darf: eigene/fremde Basisnamen+Besitzer+Schutzstatus, NIE fremde Rohstoffe/Schiffe
     * (Advisor-Review-Fund).
     */
    public function mapWindow(int $cx, int $cy, int $radius): array
    {
        $radius = max(1, min(15, $radius));
        $minX = $cx - $radius;
        $maxX = $cx + $radius;
        $minY = $cy - $radius;
        $maxY = $cy + $radius;

        $sectorsStmt = $this->pdo->prepare('SELECT id, x, y FROM sectors WHERE x BETWEEN ? AND ? AND y BETWEEN ? AND ?');
        $sectorsStmt->execute([$minX, $maxX, $minY, $maxY]);
        $sectors = $sectorsStmt->fetchAll();
        if ($sectors === []) {
            return ['sectors' => []];
        }

        $sectorIds = array_map(fn ($s) => (int) $s['id'], $sectors);
        $placeholders = implode(',', array_fill(0, count($sectorIds), '?'));

        $basesStmt = $this->pdo->prepare(
            "SELECT b.sector_id, b.position, b.name, b.protected_until, p.username
             FROM bases b JOIN players p ON p.id = b.player_id
             WHERE b.sector_id IN ($placeholders)"
        );
        $basesStmt->execute($sectorIds);
        $basesBySector = [];
        foreach ($basesStmt->fetchAll() as $b) {
            $basesBySector[(int) $b['sector_id']][] = [
                'position' => (int) $b['position'],
                'type' => 'base',
                'name' => $b['name'],
                'owner' => $b['username'],
                'protected' => $b['protected_until'] !== null && new DateTimeImmutable($b['protected_until']) > new DateTimeImmutable(),
            ];
        }

        $npcStmt = $this->pdo->prepare("SELECT sector_id, position, name FROM npc_outposts WHERE sector_id IN ($placeholders)");
        $npcStmt->execute($sectorIds);
        foreach ($npcStmt->fetchAll() as $n) {
            $basesBySector[(int) $n['sector_id']][] = ['position' => (int) $n['position'], 'type' => 'npc_outpost', 'name' => $n['name']];
        }

        $asteroidStmt = $this->pdo->prepare("SELECT sector_id, position, bonus_percent FROM asteroid_fields WHERE sector_id IN ($placeholders)");
        $asteroidStmt->execute($sectorIds);
        foreach ($asteroidStmt->fetchAll() as $a) {
            $basesBySector[(int) $a['sector_id']][] = ['position' => (int) $a['position'], 'type' => 'asteroid_field', 'bonusPercent' => (int) $a['bonus_percent']];
        }

        $nebulaStmt = $this->pdo->prepare("SELECT sector_id, position FROM nebulae WHERE sector_id IN ($placeholders)");
        $nebulaStmt->execute($sectorIds);
        foreach ($nebulaStmt->fetchAll() as $n) {
            $basesBySector[(int) $n['sector_id']][] = ['position' => (int) $n['position'], 'type' => 'nebula'];
        }

        return [
            'sectors' => array_map(fn ($s) => [
                'id' => (int) $s['id'],
                'x' => (int) $s['x'],
                'y' => (int) $s['y'],
                'slots' => $basesBySector[(int) $s['id']] ?? [],
            ], $sectors),
        ];
    }

    public function baseView(int $baseId): array
    {
        $baseStmt = $this->pdo->prepare('SELECT * FROM bases WHERE id = ?');
        $baseStmt->execute([$baseId]);
        $base = $baseStmt->fetch();

        $hqLevel = 0;
        $buildingsStmt = $this->pdo->prepare(
            'SELECT bt.id, bt.name, bt.description, bt.effect, bb.level, bb.status, bb.finishes_at
             FROM building_types bt
             LEFT JOIN base_buildings bb ON bb.building_type_id = bt.id AND bb.base_id = ?
             ORDER BY bt.id'
        );
        $buildingsStmt->execute([$baseId]);
        $buildingRows = $buildingsStmt->fetchAll();
        foreach ($buildingRows as $row) {
            if ($row['id'] == 1) {
                $hqLevel = (int) ($row['level'] ?? 0);
            }
        }

        $now = new DateTimeImmutable();
        $buildings = array_map(function ($row) use ($now, $hqLevel) {
            $level = (int) ($row['level'] ?? 0);
            $isBuilding = $row['status'] === 'building';
            $secondsRemaining = $isBuilding
                ? max(0, (new DateTimeImmutable($row['finishes_at']))->getTimestamp() - $now->getTimestamp())
                : null;

            $nextLevel = null;
            if ($level < self::MAX_BUILDING_LEVEL) {
                $nextLevelStmt = $this->pdo->prepare(
                    'SELECT cost_titan, cost_deuterium, cost_credits, build_seconds, effect_amount
                     FROM building_levels WHERE building_type_id = ? AND level = ?'
                );
                $nextLevelStmt->execute([$row['id'], $level + 1]);
                $nextRow = $nextLevelStmt->fetch();
                if ($nextRow !== false) {
                    $nextLevel = [
                        'level' => $level + 1,
                        'costTitan' => (int) $nextRow['cost_titan'],
                        'costDeuterium' => (int) $nextRow['cost_deuterium'],
                        'costCredits' => (int) $nextRow['cost_credits'],
                        'buildSeconds' => $this->scaledSeconds((int) $nextRow['build_seconds']),
                        'effectAmount' => (int) $nextRow['effect_amount'],
                        'locked' => (int) $row['id'] !== 1 && ($level + 1) > $hqLevel,
                    ];
                }
            }

            return [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'description' => $row['description'],
                'effect' => $row['effect'],
                'level' => $level,
                'status' => $isBuilding ? 'building' : 'idle',
                'secondsRemaining' => $secondsRemaining,
                'nextLevel' => $nextLevel,
            ];
        }, $buildingRows);

        $shipsStmt = $this->pdo->prepare(
            'SELECT st.id, st.name, bs.quantity FROM base_ships bs JOIN ship_types st ON st.id = bs.ship_type_id WHERE bs.base_id = ? AND bs.quantity > 0'
        );
        $shipsStmt->execute([$baseId]);
        $ships = array_map(fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'quantity' => (int) $r['quantity']], $shipsStmt->fetchAll());

        $queueStmt = $this->pdo->prepare(
            'SELECT q.id, q.target_level, q.cost_titan_paid, q.cost_deuterium_paid, q.cost_credits_paid, bt.name
             FROM base_build_queue q JOIN building_types bt ON bt.id = q.building_type_id
             WHERE q.base_id = ? ORDER BY q.id ASC'
        );
        $queueStmt->execute([$baseId]);
        $queue = array_map(fn ($q) => [
            'id' => (int) $q['id'],
            'buildingName' => $q['name'],
            'targetLevel' => (int) $q['target_level'],
            'costTitanPaid' => (int) $q['cost_titan_paid'],
            'costDeuteriumPaid' => (int) $q['cost_deuterium_paid'],
            'costCreditsPaid' => (int) $q['cost_credits_paid'],
        ], $queueStmt->fetchAll());

        $shipQueueStmt = $this->pdo->prepare(
            'SELECT q.*, st.name FROM base_ship_queue q JOIN ship_types st ON st.id = q.ship_type_id
             WHERE q.base_id = ? ORDER BY q.id ASC'
        );
        $shipQueueStmt->execute([$baseId]);
        $shipQueue = array_map(function ($q) use ($now) {
            $secondsRemaining = $q['finishes_at'] !== null
                ? max(0, (new DateTimeImmutable($q['finishes_at']))->getTimestamp() - $now->getTimestamp())
                : null;

            return [
                'id' => (int) $q['id'],
                'shipName' => $q['name'],
                'quantity' => (int) $q['quantity'],
                'active' => $q['started_at'] !== null,
                'secondsRemainingForNextUnit' => $secondsRemaining,
            ];
        }, $shipQueueStmt->fetchAll());

        $titanRate = self::BASE_TITAN_PER_TICK + $this->productionEffectAmount($baseId, 'titan_production');
        $deuteriumRate = self::BASE_DEUTERIUM_PER_TICK + $this->productionEffectAmount($baseId, 'deuterium_production');
        $bonusFactor = 1 + ((int) $base['resource_bonus_percent'] / 100);
        $ticksPerHour = 3600 / $this->scaledSeconds(self::TICK_SECONDS);

        return [
            'id' => (int) $base['id'],
            'name' => $base['name'],
            'sectorId' => (int) $base['sector_id'],
            'position' => (int) $base['position'],
            'titan' => (int) $base['titan'],
            'deuterium' => (int) $base['deuterium'],
            'credits' => (int) $base['credits'],
            'titanPerHour' => (int) round($titanRate * $bonusFactor * $ticksPerHour),
            'deuteriumPerHour' => (int) round($deuteriumRate * $bonusFactor * $ticksPerHour),
            'protectedUntil' => $this->toEpoch($base['protected_until']),
            'buildings' => $buildings,
            'ships' => $ships,
            'buildQueue' => $queue,
            'shipQueue' => $shipQueue,
        ];
    }

    /**
     * MySQL-Datetime-Strings (naiv, aber UTC - siehe Database.php) in Unix-Epoch umwandeln, statt
     * sie roh an den Client zu geben (Advisor-Review-Fund: "Y-m-d H:i:s" parst JS als LOKALE Zeit,
     * nicht UTC - jeder Countdown waere in Deutschland eine Stunde falsch, und die Android-WebView
     * liefert fuer das Leerzeichen-Format teils "Invalid Date"). Der Client rechnet stattdessen
     * selbst gegen das mitgelieferte serverNow.
     */
    private function toEpoch(?string $mysqlDatetime): ?int
    {
        if ($mysqlDatetime === null) {
            return null;
        }

        return (new DateTimeImmutable($mysqlDatetime, new DateTimeZone('UTC')))->getTimestamp();
    }

    /**
     * Rollback-vor-Error gilt hier (und in jedem anderen Response::error()-Aufruf dieser Klasse,
     * der innerhalb einer offenen Transaktion passieren kann) IMMER mit diesem Wächter, nie
     * blind $this->pdo->rollBack() - das wirft selbst eine Exception, wenn gerade keine
     * Transaktion offen ist.
     */
    private function rollbackIfOpen(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function lockOwnBase(int $playerId, int $baseId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM bases WHERE id = ? FOR UPDATE');
        $stmt->execute([$baseId]);
        $base = $stmt->fetch();

        if ($base === false || (int) $base['player_id'] !== $playerId) {
            $this->rollbackIfOpen();
            Response::error('Diese Basis gehört dir nicht', 403);
        }

        return $base;
    }
}
