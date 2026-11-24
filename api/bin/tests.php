<?php

declare(strict_types=1);

/**
 * Regressionstest gegen den LAUFENDEN lokalen Dev-Server (http://127.0.0.1:8199). Server MUSS
 * mit PHP_CLI_SERVER_WORKERS=4 (oder mehr) laufen, sonst bedient `php -S` Anfragen seriell und
 * jeder Race-Condition-Test besteht automatisch, ohne dass irgendein Lock tatsächlich geprüft
 * wird (siehe Advisor-Review - derselbe blinde Fleck gilt im Vorgängerprojekt Vector Trek, dort
 * aber nicht behoben, hier von Anfang an richtig).
 *
 * Aufruf: PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8199 -t public public/index.php &
 *         php bin/tests.php
 */

require __DIR__ . '/../src/Database.php';

const BASE_URL = 'http://127.0.0.1:8199';

$pdo = Database::connection();
$createdDeviceIds = [];
$failures = 0;

function apiCall(string $method, string $path, ?array $body = null, ?string $token = null): array
{
    $ch = curl_init(BASE_URL . $path);
    $headers = ['Content-Type: application/json'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['status' => $status, 'body' => json_decode($raw, true)];
}

function registerPlayer(PDO $pdo, array &$createdDeviceIds, string $prefix): array
{
    $deviceId = $prefix . '-' . bin2hex(random_bytes(4)) . '-' . time();
    $createdDeviceIds[] = $deviceId;
    // Server begrenzt Namen auf max. 20 Zeichen - lange Test-Prefixe müssen gekürzt werden,
    // damit noch Platz für ein paar Zufallszeichen bleibt (Eindeutigkeit bei parallelen Läufen).
    $username = substr($prefix, 0, 14) . bin2hex(random_bytes(3));
    $res = apiCall('POST', '/api/register', ['deviceId' => $deviceId, 'username' => $username, 'universeId' => 1]);
    if ($res['status'] !== 200) {
        throw new RuntimeException('Registrierung fehlgeschlagen: ' . json_encode($res));
    }
    $token = $res['body']['token'];

    $stmt = $pdo->prepare('SELECT id FROM players WHERE device_id = ? AND universe_id = 1');
    $stmt->execute([$deviceId]);
    $playerId = (int) $stmt->fetchColumn();

    $baseStmt = $pdo->prepare('SELECT id, sector_id, position FROM bases WHERE player_id = ?');
    $baseStmt->execute([$playerId]);
    $base = $baseStmt->fetch();

    return [
        'deviceId' => $deviceId,
        'token' => $token,
        'playerId' => $playerId,
        'baseId' => (int) $base['id'],
        'sectorId' => (int) $base['sector_id'],
        'position' => (int) $base['position'],
    ];
}

function check(bool $condition, string $message): void
{
    global $failures;

    if ($condition) {
        echo "  OK   {$message}\n";
    } else {
        echo "  FAIL {$message}\n";
        $failures++;
    }
}

// --- Test 1: frischer Spieler kann BEIDE Produktionsgebäude irgendwann bezahlen ---
// Advisor-Review-Fund: ohne flache Grundproduktion softlockt sich jede erste Bau-Reihenfolge
// selbst, weil die jeweils ANDERE Ressourcenart nie wächst.
echo "Test: frische Basis kann beide Produktionsgebäude irgendwann bezahlen (kein Softlock)\n";
$p1 = registerPlayer($pdo, $createdDeviceIds, 'test-softlock');
// Titan-Raffinerie zuerst (teurer an Deuterium als an Titan übrig)
$buildRaffinerie = apiCall('POST', '/api/base/build', ['baseId' => $p1['baseId'], 'buildingTypeId' => 2], $p1['token']);
check($buildRaffinerie['status'] === 200, 'Titan-Raffinerie Level 1 ist mit Startkapital bezahlbar');
// Zeit vorspulen (Bauzeit + viele Ticks), damit Deuterium nachwächst
$pdo->prepare("UPDATE base_buildings SET finishes_at = UTC_TIMESTAMP() - INTERVAL '1:0' MINUTE_SECOND WHERE base_id = ? AND building_type_id = 2")
    ->execute([$p1['baseId']]);
$pdo->prepare('UPDATE bases SET last_tick_at = UTC_TIMESTAMP() - INTERVAL 20 MINUTE WHERE id = ?')->execute([$p1['baseId']]);
$afterWait = apiCall('GET', '/api/state', null, $p1['token'])['body'];
$base1 = $afterWait['bases'][0];
check($base1['buildings'][1]['level'] === 1, 'Titan-Raffinerie hat Level 1 erreicht');
$buildSammler = apiCall('POST', '/api/base/build', ['baseId' => $p1['baseId'], 'buildingTypeId' => 3], $p1['token']);
check(
    $buildSammler['status'] === 200,
    'Deuterium-Sammler ist nach etwas Wartezeit ebenfalls bezahlbar (Deuterium ist nachgewachsen), Status: ' . $buildSammler['status']
);

// --- Test 2: fremde Basis - weder bauen noch Flotte losschicken ---
echo "Test: fremde Basis kann weder bebaut noch für den Flottenversand genutzt werden\n";
$p2 = registerPlayer($pdo, $createdDeviceIds, 'test-foreign');
$p3 = registerPlayer($pdo, $createdDeviceIds, 'test-foreign-victim');
$foreignBuild = apiCall('POST', '/api/base/build', ['baseId' => $p3['baseId'], 'buildingTypeId' => 2], $p2['token']);
check($foreignBuild['status'] === 403, 'Ausbau an fremder Basis wird abgelehnt (403)');
$foreignDispatch = apiCall('POST', '/api/fleet/dispatch', [
    'baseId' => $p3['baseId'], 'ships' => [1 => 1], 'targetSectorId' => $p2['sectorId'], 'targetPosition' => $p2['position'],
    'mission' => 'transport', 'energyPreset' => 'weapons',
], $p2['token']);
check($foreignDispatch['status'] === 403, 'Flottenversand von fremder Basis wird abgelehnt (403)');

// --- Test 3: erneute Registrierung derselben Geräte-ID wird abgelehnt (Account-Übernahme) ---
echo "Test: erneute Registrierung derselben Geräte-ID wird abgelehnt\n";
$reregister = apiCall('POST', '/api/register', ['deviceId' => $p2['deviceId'], 'username' => 'NeuerName', 'universeId' => 1]);
check($reregister['status'] === 409, 'Zweite Registrierung mit bekannter device_id wird abgelehnt (409)');

// --- Test 3b: Werft-Warteschlange (Schiffsbau) - seit Phase 2b Teil der API ---
echo "Test: Schiffsbau kostet beim Einreihen, ist ohne Werft gesperrt, fremde Basis abgelehnt, Kette läuft offline durch\n";
$p3b = registerPlayer($pdo, $createdDeviceIds, 'test-shipyard');
$pdo->prepare('UPDATE bases SET titan = 20000, deuterium = 20000, credits = 20000 WHERE id = ?')->execute([$p3b['baseId']]);

$noShipyard = apiCall('POST', '/api/base/ships/build', ['baseId' => $p3b['baseId'], 'shipTypeId' => 1, 'quantity' => 1], $p3b['token']);
check($noShipyard['status'] === 422, 'Schiffsbau ohne Werft wird abgelehnt (422), Status: ' . $noShipyard['status']);

$foreignShipBuild = apiCall('POST', '/api/base/ships/build', ['baseId' => $p3b['baseId'], 'shipTypeId' => 1, 'quantity' => 1], $p3['token']);
check($foreignShipBuild['status'] === 403, 'Schiffsbau an fremder Basis wird abgelehnt (403)');

apiCall('POST', '/api/base/build', ['baseId' => $p3b['baseId'], 'buildingTypeId' => 4], $p3b['token']); // Werft bauen
$pdo->prepare("UPDATE base_buildings SET finishes_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE base_id = ? AND building_type_id = 4")
    ->execute([$p3b['baseId']]);

$beforeBuild = apiCall('GET', '/api/state', null, $p3b['token'])['body']['bases'][0];
$order = apiCall('POST', '/api/base/ships/build', ['baseId' => $p3b['baseId'], 'shipTypeId' => 1, 'quantity' => 3], $p3b['token']);
check($order['status'] === 200, 'Schiffsbau-Bestellung (3 Scouts) wird angenommen, Status: ' . $order['status']);
$scoutCost = 300; // ship_types.cost_titan für Scout (Migration 002)
check(
    $order['body']['titan'] === $beforeBuild['titan'] - 3 * $scoutCost,
    'Kosten für alle 3 Scouts werden SOFORT beim Einreihen abgebucht, nicht erst beim Bau'
);
check(count($order['body']['shipQueue']) === 1 && $order['body']['shipQueue'][0]['quantity'] === 3, 'Werft-Warteschlange zeigt den offenen Auftrag mit Stückzahl 3');

// Zeit weit genug vorspulen, dass alle 3 Einheiten (virtuelle Kette) fertig werden, während der
// Spieler "offline" war - exakt dieselbe Lektion wie bei base_build_queue in Phase 2.
$pdo->prepare('UPDATE base_ship_queue SET started_at = UTC_TIMESTAMP() - INTERVAL 10 MINUTE, finishes_at = UTC_TIMESTAMP() - INTERVAL 9 MINUTE WHERE base_id = ?')
    ->execute([$p3b['baseId']]);
$afterOffline = apiCall('GET', '/api/state', null, $p3b['token'])['body']['bases'][0];
check($afterOffline['shipQueue'] === [], 'Werft-Warteschlange ist nach ausreichend Wartezeit komplett leer');
check(
    ($afterOffline['ships'][0]['quantity'] ?? 0) === 3,
    'Alle 3 Scouts sind fertig, nicht nur einer - virtuelle Zeitverkettung verarbeitet die ganze Kette offline'
);

// Werft steht bei p3b auf Level 1 - Kreuzer (Schiffstyp 3) braucht laut Katalog Level 3, muss also
// trotz bezahlbarer Rohstoffe abgelehnt werden (Phase 4: Werft-Stufen-Freischaltung).
$cruiserLocked = apiCall('POST', '/api/base/ships/build', ['baseId' => $p3b['baseId'], 'shipTypeId' => 3, 'quantity' => 1], $p3b['token']);
check($cruiserLocked['status'] === 422, 'Kreuzer ohne ausreichendes Werft-Level wird abgelehnt (422), Status: ' . $cruiserLocked['status']);

// --- Vorbereitung für Flotten-/Kampf-Tests: Testspieler bekommen Schiffe direkt in den Hangar ---
// (einfacher als jedes Mal eine Werft hochzuziehen und die Bauzeit zu überspringen)
function giveShips(PDO $pdo, int $baseId, int $shipTypeId, int $quantity): void
{
    $pdo->prepare(
        'INSERT INTO base_ships (base_id, ship_type_id, quantity) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)'
    )->execute([$baseId, $shipTypeId, $quantity]);
}

// --- Test 4: parallele Flottenversand-Anfragen können nicht mehr Schiffe losschicken, als da sind ---
echo "Test: parallele Flottenversand-Anfragen überschreiten den Hangar-Bestand nicht (mehrere Worker nötig!)\n";
$p4 = registerPlayer($pdo, $createdDeviceIds, 'test-race');
giveShips($pdo, $p4['baseId'], 2, 5); // 5 Abfangjäger
$pdo->prepare('UPDATE bases SET deuterium = 100000 WHERE id = ?')->execute([$p4['baseId']]);
// Ziel: ein leeres Feld in einem Nachbar-Sektor für eine harmlose 'transport'-Mission auf die
// EIGENE Basis (einfachster gültiger Dispatch, der nicht von Kampf-Timing abhängt).
$multiHandle = curl_multi_init();
$handles = [];
for ($i = 0; $i < 6; $i++) {
    $ch = curl_init(BASE_URL . '/api/fleet/dispatch');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $p4['token']]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'baseId' => $p4['baseId'], 'ships' => [2 => 2],
        'targetSectorId' => $p4['sectorId'], 'targetPosition' => $p4['position'],
        'mission' => 'transport', 'energyPreset' => 'weapons',
    ]));
    curl_multi_add_handle($multiHandle, $ch);
    $handles[] = $ch;
}
do {
    curl_multi_exec($multiHandle, $running);
} while ($running);
$accepted = 0;
foreach ($handles as $ch) {
    if (curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200) {
        $accepted++;
    }
    curl_multi_remove_handle($multiHandle, $ch);
    curl_close($ch);
}
curl_multi_close($multiHandle);
// 5 Schiffe vorhanden, je Versand 2 angefragt -> höchstens 2 Versände können gelingen (4 von 5),
// ein dritter (6 von 5) darf NICHT mehr gelingen.
check($accepted === 2, "Von 6 parallelen Versand-Anfragen (je 2 Schiffe, 5 vorhanden) gelingen genau 2, nicht mehr, sind {$accepted}");
$remainingShipsStmt = $pdo->prepare('SELECT quantity FROM base_ships WHERE base_id = ? AND ship_type_id = 2');
$remainingShipsStmt->execute([$p4['baseId']]);
$remainingShips = (int) $remainingShipsStmt->fetchColumn();
check($remainingShips === 1, "Nach 2 erfolgreichen Versänden (je 2 von 5) bleibt genau 1 Schiff im Hangar, sind {$remainingShips}");

