<?php

declare(strict_types=1);

/**
 * Deterministische Kampfauflösung (KEIN random_int - laut Vorgabe deterministisch). Schild wird
 * pro Runde um GENAU das absorbiert, was er abfängt (nicht pauschal um den vollen gegnerischen
 * Angriffswert) - ein Schildgenerator blockt also nicht für immer dieselbe Menge, sondern leert
 * sich (Advisor-Review-Fund: die erste Fassung hätte Basen mit genug Schild-Level unverwundbar
 * gemacht). Verluste werden am Ende GLEICHMÄSSIG (proportional zum Hüllenschaden) auf alle
 * Schiffstypen der jeweiligen Seite verteilt - keine Haltbarkeit pro Schiffstyp in Phase 2,
 * bewusste Vereinfachung.
 */
final class CombatResolver
{
    private const MAX_ROUNDS = 6;

    /**
     * @param array<int, array{type: array, quantity: int}> $attackerShips
     * @param array<int, array{type: array, quantity: int}> $defenderShips
     */
    public static function resolve(
        array $attackerShips,
        array $defenderShips,
        int $defenderShieldBonus,
        string $attackerEnergyPreset
    ): array {
        $attacker = self::aggregate($attackerShips, $attackerEnergyPreset);
        // Verteidiger kämpft immer im "weapons"-Profil - keine Energie-Presets für Verteidigung
        // in Phase 2 (es gibt keinen Spieler-Input dafür, die Basis verteidigt sich "wie sie ist").
        $defender = self::aggregate($defenderShips, 'weapons');
        $defender['shield'] += $defenderShieldBonus;

        $rounds = [];
        for ($round = 1; $round <= self::MAX_ROUNDS; $round++) {
            if ($attacker['hull'] <= 0 || $defender['hull'] <= 0) {
                break;
            }

            $absorbedByDefender = min($defender['shield'], $attacker['attack']);
            $dmgToDefenderHull = $attacker['attack'] - $absorbedByDefender;
            $defender['shield'] -= $absorbedByDefender;

            $absorbedByAttacker = min($attacker['shield'], $defender['attack']);
            $dmgToAttackerHull = $defender['attack'] - $absorbedByAttacker;
            $attacker['shield'] -= $absorbedByAttacker;

            $defender['hull'] -= $dmgToDefenderHull;
            $attacker['hull'] -= $dmgToAttackerHull;

            $rounds[] = [
                'round' => $round,
                'damageToDefenderHull' => $dmgToDefenderHull,
                'damageToAttackerHull' => $dmgToAttackerHull,
                'defenderShieldRemaining' => max(0, $defender['shield']),
                'attackerShieldRemaining' => max(0, $attacker['shield']),
                'defenderHullRemaining' => max(0, $defender['hull']),
                'attackerHullRemaining' => max(0, $attacker['hull']),
            ];
        }

        if ($attacker['hull'] > 0 && $defender['hull'] <= 0) {
            $outcome = 'attacker_win';
        } elseif ($defender['hull'] > 0 && $attacker['hull'] <= 0) {
            $outcome = 'defender_win';
        } else {
            // Beide noch am Leben nach MAX_ROUNDS ODER beide gleichzeitig vernichtet - als
            // Unentschieden gewertet. Ein Unentschieden zählt NICHT als Sieg: der Angreifer
            // erbeutet nichts, siehe GameEngine::resolveFleetArrival().
            $outcome = 'draw';
        }

        $attackerLossRatio = $attacker['startHull'] > 0 ? 1 - max(0, $attacker['hull']) / $attacker['startHull'] : 0.0;
        $defenderLossRatio = $defender['startHull'] > 0 ? 1 - max(0, $defender['hull']) / $defender['startHull'] : 0.0;

        return [
            'outcome' => $outcome,
            'rounds' => $rounds,
            'attackerLossRatio' => min(1.0, max(0.0, $attackerLossRatio)),
            'defenderLossRatio' => min(1.0, max(0.0, $defenderLossRatio)),
        ];
    }

    /** NPC-Außenposten haben keine Schiffstypen, nur eine einzelne defense_strength-Zahl - wird
     *  hier in ein synthetisches "Verteidiger-Schiff" aufgeteilt (Platzhalter-Verhältnis, noch
     *  nicht balanciert). */
    public static function npcDefenseAsShip(int $defenseStrength): array
    {
        return [[
            'type' => [
                'attack' => (int) round($defenseStrength * 0.2),
                'shield' => (int) round($defenseStrength * 0.3),
                'hull' => $defenseStrength,
            ],
            'quantity' => 1,
        ]];
    }

    private static function aggregate(array $ships, string $energyPreset): array
    {
        $attack = 0;
        $shield = 0;
        $hull = 0;
        foreach ($ships as $s) {
            $attack += $s['type']['attack'] * $s['quantity'];
            $shield += $s['type']['shield'] * $s['quantity'];
            $hull += $s['type']['hull'] * $s['quantity'];
        }
        [$attack, $shield] = self::applyPreset($attack, $shield, $energyPreset);

        return ['attack' => $attack, 'shield' => $shield, 'hull' => $hull, 'startHull' => $hull];
    }

    private static function applyPreset(int $attack, int $shield, string $preset): array
    {
        return match ($preset) {
            'shields' => [(int) round($attack * 0.8), (int) round($shield * 1.3)],
            'weapons' => [(int) round($attack * 1.2), (int) round($shield * 0.8)],
            'speed' => [(int) round($attack * 0.9), (int) round($shield * 0.9)], // Tempo wirkt auf Flugzeit, nicht auf den Kampf selbst
            default => [$attack, $shield],
        };
    }
}
