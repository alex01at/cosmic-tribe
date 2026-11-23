// App-Shell: Login, Status-Polling, View-Umschaltung, Render-Funktionen. Bewusst ohne Framework
// (Konzept-Vorgabe: statisches Vanilla-JS-Frontend).
const App = (() => {
    let state = null;
    let dispatchTarget = null; // {sectorId, x, y, position} - von der Karte gesetzt
    let dispatchMission = 'attack';
    let dispatchPreset = 'weapons';
    let dispatchShipQty = {}; // shipTypeId -> eingetragene Menge, übersteht renderDispatchForm()
    let pollTimer = null;
    let tickTimer = null;
    let mapInitialized = false;
    let isOffline = false;
    let pollIntervalMs = 25000;
    const POLL_INTERVAL_NORMAL = 25000;
    const POLL_INTERVAL_OFFLINE = 60000; // Backoff - kein Sinn, alle 25s gegen einen toten Server zu rennen

    function $(id) { return document.getElementById(id); }

    function errorToast(e, fallback) {
        if (e instanceof ApiError && e.isNetworkError) {
            showToast('Keine Verbindung zum Server - bitte später erneut versuchen.');
            return;
        }
        showToast((e && e.message) || fallback);
    }

    function showToast(message) {
        const el = $('toast');
        el.textContent = message;
        el.classList.add('visible');
        clearTimeout(showToast._t);
        showToast._t = setTimeout(() => el.classList.remove('visible'), 3200);
    }

    // ============================================================
    // Boot / Login
    // ============================================================
    async function boot() {
        if (Api.getToken()) {
            try {
                await refreshState();
                if (state) {
                    showGame();
                    startLoops();
                    return;
                }
                // refreshState() ist beim allerersten Aufruf ohne Netz gescheitert - es gibt noch
                // keinen gecachten state() zum Anzeigen, also zurück zum Login mit Hinweis statt
                // eines leeren Spielbildschirms.
                $('login-error').textContent = 'Server nicht erreichbar. Bitte erneut versuchen.';
            } catch (e) {
                // Token ungültig (401 hat clearSession() schon erledigt) - zurück zum Login,
                // nicht in einer Fehlerschleife hängen bleiben.
            }
        }
        showLogin();
    }

    function showLogin() {
        $('login-screen').classList.remove('hidden');
        $('game-screen').classList.add('hidden');
    }

    function showGame() {
        $('login-screen').classList.add('hidden');
        $('game-screen').classList.remove('hidden');
        $('game-screen').style.display = 'flex';
    }

    function initLoginForm() {
        $('login-submit').addEventListener('click', submitLogin);
        $('login-username').addEventListener('keydown', e => { if (e.key === 'Enter') submitLogin(); });
    }

    async function submitLogin() {
        const username = $('login-username').value.trim();
        $('login-error').textContent = '';
        if (username.length < 3) {
            $('login-error').textContent = 'Name muss mindestens 3 Zeichen haben.';
            return;
        }
        try {
            const res = await Api.post('/register', { deviceId: Api.getDeviceId(), username });
            Api.setToken(res.token);
            await refreshState();
            showGame();
            startLoops();
        } catch (e) {
            $('login-error').textContent = humanizeError(e);
        }
    }

    function humanizeError(e) {
        if (e.status === 409) return 'Name bereits vergeben oder Gerät bereits registriert.';
        if (e.status === 422) return e.message || 'Ungültige Eingabe.';
        return 'Verbindung zum Server fehlgeschlagen.';
    }

    // ============================================================
    // State laden / Loops
    // ============================================================
    async function refreshState() {
        try {
            state = await Api.get('/state');
            setOffline(false);
            renderAll();
        } catch (e) {
            if (e instanceof ApiError && e.isNetworkError) {
                // Server nicht erreichbar - letzten bekannten state() NICHT verwerfen, nur
                // Banner zeigen und Polling verlangsamen statt den Spieler alle 25s erneut
                // gegen einen toten Server laufen zu lassen.
                setOffline(true);
                return;
            }
            throw e;
        }
    }

    function setOffline(offline) {
        if (offline === isOffline) return;
        isOffline = offline;
        $('offline-bar').classList.toggle('visible', offline);
        pollIntervalMs = offline ? POLL_INTERVAL_OFFLINE : POLL_INTERVAL_NORMAL;
        if (pollTimer) restartPollTimer();
    }

    function restartPollTimer() {
        clearInterval(pollTimer);
        pollTimer = setInterval(() => refreshState().catch(() => {}), pollIntervalMs);
    }

    function startLoops() {
        if (pollTimer) clearInterval(pollTimer);
        if (tickTimer) clearInterval(tickTimer);

        // state() haelt global die GET_LOCK - nicht sekuendlich pollen, 25s reicht fuer einen
        // Aufbau-Titel, Countdown-Optik laeuft lokal ueber tick().
        restartPollTimer();
        tickTimer = setInterval(tick, 1000);
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') refreshState().catch(() => {});
        });
        window.addEventListener('online', () => refreshState().catch(() => {}));
        window.addEventListener('offline', () => setOffline(true));
    }

    function tick() {
        if (!state) return;
        // Lokaler Countdown ohne Refetch - sobald einer auf 0 faellt, einmal neu laden, damit der
        // abgeschlossene Bau/die Ankunft tatsaechlich verarbeitet wird.
        let anyExpired = false;
        const base = currentBase();
        if (base) {
            for (const b of base.buildings) {
                if (b.secondsRemaining !== null) {
                    b.secondsRemaining -= 1;
                    if (b.secondsRemaining <= 0) anyExpired = true;
                }
            }
            for (const q of base.shipQueue) {
                if (q.secondsRemainingForNextUnit !== null) {
                    q.secondsRemainingForNextUnit -= 1;
                    if (q.secondsRemainingForNextUnit <= 0) anyExpired = true;
                }
            }
        }
        for (const f of state.fleets) {
            const remaining = f.arrivesAtEpoch - Api.serverNowEpoch();
            if (remaining <= 0) anyExpired = true;
        }
        if (anyExpired) {
            refreshState().catch(() => {});
        } else {
            renderBase();
            renderFleets();
        }
    }

    function currentBase() {
        return state && state.bases[0];
    }

    // ============================================================
    // Render: gesamt
    // ============================================================
    function renderAll() {
        renderResourceBar();
        renderAlertBar();
        renderBase();
        renderFleets();
        renderReports();
    }

    function renderResourceBar() {
        const base = currentBase();
        if (!base) return;
        $('res-titan').textContent = Format.number(base.titan);
        $('res-deuterium').textContent = Format.number(base.deuterium);
        $('res-credits').textContent = Format.number(base.credits);
        $('res-titan-rate').textContent = `+${Format.number(base.titanPerHour)}/h`;
        $('res-deuterium-rate').textContent = `+${Format.number(base.deuteriumPerHour)}/h`;
    }

    function renderAlertBar() {
        const bar = $('alert-bar');
        const incoming = state.incomingHostileFleets || [];
        if (incoming.length === 0) {
            bar.classList.remove('visible');
            return;
        }
        const next = incoming.reduce((a, b) => (a.arrivesAtEpoch < b.arrivesAtEpoch ? a : b));
        const remaining = next.arrivesAtEpoch - Api.serverNowEpoch();
        bar.textContent = `⚠ Feindliche Flotte von ${next.attackerName} trifft in ${Format.duration(remaining)} ein!`;
        bar.classList.add('visible');
    }

    // ============================================================
    // Render: Basis / Gebäude
    // ============================================================
    function renderBase() {
        const base = currentBase();
        if (!base) return;
        $('base-name').textContent = base.name;
        renderResourceBar();

        $('base-buildings').innerHTML = base.buildings.map(b => buildingRowHtml(base, b)).join('');
        $('base-queue').innerHTML = base.buildQueue.length
            ? base.buildQueue.map(q => `
                <div class="queue-row">
                    <div class="row-main">
                        <div class="row-title">${q.buildingName} &rarr; Lv. ${q.targetLevel}</div>
                        <div class="row-sub">${Format.number(q.costTitanPaid)} Titan, ${Format.number(q.costDeuteriumPaid)} Deut. bezahlt</div>
                    </div>
                </div>`).join('') + `<button class="btn danger" id="cancel-build-btn" style="margin-top:8px;">Letzten Auftrag stornieren (Rückerstattung)</button>`
            : '<p class="row-sub">Keine wartenden Bauaufträge.</p>';

        const cancelBtn = $('cancel-build-btn');
        if (cancelBtn) cancelBtn.addEventListener('click', cancelBuild);

        document.querySelectorAll('[data-build-building]').forEach(btn => {
            btn.addEventListener('click', () => queueBuilding(parseInt(btn.dataset.buildBuilding, 10)));
        });
    }

    function buildingRowHtml(base, b) {
        if (b.status === 'building') {
            const total = b.secondsRemaining; // Annäherung für die Balkenbreite, reicht optisch
            return `
                <div class="building-row">
                    <div class="row-main">
                        <div class="row-title">${b.name} <span class="row-sub">Lv. ${b.level} &rarr; ${b.level + 1}</span></div>
                        <div class="row-sub">Fertig in ${Format.duration(b.secondsRemaining)}</div>
                        <div class="progress-bar"><div class="fill" style="width:40%"></div></div>
                    </div>
                </div>`;
        }

        if (!b.nextLevel) {
            return `
                <div class="building-row">
                    <div class="row-main"><div class="row-title">${b.name}</div><div class="row-sub">Lv. ${b.level} (Maximum erreicht)</div></div>
                </div>`;
        }

        const nl = b.nextLevel;
        const affordable = nl.costTitan <= base.titan && nl.costDeuterium <= base.deuterium && nl.costCredits <= base.credits;
        const disabled = nl.locked || !affordable;
        const reason = nl.locked ? 'Kommandobrücke zu niedrig' : (!affordable ? 'Nicht genug Rohstoffe' : '');

        return `
            <div class="building-row">
                <div class="row-main">
                    <div class="row-title">${b.name} <span class="row-sub">Lv. ${b.level} &rarr; ${nl.level}</span></div>
                    <div class="cost-line ${affordable ? '' : 'insufficient'}">
                        ${Format.number(nl.costTitan)} Titan · ${Format.number(nl.costDeuterium)} Deut.${nl.costCredits ? ' · ' + Format.number(nl.costCredits) + ' Cr.' : ''} · ${Format.duration(nl.buildSeconds)}
                    </div>
                    ${reason ? `<div class="cost-line insufficient">${reason}</div>` : ''}
                </div>
                <button class="btn ${disabled ? '' : 'primary'}" data-build-building="${b.id}" ${disabled ? 'disabled' : ''}>Ausbauen</button>
            </div>`;
    }

    async function queueBuilding(buildingTypeId) {
        const base = currentBase();
        try {
            await Api.post('/base/build', { baseId: base.id, buildingTypeId });
            await refreshState();
        } catch (e) {
            errorToast(e, 'Fehler beim Ausbauen');
        }
    }

    async function cancelBuild() {
        const base = currentBase();
        try {
            await Api.post('/base/cancel', { baseId: base.id });
            await refreshState();
        } catch (e) {
            errorToast(e, 'Fehler beim Stornieren');
        }
    }

    // ============================================================
    // Render: Werft / Hangar / Flotten
    // ============================================================
    function renderFleets() {
        const base = currentBase();
        if (!base) return;

        const shipyard = base.buildings.find(b => b.effect === 'shipyard');
        const shipyardLevel = shipyard ? shipyard.level : 0;

        $('shipyard-list').innerHTML = shipyardLevel < 1
            ? '<p class="row-sub">Werft muss zuerst gebaut werden (siehe Basis-Tab).</p>'
            : state.catalog.shipTypes.filter(s => !s.isFlagship).map(s => `
                <div class="ship-row">
                    <div class="row-main">
                        <div class="row-title">${s.name}</div>
                        <div class="row-sub">${s.description}</div>
                        <div class="cost-line">${Format.number(s.costTitan)} Titan · ${Format.number(s.costDeuterium)} Deut.${s.costCredits ? ' · ' + Format.number(s.costCredits) + ' Cr.' : ''} · ${Format.duration(s.buildSeconds)}/Stk.</div>
                    </div>
                    <button class="btn primary" data-build-ship="${s.id}">+1</button>
                </div>`).join('');

        document.querySelectorAll('[data-build-ship]').forEach(btn => {
            btn.addEventListener('click', () => queueShip(parseInt(btn.dataset.buildShip, 10)));
        });

        $('shipyard-queue').innerHTML = base.shipQueue.length
            ? base.shipQueue.map(q => `
                <div class="queue-row">
                    <div class="row-main">
                        <div class="row-title">${q.shipName} &times; ${q.quantity}</div>
                        <div class="row-sub">${q.active ? `Nächste Einheit in ${Format.duration(q.secondsRemainingForNextUnit)}` : 'Wartet'}</div>
                    </div>
                </div>`).join('') + `<button class="btn danger" id="cancel-ship-btn" style="margin-top:8px;">Letzten Auftrag stornieren</button>`
            : '';
        const cancelShipBtn = $('cancel-ship-btn');
        if (cancelShipBtn) cancelShipBtn.addEventListener('click', cancelShip);

        $('hangar-list').innerHTML = base.ships.length
            ? base.ships.map(s => `<div class="ship-row"><div class="row-main"><div class="row-title">${s.name}</div></div><div class="row-sub">${s.quantity} Stk.</div></div>`).join('')
            : '<p class="row-sub">Hangar ist leer.</p>';

        renderDispatchForm();

        $('fleets-active').innerHTML = state.fleets.length
            ? state.fleets.map(f => {
                const remaining = f.arrivesAtEpoch - Api.serverNowEpoch();
                return `
                <div class="queue-row">
                    <div class="row-main">
                        <div class="row-title">${missionLabel(f.mission)} ${f.status === 'returning' ? '(Rückflug)' : ''}</div>
                        <div class="row-sub">Ziel-Sektor ${f.targetSector.x}:${f.targetSector.y} · Ankunft in ${Format.duration(remaining)}</div>
                    </div>
                </div>`;
            }).join('')
            : '<p class="row-sub">Keine Flotten unterwegs.</p>';
    }

    function missionLabel(m) {
        return { attack: 'Angriff', plunder: 'Plünderung', station: 'Stationierung', transport: 'Transport', colonize: 'Kolonisierung' }[m] || m;
    }

    async function queueShip(shipTypeId) {
        const base = currentBase();
        try {
            await Api.post('/base/ships/build', { baseId: base.id, shipTypeId, quantity: 1 });
            await refreshState();
        } catch (e) {
            errorToast(e, 'Fehler beim Schiffsbau');
        }
    }

    async function cancelShip() {
        const base = currentBase();
        try {
            await Api.post('/base/ships/cancel', { baseId: base.id });
            await refreshState();
        } catch (e) {
            errorToast(e, 'Fehler beim Stornieren');
        }
    }

    // ============================================================
    // Flotte entsenden
    // ============================================================
    function renderDispatchForm() {
        const base = currentBase();
        const el = $('dispatch-form');

        if (base.ships.length === 0) {
            el.innerHTML = '<p class="row-sub">Keine Schiffe im Hangar.</p>';
            return;
        }

        const missions = ['attack', 'plunder', 'station', 'transport', 'colonize'];
        const presets = ['weapons', 'shields', 'speed'];
        const presetLabels = { weapons: 'Waffen', shields: 'Schilde', speed: 'Tempo' };

        el.innerHTML = `
            <div class="row-sub" style="margin-bottom:6px;">
                Ziel: ${dispatchTarget ? `Sektor ${dispatchTarget.x}:${dispatchTarget.y}, Slot ${dispatchTarget.position}` : 'Auf der Weltkarte antippen'}
            </div>
            <div style="display:flex; gap:6px; flex-wrap:wrap; margin-bottom:8px;">
                ${missions.map(m => `<button class="btn ${dispatchMission === m ? 'primary' : ''}" data-mission="${m}">${missionLabel(m)}</button>`).join('')}
            </div>
            <div style="display:flex; gap:6px; margin-bottom:8px;">
                ${presets.map(p => `<button class="btn ${dispatchPreset === p ? 'primary' : ''}" data-preset="${p}">${presetLabels[p]}</button>`).join('')}
            </div>
            <div id="dispatch-ships">
                ${base.ships.map(s => `
                    <div class="ship-row">
                        <div class="row-main row-title">${s.name} (${s.quantity} verfügbar)</div>
                        <input type="number" min="0" max="${s.quantity}" value="${dispatchShipQty[s.id] || 0}" data-ship-qty="${s.id}" style="width:64px; background:var(--bg-panel-raised); color:var(--text); border:1px solid var(--border); border-radius:6px; padding:4px;">
                    </div>`).join('')}
            </div>
            <button class="btn primary" id="dispatch-submit" style="width:100%; margin-top:8px;">Flotte losschicken</button>
        `;

        document.querySelectorAll('[data-mission]').forEach(btn => {
            btn.addEventListener('click', () => { dispatchMission = btn.dataset.mission; renderDispatchForm(); });
        });
        document.querySelectorAll('[data-preset]').forEach(btn => {
            btn.addEventListener('click', () => { dispatchPreset = btn.dataset.preset; renderDispatchForm(); });
        });
        document.querySelectorAll('[data-ship-qty]').forEach(input => {
            input.addEventListener('input', () => {
                dispatchShipQty[input.dataset.shipQty] = parseInt(input.value, 10) || 0;
            });
        });
        $('dispatch-submit').addEventListener('click', submitDispatch);
    }

    async function submitDispatch() {
        if (!dispatchTarget) {
            showToast('Bitte zuerst ein Ziel auf der Weltkarte antippen.');
            return;
        }
        const ships = {};
        document.querySelectorAll('[data-ship-qty]').forEach(input => {
            const qty = parseInt(input.value, 10) || 0;
            if (qty > 0) ships[input.dataset.shipQty] = qty;
        });
        if (Object.keys(ships).length === 0) {
            showToast('Bitte mindestens ein Schiff auswählen.');
            return;
        }

        try {
            const base = currentBase();
            const res = await Api.post('/fleet/dispatch', {
                baseId: base.id,
                ships,
                targetSectorId: dispatchTarget.sectorId,
                targetPosition: dispatchTarget.position,
                mission: dispatchMission,
                energyPreset: dispatchPreset,
            });
            showToast(`Flotte unterwegs, Ankunft in ${Format.duration(res.arrivesInSeconds)}.`);
            dispatchShipQty = {};
            dispatchTarget = null;
            await refreshState();
        } catch (e) {
            errorToast(e, 'Flottenversand fehlgeschlagen');
        }
    }

    // ============================================================
    // Berichte
    // ============================================================
    function renderReports() {
        const base = currentBase();
        $('reports-list').innerHTML = state.combatReports.length
            ? state.combatReports.map(r => {
                const isAttacker = r.attackerPlayerId === state.player.id;
                const ago = Api.serverNowEpoch() - r.resolvedAtEpoch;
                return `
                <div class="queue-row">
                    <div class="row-main">
                        <div class="row-title outcome-${r.outcome}">${isAttacker ? 'Angriff gesendet' : 'Basis angegriffen'} - ${Format.outcomeLabel(r.outcome)}</div>
                        <div class="row-sub">Beute: ${Format.number(r.lootTitan)} Titan, ${Format.number(r.lootDeuterium)} Deut., ${Format.number(r.lootCredits)} Cr. · vor ${Format.duration(ago)}</div>
                    </div>
                </div>`;
            }).join('')
            : '<p class="row-sub">Noch keine Kampfberichte.</p>';
    }

    // ============================================================
    // Navigation
    // ============================================================
    function initNav() {
        document.querySelectorAll('.nav-btn[data-view]').forEach(btn => {
            btn.addEventListener('click', () => switchView(btn.dataset.view));
        });
    }

    function switchView(view) {
        document.querySelectorAll('.view').forEach(v => v.classList.remove('active'));
        document.querySelectorAll('.nav-btn').forEach(b => b.classList.remove('active'));
        $('view-' + view).classList.add('active');
        document.querySelector(`.nav-btn[data-view="${view}"]`).classList.add('active');
        if (view === 'map') {
            MapView.resize();
            if (!mapInitialized) {
                mapInitialized = true;
                const base = currentBase();
                if (base) MapView.centerOn(base.sectorX, base.sectorY);
            }
        }
    }

    // ============================================================
    // Karte: Slot-Auswahl -> Ziel für Flottenversand
    // ============================================================
    function initMap() {
        MapView.init($('map-canvas'), $('map-canvas-wrap'), (x, y, sector) => {
            if (!sector) {
                $('map-slot-panel').classList.remove('visible');
                return;
            }
            const panel = $('map-slot-panel');
            panel.classList.add('visible');
            panel.innerHTML = `
                <h2>Sektor ${x}:${y}</h2>
                ${sector.slots.length === 0 ? '<p class="row-sub">Leer (kolonisierbar).</p>' : ''}
                ${sector.slots.map(slot => `
                    <div class="queue-row">
                        <div class="row-main">
                            <div class="row-title">${slotLabel(slot)}</div>
                        </div>
                        <button class="btn primary" data-pick-slot="${slot.position}">Ziel wählen</button>
                    </div>`).join('')}
                ${sector.slots.length === 0 ? `<button class="btn primary" data-pick-slot="0">Slot 0 als Ziel (Kolonisierung)</button>` : ''}
            `;
            panel.querySelectorAll('[data-pick-slot]').forEach(btn => {
                btn.addEventListener('click', () => {
                    dispatchTarget = { sectorId: sector.id, x, y, position: parseInt(btn.dataset.pickSlot, 10) };
                    showToast(`Ziel gesetzt: Sektor ${x}:${y}, Slot ${dispatchTarget.position}`);
                    switchView('fleets');
                });
            });
        });
    }

    function slotLabel(slot) {
        if (slot.type === 'base') return `${slot.name} (${slot.owner})${slot.protected ? ' · geschützt' : ''}`;
        if (slot.type === 'npc_outpost') return `NPC-Außenposten: ${slot.name}`;
        if (slot.type === 'asteroid_field') return `Asteroidenfeld (+${slot.bonusPercent}%)`;
        if (slot.type === 'nebula') return 'Nebel';
        return slot.type;
    }

    function init() {
        initLoginForm();
        initNav();
        initMap();
        boot();
    }

    return { init };
})();

document.addEventListener('DOMContentLoaded', App.init);
