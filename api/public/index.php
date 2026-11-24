<?php

declare(strict_types=1);

// CORS MUSS als Allererstes passieren, noch vor Auth/Routing - sonst bekommen Fehlerantworten
// (401/404/422) keine CORS-Header und der Browser zeigt nur einen nichtssagenden
// "Network Error" statt der echten Fehlermeldung (Advisor-Review-Fund). Erlaubt sowohl den
// Browser (eigener Origin) als auch die Android-WebView (capacitor://localhost / https://localhost).
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/Response.php';
require __DIR__ . '/../src/Router.php';
require __DIR__ . '/../src/Auth.php';
require __DIR__ . '/../src/AdminAuth.php';
require __DIR__ . '/../src/AdminEngine.php';
require __DIR__ . '/../src/CombatResolver.php';
require __DIR__ . '/../src/GameEngine.php';
require __DIR__ . '/../src/GalaxyGenerator.php';

$pdo = Database::connection();
$config = require __DIR__ . '/../config/config.php';

$adminEngine = new AdminEngine($pdo, $config);

$router = new Router();

// Für Spieler-Routen: Spieler auflösen UND passend dazu eine GameEngine mit der game_speed
// SEINES Universums bauen - jedes Universum kann eine eigene Geschwindigkeit haben.
$requirePlayerEngine = function (PDO $pdo, AdminEngine $adminEngine): array {
    $player = Auth::requirePlayer($pdo);
    $settings = $adminEngine->getUniverseSettings((int) $player['universe_id']);

    return [$player, new GameEngine($pdo, $settings['gameSpeed'], $settings['parallelBuildRate'])];
};

$router->post('/api/register', function (array $body) use ($pdo) {
    $deviceId = (string) ($body['deviceId'] ?? '');
    $username = (string) ($body['username'] ?? '');
    $universeId = (int) ($body['universeId'] ?? 0);
    Response::json(Auth::register($pdo, $deviceId, $username, $universeId));
});

$router->get('/api/universes', function () use ($pdo) {
    Response::json(Auth::listOpenUniverses($pdo));
});

$router->get('/api/state', function () use ($pdo, $adminEngine, $requirePlayerEngine) {
    [$player, $engine] = $requirePlayerEngine($pdo, $adminEngine);
    Response::json($engine->state($player));
});

$router->post('/api/base/build', function (array $body) use ($pdo, $adminEngine, $requirePlayerEngine) {
    [$player, $engine] = $requirePlayerEngine($pdo, $adminEngine);
    $baseId = (int) ($body['baseId'] ?? 0);
    $buildingTypeId = (int) ($body['buildingTypeId'] ?? 0);
    $payForParallel = (bool) ($body['payForParallel'] ?? false);
    Response::json($engine->queueBuilding((int) $player['id'], $baseId, $buildingTypeId, $payForParallel));
});

$router->post('/api/base/cancel', function (array $body) use ($pdo, $adminEngine, $requirePlayerEngine) {
    [$player, $engine] = $requirePlayerEngine($pdo, $adminEngine);
    $baseId = (int) ($body['baseId'] ?? 0);
    Response::json($engine->cancelQueuedBuilding((int) $player['id'], $baseId));
});

$router->post('/api/base/ships/build', function (array $body) use ($pdo, $adminEngine, $requirePlayerEngine) {
    [$player, $engine] = $requirePlayerEngine($pdo, $adminEngine);
    $baseId = (int) ($body['baseId'] ?? 0);
    $shipTypeId = (int) ($body['shipTypeId'] ?? 0);
    $quantity = (int) ($body['quantity'] ?? 0);
    $payForParallel = (bool) ($body['payForParallel'] ?? false);
    Response::json($engine->queueShip((int) $player['id'], $baseId, $shipTypeId, $quantity, $payForParallel));
});

$router->post('/api/base/ships/cancel', function (array $body) use ($pdo, $adminEngine, $requirePlayerEngine) {
    [$player, $engine] = $requirePlayerEngine($pdo, $adminEngine);
    $baseId = (int) ($body['baseId'] ?? 0);
    Response::json($engine->cancelQueuedShip((int) $player['id'], $baseId));
});

$router->get('/api/map', function () use ($pdo, $adminEngine, $requirePlayerEngine) {
    [$player, $engine] = $requirePlayerEngine($pdo, $adminEngine);
    $cx = (int) ($_GET['cx'] ?? 0);
    $cy = (int) ($_GET['cy'] ?? 0);
    $radius = (int) ($_GET['r'] ?? 5);
    Response::json($engine->mapWindow((int) $player['universe_id'], $cx, $cy, $radius));
});

$router->post('/api/fleet/dispatch', function (array $body) use ($pdo, $adminEngine, $requirePlayerEngine) {
    [$player, $engine] = $requirePlayerEngine($pdo, $adminEngine);
    $baseId = (int) ($body['baseId'] ?? 0);
    $shipComposition = [];
    foreach ((array) ($body['ships'] ?? []) as $shipTypeId => $quantity) {
        $shipComposition[(int) $shipTypeId] = (int) $quantity;
    }
    $targetSectorId = (int) ($body['targetSectorId'] ?? 0);
    $targetPosition = (int) ($body['targetPosition'] ?? 0);
    $mission = (string) ($body['mission'] ?? '');
    $energyPreset = (string) ($body['energyPreset'] ?? 'weapons');

    Response::json($engine->dispatchFleet(
        (int) $player['id'], $baseId, $shipComposition, $targetSectorId, $targetPosition, $mission, $energyPreset
    ));
});

$router->get('/api/alliances', function () use ($pdo, $adminEngine, $requirePlayerEngine) {
    [$player, $engine] = $requirePlayerEngine($pdo, $adminEngine);
    Response::json($engine->listAlliances((int) $player['universe_id']));
});