// --- Test 5: Anfängerschutz verhindert einen Angriff ---
echo "Test: Anfängerschutz verhindert einen Angriff auf eine frische Basis\n";
$p5 = registerPlayer($pdo, $createdDeviceIds, 'test-protected');
$p6 = registerPlayer($pdo, $createdDeviceIds, 'test-attacker-blocked');
giveShips($pdo, $p6['baseId'], 3, 5); // 5 Kreuzer
$pdo->prepare('UPDATE bases SET deuterium = 100000 WHERE id = ?')->execute([$p6['baseId']]);
$blockedAttack = apiCall('POST', '/api/fleet/dispatch', [
    'baseId' => $p6['baseId'], 'ships' => [3 => 5],
    'targetSectorId' => $p5['sectorId'], 'targetPosition' => $p5['position'],
    'mission' => 'attack', 'energyPreset' => 'weapons',
], $p6['token']);
check($blockedAttack['status'] === 422, 'Angriff auf eine Basis unter Anfängerschutz wird abgelehnt (422)');

// --- Test 6: Schildgenerator macht eine Basis NICHT unverwundbar ---
echo "Test: Schildgenerator blockt nicht für immer dieselbe Menge (keine Unverwundbarkeit)\n";
$p7 = registerPlayer($pdo, $createdDeviceIds, 'test-shield-defender');
$p8 = registerPlayer($pdo, $createdDeviceIds, 'test-shield-attacker');
$pdo->prepare('UPDATE bases SET protected_until = NULL WHERE id = ?')->execute([$p7['baseId']]);
$pdo->prepare(
    "INSERT INTO base_buildings (base_id, building_type_id, level, status) VALUES (?, 5, 1, 'idle')
     ON DUPLICATE KEY UPDATE level = 1, status = 'idle'"
)->execute([$p7['baseId']]); // Schildgenerator Level 1 (500 Schild)
giveShips($pdo, $p8['baseId'], 3, 20); // 20 Kreuzer (20*80=1600 Angriff, weit über 500 Schild)
$pdo->prepare('UPDATE bases SET deuterium = 100000 WHERE id = ?')->execute([$p8['baseId']]);
$shieldAttack = apiCall('POST', '/api/fleet/dispatch', [
    'baseId' => $p8['baseId'], 'ships' => [3 => 20],
    'targetSectorId' => $p7['sectorId'], 'targetPosition' => $p7['position'],
    'mission' => 'attack', 'energyPreset' => 'weapons',
], $p8['token']);
check($shieldAttack['status'] === 200, 'Angriff auf geschildete, aber verteidigungslose Basis wird angenommen');
$fleetIdShield = $shieldAttack['body']['fleetId'];
$pdo->prepare("UPDATE fleets SET arrives_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE id = ?")->execute([$fleetIdShield]);
$afterShieldCombat = apiCall('GET', '/api/state', null, $p8['token'])['body'];
$shieldReport = $afterShieldCombat['combatReports'][0] ?? null;
check($shieldReport !== null, 'Kampfbericht wurde erstellt');
check(
    $shieldReport !== null && $shieldReport['outcome'] === 'attacker_win',
    'Angreifer gewinnt trotz Schildgenerator (Schild hält nicht ewig), Ergebnis: ' . ($shieldReport['outcome'] ?? 'n/a')
);

