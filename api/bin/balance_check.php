<?php

/**
 * Grober Balance-Check, kein Unit-Test (siehe bin/tests.php für das): liest echte Katalogdaten
 * aus der Dev-DB und nutzt die ECHTEN Formeln aus GameEngine/CombatResolver (private Konstanten
 * per Reflection, CombatResolver::resolve() direkt - KEINE Formel wird hier manuell
 * nachgebaut). Rechnet bei game_speed=1 (Produktions-Tempo), nicht beim lokalen Dev-Wert 20.
 *
 * Aufruf: php bin/balance_check.php
 */

require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/GameEngine.php';
require __DIR__ . '/../src/CombatResolver.php';

$pdo = Database::connection();
$engine = new GameEngine($pdo, 1.0); // game_speed=1 - echtes Produktions-Tempo, nicht der 20x-Dev-Wert

$scaledSecondsMethod = new ReflectionMethod(GameEngine::class, 'scaledSeconds');
$scaledSecondsMethod->setAccessible(true);
$scaledSeconds = fn (int $base) => $scaledSecondsMethod->invoke($engine, $base);

$engineConsts = (new ReflectionClass(GameEngine::class))->getConstants();

function fmtDuration(int $seconds): string
{
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    if ($h > 0) {
        return sprintf('%dh %dm', $h, $m);
    }
    if ($m > 0) {
        return sprintf('%dm %ds', $m, $s);
    }
    return sprintf('%ds', $s);
}

echo "=== Zeit bis zum ersten Schiff (game_speed=1) ===\n";
echo "Kommandobrücke Level 1 ist laut Konzept kostenlos+sofort, zählt hier nicht mit.\n";

$werftLevel1 = $pdo->query(
    "SELECT bl.* FROM building_levels bl
     JOIN building_types bt ON bt.id = bl.building_type_id
     WHERE bt.effect = 'shipyard' AND bl.level = 1"
)->fetch();
$werftSeconds = $scaledSeconds((int) $werftLevel1['build_seconds']);

$shipTypes = $pdo->query('SELECT * FROM ship_types ORDER BY cost_titan + cost_deuterium + cost_credits')->fetchAll();
$cheapestShip = $shipTypes[0];
$shipSeconds = $scaledSeconds((int) $cheapestShip['build_seconds']);

printf(
    "Werft Level 1: %d Titan, %d Deuterium, %s Bauzeit\n",
    $werftLevel1['cost_titan'], $werftLevel1['cost_deuterium'], fmtDuration($werftSeconds)
);
printf(
    "+ günstigstes Schiff (%s): %d Titan, %d Deuterium, %d Credits, %s Bauzeit\n",
    $cheapestShip['name'], $cheapestShip['cost_titan'], $cheapestShip['cost_deuterium'], $cheapestShip['cost_credits'],
    fmtDuration($shipSeconds)
);
printf("=> Frühestens %s nach Spielstart, WENN die Rohstoffe sofort reichen würden.\n", fmtDuration($werftSeconds + $shipSeconds));

$titanRate = $engineConsts['BASE_TITAN_PER_TICK'];
$deuteriumRate = $engineConsts['BASE_DEUTERIUM_PER_TICK'];
$tickSeconds = $scaledSeconds($engineConsts['TICK_SECONDS']);
$titanPerSecond = $titanRate / $tickSeconds;
$deuteriumPerSecond = $deuteriumRate / $tickSeconds;
$neededTitan = (int) $werftLevel1['cost_titan'] + (int) $cheapestShip['cost_titan'];
$neededDeuterium = (int) $werftLevel1['cost_deuterium'] + (int) $cheapestShip['cost_deuterium'];
$startTitan = 500; // siehe Seed/Register-Startwerte
$startDeuterium = 300;
$waitForTitan = max(0, ($neededTitan - $startTitan) / $titanPerSecond);
$waitForDeuterium = max(0, ($neededDeuterium - $startDeuterium) / $deuteriumPerSecond);
printf(
    "Realistisch (Start: %d Titan/%d Deuterium, Grundproduktion %d/%d pro %s): zusätzlich ~%s warten, bis die Rohstoffe reichen.\n",
    $startTitan, $startDeuterium, $titanRate, $deuteriumRate, fmtDuration($tickSeconds),
    fmtDuration((int) round(max($waitForTitan, $waitForDeuterium)))
);

echo "\n=== NPC-Außenposten: Beute vs. Flottenkosten (ECHTE Außenposten-Zeilen, echte capLootByCargo()/npcLootAvailability() per Reflection) ===\n";
echo "(NPC-Beute ist NICHT durch PLUNDER_MAX_PERCENT gedeckelt - das gilt nur für Spielerbasen.)\n";

$interceptor = array_values(array_filter($shipTypes, fn ($s) => $s['name'] === 'Abfangjäger'))[0];
$transporter = array_values(array_filter($shipTypes, fn ($s) => $s['name'] === 'Transporter'))[0];
$npcDefenseMethod = new ReflectionMethod(CombatResolver::class, 'npcDefenseAsShip');
$npcDefenseMethod->setAccessible(true);
$capLootMethod = new ReflectionMethod(GameEngine::class, 'capLootByCargo');
$capLootMethod->setAccessible(true);
$npcLootAvailabilityMethod = new ReflectionMethod(GameEngine::class, 'npcLootAvailability');
$npcLootAvailabilityMethod->setAccessible(true);

