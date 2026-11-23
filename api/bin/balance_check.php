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

echo "\n=== NPC-Außenposten: Beute vs. Flottenkosten (via CombatResolver::resolve(), kein Zufall) ===\n";
echo "(NPC-Beute ist NICHT durch PLUNDER_MAX_PERCENT gedeckelt - das gilt nur für Spielerbasen.)\n";

$interceptor = array_values(array_filter($shipTypes, fn ($s) => $s['name'] === 'Abfangjäger'))[0];
$npcDefenseMethod = new ReflectionMethod(CombatResolver::class, 'npcDefenseAsShip');
$npcDefenseMethod->setAccessible(true);

foreach ([50, 150, 250, 400] as $defenseStrength) {
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
    $fleetCostTitan = $qty * (int) $interceptor['cost_titan'];
    $fleetCostDeuterium = $qty * (int) $interceptor['cost_deuterium'];
    $avgLootFactor = 3.5; // Mittelwert aus seed.php: loot = strength * random_int(2,5)
    $avgLootTitan = (int) round($defenseStrength * $avgLootFactor);
    printf(
        "defense_strength=%-3d: %3d Abfangjäger nötig (%5d Titan, %5d Deuterium Flottenwert) vs. ~%4d Titan erwartbare Beute -> %s\n",
        $defenseStrength, $qty, $fleetCostTitan, $fleetCostDeuterium, $avgLootTitan,
        $avgLootTitan >= $fleetCostTitan ? 'Beute deckt Flottenkosten' : 'Beute deckt Flottenkosten NICHT (Verlustwaffe ohne Rückflug-Überlebende)'
    );
}
echo "Hinweis: die Flotte selbst geht beim Sieg nicht verloren (nur anteiliger Hüllenschaden),\n";
echo "der Vergleich ist daher konservativ - echte Kosten sind nur der anteilige Verlust, nicht die volle Flotte.\n";

echo "\n=== Jeder Schiffstyp einzeln vs. NPC-Außenposten (1 Schiffseinheit) ===\n";
foreach ($shipTypes as $type) {
    if ($type['is_flagship']) {
        continue;
    }
    foreach ([50, 150, 250, 400] as $defenseStrength) {
        $defenderShips = $npcDefenseMethod->invoke(null, $defenseStrength);
        $combat = CombatResolver::resolve([['type' => $type, 'quantity' => 1]], $defenderShips, 0, 'weapons');
        printf(
            "%-14s (Lv.%d) vs defense=%-3d: %s nach %d Runde(n)\n",
            $type['name'], $type['min_shipyard_level'], $defenseStrength, $combat['outcome'], count($combat['rounds'])
        );
    }
}