// --- Test 7: Plünderung ist auf 50% UND auf den Frachtraum gedeckelt, NPC-Beute erschöpft sich ---
echo "Test: Plünderung gedeckelt (50% / Frachtraum), NPC-Außenposten erschöpft seine Beute\n";
$npcStmt = $pdo->query('SELECT id, sector_id, position, loot_titan, loot_deuterium, loot_credits FROM npc_outposts LIMIT 1');
$npc = $npcStmt->fetch();
$pdo->prepare('UPDATE npc_outposts SET last_raided_at = NULL, defense_strength = 1 WHERE id = ?')->execute([$npc['id']]);
$p9 = registerPlayer($pdo, $createdDeviceIds, 'test-plunder');
giveShips($pdo, $p9['baseId'], 3, 10); // 10 Kreuzer, massiv überlegen gegen defense_strength=1
$pdo->prepare('UPDATE bases SET deuterium = 100000 WHERE id = ?')->execute([$p9['baseId']]);
$plunder1 = apiCall('POST', '/api/fleet/dispatch', [
    'baseId' => $p9['baseId'], 'ships' => [3 => 10],
    'targetSectorId' => $npc['sector_id'], 'targetPosition' => $npc['position'],
    'mission' => 'plunder', 'energyPreset' => 'weapons',
], $p9['token']);
check($plunder1['status'] === 200, 'Plünderung gegen NPC-Außenposten wird angenommen');
$pdo->prepare('UPDATE fleets SET arrives_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE id = ?')->execute([$plunder1['body']['fleetId']]);
$afterPlunder1 = apiCall('GET', '/api/state', null, $p9['token'])['body'];
// Direkt per ID aus der DB statt über state()['combatReports'][0] - zwei Auflösungen können
// denselben resolved_at-Zeitstempel bekommen (Sekunden-Genauigkeit), id DESC ist der einzige
// verlässliche Tie-Breaker (siehe GameEngine::state()-Kommentar, dort genauso gefixt).
$report1 = $pdo->query(
    "SELECT * FROM combat_reports WHERE attacker_player_id = {$p9['playerId']} ORDER BY id DESC LIMIT 1"
)->fetch();
// Riesige Flotte (1000 statt 10 Schiffe) - die Frachtkapazität darf hier NICHT der bindende
// Faktor sein, sonst kürzt sich der Verfügbarkeits-Faktor beim Vergleich rechnerisch heraus
// (Beute = Pool * Verfügbarkeit, gedeckelt auf Fracht; wenn beide Versuche frachtraum-gedeckelt
// sind, kommt in BEIDEN Fällen derselbe Wert raus, unabhängig von der echten Verfügbarkeit -
// genau das beim ersten Testlauf beobachtet, kein Engine-Bug, nur ein zu kleiner Testaufbau).
giveShips($pdo, $p9['baseId'], 3, 1000);
$pdo->prepare('UPDATE bases SET deuterium = 1000000 WHERE id = ?')->execute([$p9['baseId']]);
$maxCargo = 1000 * 50;
check(
    (int) $report1['loot_titan'] <= (int) round($npc['loot_titan'] * 0.5),
    "Beute ({$report1['loot_titan']}) übersteigt nicht 50% des Bestands ({$npc['loot_titan']})"
);
check(
    (int) $report1['loot_titan'] + (int) $report1['loot_deuterium'] + (int) $report1['loot_credits'] <= $maxCargo,
    'Gesamtbeute übersteigt nicht die Frachtkapazität der Flotte'
);

