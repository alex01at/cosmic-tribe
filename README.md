# Cosmic Tribe

Asynchrones Multiplayer-Weltraum-Strategiespiel (Basenbau, Flotten, Kämpfe) im Browser, später
auch als Android-App via Capacitor. Konzeptionell an "Lords & Knights" angelehnt, aber als
eigenständiges Sci-Fi-Universum umgesetzt.

## Architektur

- **`api/`** – PHP 8.3+ Backend, zustandslos, Bearer-Token-Auth. Keine Dauer-Loop/Daemon: alle
  Zeit-abhängigen Effekte (Produktion, Bauqueues, Flugzeiten) werden bei jedem Request faul
  nachgerechnet (`GameEngine::advanceBaseTo()`), funktioniert also auch, wenn kein Spieler online
  war.
- **`www/`** – statisches Vanilla-JS-Frontend (bewusst ohne Framework), mobile-first mit
  Safe-Area-Unterstützung. Spricht die API ausschließlich über `www/js/api.js` an.
- MariaDB als Datenbank, `GET_LOCK`/`RELEASE_LOCK` für die Flotten-Ankunfts-Auflösung bei
  gleichzeitigen Requests.

### Kernmechaniken

- Galaxie-Raster aus Sektoren, je Sektor bis zu 8 Slots (eigene/fremde Basen, NPC-Außenposten,
  Asteroidenfelder, Nebel).
- Basen mit Rohstoffen (Titan, Deuterium, Credits), Gebäuden mit Bau-Warteschlange, Werft mit
  Schiffsbau-Warteschlange.
- Asynchroner Flottenversand (Angriff, Plünderung, Stationierung, Transport, Kolonisierung) mit
  deterministischer Kampfauflösung bei Ankunft.
- Die Weltkarte zeigt nie fremde Rohstoffe, Schiffe oder Flottenzusammensetzung – nur
  Basisname/Besitzer/Schutzstatus, bei anfliegenden feindlichen Flotten nur Angreifername und
  Ankunftszeit.
- Allianzen sind für eine spätere Phase vorgesehen, aktuell nicht implementiert.

## Lokale Entwicklungsumgebung

Voraussetzungen: PHP 8.3+, MariaDB, Python 3 (nur für den statischen Dev-Webserver).

```bash
# Datenbank anlegen (Name/User/Passwort siehe api/config/config.php)
# Migrationen einspielen
php api/bin/migrate.php

# Start-Galaxie + Testspieler erzeugen (nur gegen eine leere DB, nicht idempotent)
php api/bin/seed.php

# API-Dev-Server starten (mehrere Worker nötig, sonst serialisiert php -S alle Requests
# und Race-Condition-Tests bestehen fälschlich immer)
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8099 -t api/public

# Frontend separat ausliefern (eigener Port, damit CORS auch lokal wirklich greift)
cd www && python3 -m http.server 8089
```

Danach `www/config.js` auf die tatsächliche API-URL zeigen lassen und
`http://127.0.0.1:8089/index.html` im Browser öffnen.

In `api/config/config.php` steht `game_speed` (aktuell `20`) – skaliert Bauzeiten, Produktion und
Flugzeiten, damit sich das Spiel lokal in Minuten statt Stunden testen lässt. Für einen
Produktivbetrieb auf `1` setzen.

### Tests

```bash
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8199 -t api/public &
php api/bin/tests.php
```

Regressionstest gegen einen laufenden Dev-Server (Port `8199`), prüft u. a. Auth, Bau- und
Schiffs-Warteschlangen, Flottenversand und Kampfauflösung.

## Status

- Phase 1: Datenbankschema, Katalogdaten, Start-Galaxie-Seed – fertig
- Phase 2: Auth, GameEngine, Kampfauflösung, Router mit CORS – fertig
- Phase 2b: Werft-Warteschlange, Kartenabfrage, Zeitstempel-Fixes – fertig
- Phase 3: Frontend (Basis, Werft/Flotten, Weltkarte, Berichte) – fertig
- Phase 4 (Capacitor/Android, Allianzen, Balancing): offen
