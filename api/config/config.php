<?php

return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3319,
        'name' => 'starforge',
        'user' => 'starforge',
        'pass' => 'sf_local_dev',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'env' => 'local',
        'token_secret' => 'local-dev-secret',
        // Skaliert Bauzeiten/Produktions-Ticks/Flugzeiten (höher = schneller). Mit den aktuellen
        // Platzhalter-Zahlen dauern Level/Flüge sonst Stunden - für echtes Playtesting lokal
        // hochdrehen (siehe "langweilig"-Feedback zu Vector Trek, derselbe Fehler wie dort: nie
        // selbst gespielt). In einer späteren Produktiv-Config auf 1 setzen.
        'game_speed' => 20,
    ],
];