// 4 ECHTE Außenposten-Zeilen über das Staerke-Spektrum verteilt, keine erfundenen Werte.
$npcCount = (int) $pdo->query('SELECT COUNT(*) FROM npc_outposts')->fetchColumn();
$sampleNpcs = [];
if ($npcCount > 0) {
    foreach ([0.0, 0.33, 0.66, 0.99] as $pct) {
        $offset = min($npcCount - 1, (int) floor($pct * $npcCount));
        $row = $pdo->query("SELECT * FROM npc_outposts ORDER BY defense_strength ASC LIMIT 1 OFFSET $offset")->fetch();
        $sampleNpcs[$row['id']] = $row; // dedupliziert über die ID, falls zwei Perzentile denselben Offset treffen
    }
}

foreach ($sampleNpcs as $npc) {
    $defenseStrength = (int) $npc['defense_strength'];
    $defenderShips = $npcDefenseMethod->invoke(null, $defenseStrength);
    $won = false;
    for ($qty = 1; $qty <= 200; $qty++) {
        $attackerShips = [['type' => $interceptor, 'quantity' => $qty]];
        $combat = CombatResolver::resolve($attackerShips, $defenderShips, 0, 'weapons');
        if ($combat['outcome'] === 'attacker_win') {
            $won = true;
            break;
        }
    }
    if (!$won) {
        printf("defense_strength=%-3d: selbst 200 Abfangjäger gewinnen nicht (Formel/Daten prüfen)\n", $defenseStrength);
        continue;
    }

    $availability = $npcLootAvailabilityMethod->invoke($engine, $npc); // 1.0, da last_raided_at hier NULL ist
    $rawLoot = [
        'titan' => (int) round($npc['loot_titan'] * $availability),
        'deuterium' => (int) round($npc['loot_deuterium'] * $availability),
        'credits' => (int) round($npc['loot_credits'] * $availability),
    ];

    $combatOnlyAttackerShips = [['type' => $interceptor, 'quantity' => $qty]];
    $cappedCombatOnly = $capLootMethod->invoke($engine, $rawLoot, $combatOnlyAttackerShips);

    $fleetCostTitan = $qty * (int) $interceptor['cost_titan'];
    $fleetCostDeuterium = $qty * (int) $interceptor['cost_deuterium'];
    // Echte Kosten sind nur der ANTEILIGE Verlust (attackerLossRatio), nicht die volle Flotte -
    // die Flotte selbst geht bei einem Sieg nicht verloren, nur ein Teil nimmt Hüllenschaden.
    $lossRatio = $combat['attackerLossRatio'];
    $actualCostTitan = (int) round($fleetCostTitan * $lossRatio);
    $actualCostDeuterium = (int) round($fleetCostDeuterium * $lossRatio);

    $rawTotal = $rawLoot['titan'] + $rawLoot['deuterium'] + $rawLoot['credits'];
    $cappedTotal = $cappedCombatOnly['titan'] + $cappedCombatOnly['deuterium'] + $cappedCombatOnly['credits'];

    printf(
        "defense_strength=%-3d: %3d Abfangjäger nötig. Beute roh: %d Titan/%d Deut./%d Cr. -> nach Frachtraum-Deckel (nur Kampfschiffe, %d Frachtraum gesamt): %d Titan/%d Deut./%d Cr. (%.0f%% der rohen Beute geht verloren, kein Platz)\n",
        $defenseStrength, $qty, $rawLoot['titan'], $rawLoot['deuterium'], $rawLoot['credits'],
        $qty * (int) $interceptor['cargo_capacity'], $cappedCombatOnly['titan'], $cappedCombatOnly['deuterium'], $cappedCombatOnly['credits'],
        $rawTotal > 0 ? (1 - $cappedTotal / $rawTotal) * 100 : 0
    );
    printf(
        "    Tatsächlicher Flottenverlust (Hüllenschaden-Anteil %.0f%%): ~%d Titan, ~%d Deuterium -> %s\n",
        $lossRatio * 100, $actualCostTitan, $actualCostDeuterium,
        $cappedTotal >= ($actualCostTitan + $actualCostDeuterium) ? 'Beute deckt den tatsächlichen Verlust' : 'Beute deckt den tatsächlichen Verlust NICHT'
    );

    $neededCargo = $rawTotal;
    $transportersNeeded = (int) ceil(max(0, $neededCargo - $qty * (int) $interceptor['cargo_capacity']) / (int) $transporter['cargo_capacity']);
    printf(
        "    Mit Transporter-Eskorte (je %d Frachtraum): %d Transporter zusätzlich würden die volle rohe Beute abtransportieren.\n",
        $transporter['cargo_capacity'], $transportersNeeded
    );
}
echo "Fazit: Kampfschiffe allein haben kaum Frachtraum - Plünderungen brauchen in der Praxis eine\n";
echo "Transporter-Eskorte, nicht nur eine siegfähige Kampfflotte. Das ist die eigentliche Erkenntnis,\n";
echo "nicht 'die Beute ist zu niedrig' (frühere Version dieses Scripts hatte den Frachtraum-Deckel\n";
echo "übersehen und dadurch die falsche Schlussfolgerung gezogen).\n";

echo "\n=== Jeder Schiffstyp einzeln vs. NPC-Außenposten (1 Schiffseinheit, dieselben echten Außenposten wie oben) ===\n";
$sampleStrengths = array_map(fn ($n) => (int) $n['defense_strength'], $sampleNpcs);
foreach ($shipTypes as $type) {
    if ($type['is_flagship']) {
        continue;
    }
    foreach ($sampleStrengths as $defenseStrength) {
        $defenderShips = $npcDefenseMethod->invoke(null, $defenseStrength);
        $combat = CombatResolver::resolve([['type' => $type, 'quantity' => 1]], $defenderShips, 0, 'weapons');
        printf(
            "%-14s (Lv.%d) vs defense=%-3d: %s nach %d Runde(n)\n",
            $type['name'], $type['min_shipyard_level'], $defenseStrength, $combat['outcome'], count($combat['rounds'])
        );
    }
}