$router->post('/api/alliance/create', function (array $body) use ($pdo, $adminEngine, $requirePlayerEngine) {
    [$player, $engine] = $requirePlayerEngine($pdo, $adminEngine);
    $name = (string) ($body['name'] ?? '');
    $tag = (string) ($body['tag'] ?? '');
    Response::json($engine->createAlliance((int) $player['id'], (int) $player['universe_id'], $name, $tag));
});

$router->post('/api/alliance/join', function (array $body) use ($pdo, $adminEngine, $requirePlayerEngine) {
    [$player, $engine] = $requirePlayerEngine($pdo, $adminEngine);
    $allianceId = (int) ($body['allianceId'] ?? 0);
    Response::json($engine->joinAlliance((int) $player['id'], (int) $player['universe_id'], $allianceId));
});

$router->post('/api/alliance/leave', function () use ($pdo, $adminEngine, $requirePlayerEngine) {
    [$player, $engine] = $requirePlayerEngine($pdo, $adminEngine);
    Response::json($engine->leaveAlliance((int) $player['id']));
});

$router->post('/api/alliance/kick', function (array $body) use ($pdo, $adminEngine, $requirePlayerEngine) {
    [$player, $engine] = $requirePlayerEngine($pdo, $adminEngine);
    $targetPlayerId = (int) ($body['playerId'] ?? 0);
    Response::json($engine->kickAllianceMember((int) $player['id'], $targetPlayerId));
});

// ============================================================
// Admin (Betreiber-Panel) - komplett getrenntes Auth, siehe AdminAuth.php
// ============================================================

$router->post('/api/admin/login', function (array $body) use ($pdo) {
    $email = (string) ($body['email'] ?? '');
    $password = (string) ($body['password'] ?? '');
    Response::json(AdminAuth::login($pdo, $email, $password));
});

$router->post('/api/admin/logout', function () use ($pdo) {
    AdminAuth::logout($pdo);
    Response::json(['ok' => true]);
});

$router->get('/api/admin/universes', function () use ($pdo, $adminEngine) {
    AdminAuth::requireAdmin($pdo);
    Response::json(['universes' => $adminEngine->listUniverses()]);
});

$router->post('/api/admin/universes/create', function (array $body) use ($pdo, $adminEngine) {
    AdminAuth::requireAdmin($pdo);
    Response::json(['universes' => $adminEngine->createUniverse(
        (string) ($body['name'] ?? ''),
        (int) ($body['maxPlayers'] ?? 0),
        (int) ($body['galaxySize'] ?? 0),
        (float) ($body['gameSpeed'] ?? 0),
        (float) ($body['parallelBuildRate'] ?? 0)
    )]);
});

$router->post('/api/admin/universes/update', function (array $body) use ($pdo, $adminEngine) {
    AdminAuth::requireAdmin($pdo);
    $id = (int) ($body['id'] ?? 0);
    Response::json(['universes' => $adminEngine->updateUniverse($id, $body)]);
});

$router->get('/api/admin/catalog', function () use ($pdo, $adminEngine) {
    AdminAuth::requireAdmin($pdo);
    Response::json($adminEngine->getCatalog());
});

$router->post('/api/admin/catalog/building-level', function (array $body) use ($pdo, $adminEngine) {
    AdminAuth::requireAdmin($pdo);
    $buildingTypeId = (int) ($body['buildingTypeId'] ?? 0);
    $level = (int) ($body['level'] ?? 0);
    Response::json($adminEngine->updateBuildingLevel($buildingTypeId, $level, $body));
});

$router->post('/api/admin/catalog/ship-type', function (array $body) use ($pdo, $adminEngine) {
    AdminAuth::requireAdmin($pdo);
    $shipTypeId = (int) ($body['id'] ?? 0);
    Response::json($adminEngine->updateShipType($shipTypeId, $body));
});

$router->get('/api/admin/players', function () use ($pdo, $adminEngine) {
    AdminAuth::requireAdmin($pdo);
    $universeId = isset($_GET['universeId']) && $_GET['universeId'] !== '' ? (int) $_GET['universeId'] : null;
    Response::json(['players' => $adminEngine->listPlayers($universeId)]);
});

$router->post('/api/admin/players/reissue-token', function (array $body) use ($pdo, $adminEngine) {
    AdminAuth::requireAdmin($pdo);
    $playerId = (int) ($body['playerId'] ?? 0);
    Response::json(['token' => $adminEngine->reissueToken($playerId)]);
});

$router->post('/api/admin/players/delete', function (array $body) use ($pdo, $adminEngine) {
    AdminAuth::requireAdmin($pdo);
    $playerId = (int) ($body['playerId'] ?? 0);
    $adminEngine->deletePlayer($playerId);
    Response::json(['ok' => true]);
});

$router->get('/api/admin/players/bases', function () use ($pdo, $adminEngine) {
    AdminAuth::requireAdmin($pdo);
    $playerId = (int) ($_GET['playerId'] ?? 0);
    Response::json(['bases' => $adminEngine->getPlayerBases($playerId)]);
});

$router->post('/api/admin/bases/update', function (array $body) use ($pdo, $adminEngine) {
    AdminAuth::requireAdmin($pdo);
    $baseId = (int) ($body['baseId'] ?? 0);
    Response::json(['bases' => $adminEngine->updateBaseResources(
        $baseId, (int) ($body['titan'] ?? 0), (int) ($body['deuterium'] ?? 0), (int) ($body['credits'] ?? 0)
    )]);
});

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$router->dispatch($_SERVER['REQUEST_METHOD'], $path);