// Sofort nochmal plündern (last_raided_at ist jetzt gesetzt) - Beute muss SPÜRBAR geringer sein.
$plunder2 = apiCall('POST', '/api/fleet/dispatch', [
    'baseId' => $p9['baseId'], 'ships' => [3 => 1000],
    'targetSectorId' => $npc['sector_id'], 'targetPosition' => $npc['position'],
    'mission' => 'plunder', 'energyPreset' => 'weapons',
], $p9['token']);
$pdo->prepare('UPDATE fleets SET arrives_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE id = ?')->execute([$plunder2['body']['fleetId']]);
apiCall('GET', '/api/state', null, $p9['token']);
$report2 = $pdo->query(
    "SELECT * FROM combat_reports WHERE attacker_player_id = {$p9['playerId']} ORDER BY id DESC LIMIT 1"
)->fetch();
check(
    (int) $report2['loot_titan'] < (int) $report1['loot_titan'],
    "Zweite Plünderung direkt danach bringt spürbar weniger Beute ({$report2['loot_titan']} < {$report1['loot_titan']}) - Außenposten hat sich noch nicht erholt"
);

// --- Test 8: zwei Kolonieschiffe auf denselben freien Slot - genau eines gewinnt ---
echo "Test: zwei gleichzeitig ankommende Kolonieschiffe auf demselben Slot - genau eines gewinnt\n";
$freeSectorStmt = $pdo->query(
    "SELECT s.id AS sector_id, pos.position FROM sectors s
     CROSS JOIN (SELECT 0 AS position UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7) pos
     WHERE NOT EXISTS (SELECT 1 FROM bases b WHERE b.sector_id = s.id AND b.position = pos.position)
       AND NOT EXISTS (SELECT 1 FROM npc_outposts n WHERE n.sector_id = s.id AND n.position = pos.position)
       AND NOT EXISTS (SELECT 1 FROM asteroid_fields a WHERE a.sector_id = s.id AND a.position = pos.position)
       AND NOT EXISTS (SELECT 1 FROM nebulae ne WHERE ne.sector_id = s.id AND ne.position = pos.position)
     LIMIT 1"
);
$freeSlot = $freeSectorStmt->fetch();
$p10 = registerPlayer($pdo, $createdDeviceIds, 'test-colonize-a');
$p11 = registerPlayer($pdo, $createdDeviceIds, 'test-colonize-b');
giveShips($pdo, $p10['baseId'], 5, 1);
giveShips($pdo, $p11['baseId'], 5, 1);
$pdo->prepare('UPDATE bases SET deuterium = 100000 WHERE id IN (?, ?)')->execute([$p10['baseId'], $p11['baseId']]);
$colA = apiCall('POST', '/api/fleet/dispatch', [
    'baseId' => $p10['baseId'], 'ships' => [5 => 1],
    'targetSectorId' => $freeSlot['sector_id'], 'targetPosition' => $freeSlot['position'],
    'mission' => 'colonize', 'energyPreset' => 'weapons',
], $p10['token']);
$colB = apiCall('POST', '/api/fleet/dispatch', [
    'baseId' => $p11['baseId'], 'ships' => [5 => 1],
    'targetSectorId' => $freeSlot['sector_id'], 'targetPosition' => $freeSlot['position'],
    'mission' => 'colonize', 'energyPreset' => 'weapons',
], $p11['token']);
check($colA['status'] === 200 && $colB['status'] === 200, 'Beide Kolonisierungs-Flüge starten erfolgreich (Konflikt zeigt sich erst bei Ankunft)');
$pdo->prepare('UPDATE fleets SET arrives_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE id IN (?, ?)')
    ->execute([$colA['body']['fleetId'], $colB['body']['fleetId']]);
