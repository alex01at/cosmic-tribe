# Android-Build (Capacitor) - Phase 4

**Status: UNVERIFIZIERT.** In der Entwicklungsumgebung, in der dieses Setup erstellt wurde, fehlen
Node/npm, ein JDK und das Android SDK komplett - die folgenden Schritte sind nach der Capacitor-
Dokumentation geschrieben, aber nie tatsächlich ausgeführt. Vor dem ersten echten Build einplanen,
dass hier etwas nicht auf Anhieb klappt.

## appId ist (praktisch) permanent

`capacitor.config.json` setzt `appId: "com.cosmictribe.app"`. Sobald eine App unter dieser ID
einmal im Play Store veröffentlicht wurde, lässt sie sich nicht mehr ändern, ohne die App-Store-
Präsenz (Bewertungen, Installationen) zu verlieren. "Cosmic Tribe"/"cosmictribe" ist der aktuelle
Repo-Name, aber noch kein final entschiedener Produktname (siehe Projekt-Notizen) - **vor dem
ersten Play-Store-Upload nochmal bewusst bestätigen, nicht einfach durchlaufen lassen.**

## Einrichtung (einmalig)

```bash
npm install @capacitor/core@latest @capacitor/android@latest
npm install --save-dev @capacitor/cli@latest
npx cap add android
npx cap sync android
```

`package.json` listet keine feste Capacitor-Version - absichtlich, da hier keine Installation
möglich war, um eine konkrete Version gegen die aktuellen Play-Store-Anforderungen zu prüfen
(Googles Mindest-`targetSdk` wird jährlich angehoben). **Vor dem ersten Build: `targetSdk` in
`android/app/build.gradle` gegen die aktuellen Play-Console-Anforderungen prüfen.**

`npx cap add android` legt ein `android/`-Verzeichnis mit einem vollständigen Gradle-Projekt an -
das NICHT in `.gitignore` ausschließen, es enthält projektspezifische Anpassungen (Icons,
Berechtigungen), kein reiner Build-Output.

## Mixed Content / Cleartext: der wahrscheinlichste erste Stolperstein

`androidScheme: "https"` in `capacitor.config.json` lässt die WebView die App selbst über
`https://localhost` laden (nötig für `viewport-fit=cover` & moderne Web-APIs). Das Problem: die
echte API läuft lokal nur über **`http://`** (siehe `api/config/config.php`). Das sind ZWEI
getrennte Android-Mechanismen, die beide zuschlagen können - nicht denselben Fix verwechseln:

1. **Cleartext-Traffic-Policy** (ab API 28): blockt `http://`-Netzwerk-Requests der APP generell.
2. **WebView-Mixed-Content-Blocking**: blockt zusätzlich, unabhängig davon, dass eine über
   `https://` geladene SEITE `http://`-Ressourcen nachlädt - das ist genau der Fall hier
   (`https://localhost`-WebView ruft eine `http://`-API). Cleartext-Erlaubnis allein reicht
   NICHT, wenn dieser zweite Mechanismus noch blockt.

- **Für den Dev-Build auf dem eigenen Testgerät, zwei Optionen:**
  - (a) `android:usesCleartextTraffic="true"` in `android/app/src/main/AndroidManifest.xml`
    UND Mixed-Content erlauben (Capacitor-Doku zu `WebViewAssetLoader`/`allowMixedContent`
    prüfen - je Capacitor-Version unterschiedlich benannt/konfiguriert).
  - (b) Einfacher für reines Dev-Testing: `androidScheme: "http"` in `capacitor.config.json`
    setzen (keine Mixed-Content-Situation mehr, da beide Seiten `http` sind) - dann aber separat
    prüfen, ob `viewport-fit=cover`/Safe-Area-Insets unter `http`-Scheme noch zuverlässig
    funktionieren (in dieser Umgebung nicht testbar).
  - In beiden Fällen: `www/config.js` auf die LAN-IP des Dev-Rechners zeigen lassen (nicht
    `127.0.0.1` - das wäre im Telefon das Telefon selbst, nicht der Dev-Rechner).
- **Für einen echten Produktiv-Build:** die API muss über eine echte `https://`-Domain laufen
  (Let's Encrypt o.ä.). Dann `androidScheme: "https"` behalten, `usesCleartextTraffic` NICHT
  setzen (Sicherheitsrisiko) und `www/config.js` auf die `https://`-API-URL zeigen lassen.

## Build

```bash
npx cap open android   # öffnet Android Studio, dort Build > Build APK
# oder headless:
cd android && ./gradlew assembleDebug
```

Die fertige APK liegt danach unter `android/app/build/outputs/apk/debug/app-debug.apk`.

## Nach jeder Änderung an www/

```bash
npx cap sync android
```

kopiert `www/` erneut in das Android-Projekt. Ohne diesen Schritt baut Android Studio die ALTE
Version der Web-App.

## Noch offen / nicht Teil dieses Setups

- App-Icons/Splash-Screen (Capacitor-Assets-Pipeline) - noch Platzhalter von `cap add android`.
- Offline-Fehlerbehandlung im Frontend ist vorhanden (`www/js/app.js`, Phase 4), aber nie in einer
  echten WebView getestet, nur im Browser.
- Kein CI/Signing-Setup für einen Play-Store-Release-Build.