apiCall('GET', '/api/state', null, $p10['token']); // löst die globale Auflösung aus
$newBasesStmt = $pdo->prepare('SELECT COUNT(*) FROM bases WHERE sector_id = ? AND position = ?');
$newBasesStmt->execute([$freeSlot['sector_id'], $freeSlot['position']]);
check((int) $newBasesStmt->fetchColumn() === 1, 'Am umkämpften Slot steht hinterher GENAU eine neue Basis');

// --- Test 9: Verteidiger-Ausgaben NACH dem fälligen Angriffszeitpunkt zählen nicht rückwirkend ---
// Zentraler Advisor-Fund: globale Ereignis-Reihenfolge. Verteidiger baut NACH T1 (Angriffszeit)
// etwas, das die Plünderung eigentlich schon hätte mitnehmen müssen - die Plünderung muss trotzdem
// den Bestand VON T1 sehen, nicht den schon verminderten Bestand von "jetzt".
echo "Test: Plünderung sieht den Rohstoffbestand zum fälligen Zeitpunkt, nicht spätere Ausgaben\n";
$p12 = registerPlayer($pdo, $createdDeviceIds, 'test-timeline-defender');
$p13 = registerPlayer($pdo, $createdDeviceIds, 'test-timeline-attacker');
$pdo->prepare('UPDATE bases SET protected_until = NULL, titan = 1000, deuterium = 1000, credits = 1000 WHERE id = ?')
    ->execute([$p12['baseId']]);
giveShips($pdo, $p13['baseId'], 3, 15);
$pdo->prepare('UPDATE bases SET deuterium = 100000 WHERE id = ?')->execute([$p13['baseId']]);
$timelineAttack = apiCall('POST', '/api/fleet/dispatch', [
    'baseId' => $p13['baseId'], 'ships' => [3 => 15],
    'targetSectorId' => $p12['sectorId'], 'targetPosition' => $p12['position'],
    'mission' => 'plunder', 'energyPreset' => 'weapons',
], $p13['token']);
// Angriff auf T1 (vor "jetzt") zurückdatieren, ABER NOCH NICHT AUFLÖSEN LASSEN.
$pdo->prepare('UPDATE fleets SET arrives_at = UTC_TIMESTAMP() - INTERVAL 10 SECOND WHERE id = ?')
    ->execute([$timelineAttack['body']['fleetId']]);
// Verteidiger gibt (scheinbar NACH T1, aber vor der tatsächlichen Auflösung) fast alles aus.
$spendBuild = apiCall('POST', '/api/base/build', ['baseId' => $p12['baseId'], 'buildingTypeId' => 6], $p12['token']);
check($spendBuild['status'] === 200, 'Verteidiger kann (scheinbar) noch Ressourcen ausgeben, bevor die Schlacht aufgelöst wurde');
// Jetzt die Auflösung anstoßen (irgendein API-Aufruf reicht).
apiCall('GET', '/api/state', null, $p13['token']);
$timelineReportStmt = $pdo->prepare('SELECT * FROM combat_reports WHERE attacker_player_id = ? ORDER BY id DESC LIMIT 1');
$timelineReportStmt->execute([$p13['playerId']]);
$timelineReport = $timelineReportStmt->fetch();
check(
    (int) $timelineReport['loot_titan'] > 0,
    'Beute basiert auf dem Bestand VOR der (scheinbar dazwischengekommenen) Verteidiger-Ausgabe, Beute: ' . $timelineReport['loot_titan']
);

// --- Test 10: eine fällige Flottenankunft wird NUR EINMAL aufgelöst, auch bei parallelen state()-Aufrufen ---
echo "Test: eine fällige Flottenankunft wird bei parallelen state()-Aufrufen nur einmal aufgelöst\n";
$p14 = registerPlayer($pdo, $createdDeviceIds, 'test-once-defender');
$p15 = registerPlayer($pdo, $createdDeviceIds, 'test-once-attacker');
$pdo->prepare('UPDATE bases SET protected_until = NULL WHERE id = ?')->execute([$p14['baseId']]);
giveShips($pdo, $p15['baseId'], 3, 5);
$pdo->prepare('UPDATE bases SET deuterium = 100000 WHERE id = ?')->execute([$p15['baseId']]);
$onceAttack = apiCall('POST', '/api/fleet/dispatch', [
    'baseId' => $p15['baseId'], 'ships' => [3 => 5],
    'targetSectorId' => $p14['sectorId'], 'targetPosition' => $p14['position'],
    'mission' => 'plunder', 'energyPreset' => 'weapons',
], $p15['token']);
$pdo->prepare('UPDATE fleets SET arrives_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE id = ?')
    ->execute([$onceAttack['body']['fleetId']]);

$multiHandle2 = curl_multi_init();
$chAttacker = curl_init(BASE_URL . '/api/state');
curl_setopt($chAttacker, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $p15['token']]);
curl_setopt($chAttacker, CURLOPT_RETURNTRANSFER, true);
$chDefender = curl_init(BASE_URL . '/api/state');
curl_setopt($chDefender, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $p14['token']]);
curl_setopt($chDefender, CURLOPT_RETURNTRANSFER, true);
curl_multi_add_handle($multiHandle2, $chAttacker);
curl_multi_add_handle($multiHandle2, $chDefender);
do {
    curl_multi_exec($multiHandle2, $running2);
} while ($running2);
curl_multi_remove_handle($multiHandle2, $chAttacker);
curl_multi_remove_handle($multiHandle2, $chDefender);
curl_multi_close($multiHandle2);

$reportCountStmt = $pdo->prepare('SELECT COUNT(*) FROM combat_reports WHERE attacker_player_id = ?');
$reportCountStmt->execute([$p15['playerId']]);
check((int) $reportCountStmt->fetchColumn() === 1, 'Genau EIN Kampfbericht entsteht, nicht zwei (keine doppelte Auflösung)');

// --- Test 16: Allianzen - gründen, beitreten, Mitglieder schützen sich gegenseitig, Kapazität ---
echo "Test: Allianz gründen/beitreten schützt Mitglieder vor gegenseitigem Angriff, member_cap wird nicht überschritten (mehrere Worker nötig!)\n";
$a1 = registerPlayer($pdo, $createdDeviceIds, 'test-ally-leader');
$allianceTag = 'T' . random_int(1000, 9999);
$createAlliance = apiCall('POST', '/api/alliance/create', ['name' => 'Testallianz ' . $allianceTag, 'tag' => $allianceTag], $a1['token']);
check($createAlliance['status'] === 200, 'Allianz wird gegründet, Status: ' . $createAlliance['status']);
$allianceId = $createAlliance['body']['id'];

$a2 = registerPlayer($pdo, $createdDeviceIds, 'test-ally-member');
$joinResult = apiCall('POST', '/api/alliance/join', ['allianceId' => $allianceId], $a2['token']);
check($joinResult['status'] === 200 && count($joinResult['body']['members']) === 2, 'Zweiter Spieler tritt bei, Allianz hat 2 Mitglieder');

giveShips($pdo, $a2['baseId'], 2, 5); // 5 Abfangjäger für a2
$pdo->prepare('UPDATE bases SET deuterium = 100000 WHERE id = ?')->execute([$a2['baseId']]);
$allyAttack = apiCall('POST', '/api/fleet/dispatch', [
    'baseId' => $a2['baseId'], 'ships' => [2 => 2],
    'targetSectorId' => $a1['sectorId'], 'targetPosition' => $a1['position'],
    'mission' => 'attack', 'energyPreset' => 'weapons',
], $a2['token']);
check($allyAttack['status'] === 422, 'Angriff auf ein Allianzmitglied wird abgelehnt (422), Status: ' . $allyAttack['status']);

$a3 = registerPlayer($pdo, $createdDeviceIds, 'test-ally-outsider');
$outsiderAttackAttempt = apiCall('POST', '/api/alliance/join', ['allianceId' => $allianceId], $a1['token']);
check($outsiderAttackAttempt['status'] === 422, 'Bereits-Mitglied kann keiner (weiteren) Allianz beitreten (422)');

$kickResult = apiCall('POST', '/api/alliance/kick', ['playerId' => $a2['playerId']], $a1['token']);
check($kickResult['status'] === 200 && count($kickResult['body']['members']) === 1, 'Anführer kann Mitglied entfernen, Allianz hat danach 1 Mitglied');
$kickByMember = apiCall('POST', '/api/alliance/kick', ['playerId' => $a1['playerId']], $a3['token']);
check($kickByMember['status'] === 403, 'Nicht-Anführer kann niemanden entfernen (403)');

// member_cap race: Allianz auf Kapazität 2 setzen, dann 6 Spieler parallel beitreten lassen -
// dieselbe Lektion wie beim Hangar-Bestand: FOR UPDATE auf der Allianz-Zeile MUSS die Kapazität
// tatsächlich durchsetzen, nicht nur das letzte Ergebnis eines nicht-atomaren Zählers sein.
$pdo->prepare('UPDATE alliances SET member_cap = 2 WHERE id = ?')->execute([$allianceId]);
$capCandidates = [];
for ($i = 0; $i < 6; $i++) {
    $capCandidates[] = registerPlayer($pdo, $createdDeviceIds, 'test-ally-cap' . $i);
}
$multiHandle3 = curl_multi_init();
$capHandles = [];
foreach ($capCandidates as $cand) {
    $ch = curl_init(BASE_URL . '/api/alliance/join');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $cand['token']]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['allianceId' => $allianceId]));
    curl_multi_add_handle($multiHandle3, $ch);
    $capHandles[] = $ch;
}
do {
    curl_multi_exec($multiHandle3, $running3);
} while ($running3);
$capSuccesses = 0;
foreach ($capHandles as $ch) {
    $res = json_decode(curl_multi_getcontent($ch), true);
    if (($res['id'] ?? null) === $allianceId) {
        $capSuccesses++;
    }
    curl_multi_remove_handle($multiHandle3, $ch);
}
curl_multi_close($multiHandle3);
$finalMemberCountStmt = $pdo->prepare('SELECT COUNT(*) FROM alliance_members WHERE alliance_id = ?');
$finalMemberCountStmt->execute([$allianceId]);
check((int) $finalMemberCountStmt->fetchColumn() === 2, 'Allianz hat trotz 6 paralleler Beitritte genau member_cap=2 Mitglieder (1 Anführer + 1 neues)');
check($capSuccesses === 1, 'Von 6 parallelen Beitritten gelingt genau 1 (die Kapazität lässt noch 1 Platz zu), sind ' . $capSuccesses);

// --- Test 17: Allianz-Name/-Tag mit HTML/JS wird abgelehnt (XSS-Fund, Advisor-Review) ---
echo "Test: Allianz-Name/-Tag mit ungültigen Zeichen (HTML/JS) wird abgelehnt\n";
$xssAttempt = apiCall('POST', '/api/alliance/create', [
    'name' => 'Böse<img src=x onerror=alert(1)>', 'tag' => 'XSS',
], $a3['token']);
check($xssAttempt['status'] === 422, 'Allianz-Name mit HTML-Zeichen wird abgelehnt (422), Status: ' . $xssAttempt['status']);

// --- Test 18: Verlassen - Anführerwechsel bei >1 Mitglied, Allianz wird gelöscht bei 0 Mitgliedern ---
echo "Test: Allianz-Anführerwechsel beim Verlassen, Löschung bei letztem Mitglied\n";
$b1 = registerPlayer($pdo, $createdDeviceIds, 'test-leave-1');
$leaveAllianceCreate = apiCall('POST', '/api/alliance/create', ['name' => 'Verlasstest', 'tag' => 'VLT'], $b1['token']);
$leaveAllianceId = $leaveAllianceCreate['body']['id'];
$b2 = registerPlayer($pdo, $createdDeviceIds, 'test-leave-2');
apiCall('POST', '/api/alliance/join', ['allianceId' => $leaveAllianceId], $b2['token']);

$leaderLeaves = apiCall('POST', '/api/alliance/leave', [], $b1['token']);
check($leaderLeaves['status'] === 200, 'Anführer kann die Allianz verlassen, Status: ' . $leaderLeaves['status']);
$afterLeaderLeftStmt = $pdo->prepare('SELECT leader_player_id FROM alliances WHERE id = ?');
$afterLeaderLeftStmt->execute([$leaveAllianceId]);
check((int) $afterLeaderLeftStmt->fetchColumn() === $b2['playerId'], 'Führung geht automatisch an das verbleibende (dienstälteste) Mitglied über');

$lastMemberLeaves = apiCall('POST', '/api/alliance/leave', [], $b2['token']);
check($lastMemberLeaves['status'] === 200, 'Letztes Mitglied kann ebenfalls verlassen, Status: ' . $lastMemberLeaves['status']);
$allianceGoneStmt = $pdo->prepare('SELECT COUNT(*) FROM alliances WHERE id = ?');
$allianceGoneStmt->execute([$leaveAllianceId]);
check((int) $allianceGoneStmt->fetchColumn() === 0, 'Allianz wird gelöscht, sobald das letzte Mitglied sie verlässt (keine führerlose Hülle)');

// --- Aufräumen ---
echo "\nRäume Testdaten auf...\n";
$placeholders = implode(',', array_fill(0, count($createdDeviceIds), '?'));
$idsStmt = $pdo->prepare("SELECT id FROM players WHERE device_id IN ($placeholders)");
$idsStmt->execute($createdDeviceIds);
$playerIds = $idsStmt->fetchAll(PDO::FETCH_COLUMN);

if ($playerIds !== []) {
    $idPh = implode(',', array_fill(0, count($playerIds), '?'));
    $baseIdsStmt = $pdo->prepare("SELECT id FROM bases WHERE player_id IN ($idPh)");
    $baseIdsStmt->execute($playerIds);
    $allBaseIds = $baseIdsStmt->fetchAll(PDO::FETCH_COLUMN);
    $basePh = $allBaseIds !== [] ? implode(',', array_fill(0, count($allBaseIds), '?')) : null;

    // Fleets, die zu diesen Spielern ODER auf diese Basen gezielt haben
    $fleetIdsStmt = $pdo->prepare("SELECT id FROM fleets WHERE player_id IN ($idPh)");
    $fleetIdsStmt->execute($playerIds);
    $fleetIds = $fleetIdsStmt->fetchAll(PDO::FETCH_COLUMN);
    if ($fleetIds !== []) {
        $fleetPh = implode(',', array_fill(0, count($fleetIds), '?'));
        $pdo->prepare("DELETE FROM fleet_ships WHERE fleet_id IN ($fleetPh)")->execute($fleetIds);
        $pdo->prepare("DELETE FROM fleets WHERE id IN ($fleetPh)")->execute($fleetIds);
    }
    $pdo->prepare("DELETE FROM combat_reports WHERE attacker_player_id IN ($idPh) OR defender_player_id IN ($idPh)")
        ->execute(array_merge($playerIds, $playerIds));

    $pdo->prepare("DELETE FROM alliance_members WHERE player_id IN ($idPh)")->execute($playerIds);
    $pdo->prepare("DELETE FROM alliances WHERE leader_player_id IN ($idPh)")->execute($playerIds);

    if ($basePh !== null) {
        $pdo->prepare("DELETE FROM base_build_queue WHERE base_id IN ($basePh)")->execute($allBaseIds);
        $pdo->prepare("DELETE FROM base_ship_queue WHERE base_id IN ($basePh)")->execute($allBaseIds);
        $pdo->prepare("DELETE FROM base_buildings WHERE base_id IN ($basePh)")->execute($allBaseIds);
        $pdo->prepare("DELETE FROM base_ships WHERE base_id IN ($basePh)")->execute($allBaseIds);
        $pdo->prepare("DELETE FROM bases WHERE id IN ($basePh)")->execute($allBaseIds);
    }

    $pdo->prepare("DELETE FROM user_tokens WHERE player_id IN ($idPh)")->execute($playerIds);
    $pdo->prepare("DELETE FROM players WHERE id IN ($idPh)")->execute($playerIds);
}

// Durch Kolonisierung/Tests ggf. verbrauchte oder neu entstandene Test-Artefakte: neu
// kolonisierte Basen OHNE player_id-Match (sollten keine mehr übrig sein, s.o.) - defensiv keine
// weitere Aktion nötig, das obige deckt alle über registerPlayer() erzeugten Spieler ab.

echo "\n" . ($failures === 0 ? "Alle Tests bestanden.\n" : "{$failures} Test(s) fehlgeschlagen.\n");
exit($failures === 0 ? 0 : 1);
