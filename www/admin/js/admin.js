// Betreiber-Panel: eigenes, komplett getrenntes Vanilla-JS (kein Framework, Konzept-Vorgabe wie
// beim Haupt-Frontend), eigener Token-Storage-Key (sfa_token) - darf niemals mit dem
// Spieler-Token (sf_token) im selben Browser kollidieren.
const AdminApp = (() => {
    const API_BASE_URL = (window.STARFORGE_CONFIG && window.STARFORGE_CONFIG.API_BASE_URL) || '/api';
    let catalogCache = null;
    let universesCache = [];
    let expandedPlayerId = null;

    function $(id) { return document.getElementById(id); }

    function getToken() {
        try { return localStorage.getItem('sfa_token'); } catch (e) { return null; }
    }
    function setToken(token) {
        try {
            if (token) localStorage.setItem('sfa_token', token);
            else localStorage.removeItem('sfa_token');
        } catch (e) { /* privater Modus - Session haelt dann nur bis zum Reload */ }
    }

    async function api(method, path, body) {
        const headers = { 'Content-Type': 'application/json' };
        const token = getToken();
        if (token) headers.Authorization = `Bearer ${token}`;

        const res = await fetch(API_BASE_URL + path, {
            method,
            headers,
            body: body ? JSON.stringify(body) : undefined,
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
            if (res.status === 401) { setToken(null); showLogin(); }
            throw new Error(data.error || `Fehler (${res.status})`);
        }
        return data;
    }

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, c => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        }[c]));
    }

    function showToast(msg) {
        const el = $('toast');
        el.textContent = msg;
        el.classList.add('visible');
        setTimeout(() => el.classList.remove('visible'), 2500);
    }

    function showLogin() {
        $('login-screen').classList.remove('hidden');
        $('dashboard').classList.add('hidden');
    }

    function showDashboard() {
        $('login-screen').classList.add('hidden');
        $('dashboard').classList.remove('hidden');
        loadUniverses();
        loadCatalog();
    }

    async function login() {
        const email = $('login-email').value.trim();
        const password = $('login-password').value;
        $('login-error').textContent = '';
        try {
            const res = await api('POST', '/admin/login', { email, password });
            setToken(res.token);
            showDashboard();
        } catch (e) {
            $('login-error').textContent = e.message;
        }
    }

    async function logout() {
        try { await api('POST', '/admin/logout'); } catch (e) { /* egal, Token wird lokal trotzdem geloescht */ }
        setToken(null);
        showLogin();
    }

    // --- Universen --------------------------------------------------------
    async function loadUniverses() {
        try {
            const res = await api('GET', '/admin/universes');
            universesCache = res.universes;
            renderUniverses();
            renderUniverseFilter();
            loadPlayers();
        } catch (e) { showToast(e.message); }
    }

    function renderUniverses() {
        const rows = universesCache.map(u => `
            <tr data-universe-id="${u.id}">
                <td>${escapeHtml(u.name)}</td>
                <td>${u.playerCount} / ${u.maxPlayers}</td>
                <td>${u.galaxySize}x${u.galaxySize}</td>
                <td><input type="number" class="f-max-players" value="${u.maxPlayers}" min="1"></td>
                <td><input type="number" class="f-game-speed" value="${u.gameSpeed}" min="0.1" step="0.1"></td>
                <td><input type="number" class="f-parallel-rate" value="${u.parallelBuildRate}" min="0" step="0.01"></td>
                <td>
                    <select class="f-status">
                        <option value="open" ${u.status === 'open' ? 'selected' : ''}>offen</option>
                        <option value="closed" ${u.status === 'closed' ? 'selected' : ''}>geschlossen</option>
                    </select>
                </td>
                <td><button class="btn primary save-universe">Speichern</button></td>
            </tr>`).join('');

        $('universes-table').innerHTML = `
            <table class="admin-table">
                <thead><tr>
                    <th>Name</th><th>Spieler</th><th>Raster</th><th>Max. Spieler</th><th>Geschwindigkeit</th><th>Parallel-Rate</th><th>Status</th><th></th>
                </tr></thead>
                <tbody>${rows}</tbody>
            </table>`;

        document.querySelectorAll('.save-universe').forEach(btn => {
            btn.addEventListener('click', async () => {
                const tr = btn.closest('tr');
                const id = parseInt(tr.dataset.universeId, 10);
                const universe = universesCache.find(u => u.id === id);
                try {
                    await api('POST', '/admin/universes/update', {
                        id,
                        name: universe.name,
                        maxPlayers: parseInt(tr.querySelector('.f-max-players').value, 10),
                        gameSpeed: parseFloat(tr.querySelector('.f-game-speed').value),
                        parallelBuildRate: parseFloat(tr.querySelector('.f-parallel-rate').value),
                        status: tr.querySelector('.f-status').value,
                    });
                    showToast('Gespeichert.');
                    loadUniverses();
                } catch (e) { showToast(e.message); }
            });
        });
    }

    function renderUniverseFilter() {
        const select = $('players-universe-filter');
        const previous = select.value;
        select.innerHTML = universesCache.map(u => `<option value="${u.id}">${escapeHtml(u.name)}</option>`).join('');
        if (previous && universesCache.some(u => String(u.id) === previous)) {
            select.value = previous;
        }
    }

    async function createUniverse() {
        const name = $('new-universe-name').value.trim();
        try {
            universesCache = await api('POST', '/admin/universes/create', {
                name,
                maxPlayers: parseInt($('new-universe-max-players').value, 10),
                galaxySize: parseInt($('new-universe-galaxy-size').value, 10),
                gameSpeed: parseFloat($('new-universe-game-speed').value),
                parallelBuildRate: parseFloat($('new-universe-parallel-rate').value),
            }).then(r => r.universes);
            renderUniverses();
            renderUniverseFilter();
            $('new-universe-name').value = '';
            $('create-universe-status').textContent = 'Universum angelegt.';
            setTimeout(() => { $('create-universe-status').textContent = ''; }, 2500);
        } catch (e) { showToast(e.message); }
    }

    // --- Katalog --------------------------------------------------------
    async function loadCatalog() {
        try {
            catalogCache = await api('GET', '/admin/catalog');
            renderBuildingLevels();
            renderShipTypes();
        } catch (e) { showToast(e.message); }
    }

    function renderBuildingLevels() {
        const typesById = {};
        catalogCache.buildingTypes.forEach(t => { typesById[t.id] = t; });

        const rows = catalogCache.buildingLevels.map(l => `
            <tr data-building-type="${l.buildingTypeId}" data-level="${l.level}">
                <td>${escapeHtml(typesById[l.buildingTypeId]?.name || '?')}</td>
                <td>${l.level}</td>
                <td><input type="number" class="f-cost-titan" value="${l.costTitan}"></td>
                <td><input type="number" class="f-cost-deuterium" value="${l.costDeuterium}"></td>
                <td><input type="number" class="f-cost-credits" value="${l.costCredits}"></td>
                <td><input type="number" class="f-build-seconds" value="${l.buildSeconds}"></td>
                <td><input type="number" class="f-effect-amount" value="${l.effectAmount}"></td>
                <td><button class="btn primary save-building-level">Speichern</button></td>
            </tr>`).join('');

        $('building-levels-table').innerHTML = `
            <table class="admin-table">
                <thead><tr>
                    <th>Gebäude</th><th>Lv.</th><th>Titan</th><th>Deuterium</th><th>Credits</th>
                    <th>Bauzeit (s)</th><th>Effekt</th><th></th>
                </tr></thead>
                <tbody>${rows}</tbody>
            </table>`;

        document.querySelectorAll('.save-building-level').forEach(btn => {
            btn.addEventListener('click', async () => {
                const tr = btn.closest('tr');
                try {
                    await api('POST', '/admin/catalog/building-level', {
                        buildingTypeId: parseInt(tr.dataset.buildingType, 10),
                        level: parseInt(tr.dataset.level, 10),
                        costTitan: parseInt(tr.querySelector('.f-cost-titan').value, 10),
                        costDeuterium: parseInt(tr.querySelector('.f-cost-deuterium').value, 10),
                        costCredits: parseInt(tr.querySelector('.f-cost-credits').value, 10),
                        buildSeconds: parseInt(tr.querySelector('.f-build-seconds').value, 10),
                        effectAmount: parseInt(tr.querySelector('.f-effect-amount').value, 10),
                    });
                    showToast('Gespeichert.');
                } catch (e) { showToast(e.message); }
            });
        });
    }

    function renderShipTypes() {
        const rows = catalogCache.shipTypes.map(s => `
            <tr data-ship-id="${s.id}">
                <td>${escapeHtml(s.name)}</td>
                <td><input type="number" class="f-attack" value="${s.attack}"></td>
                <td><input type="number" class="f-shield" value="${s.shield}"></td>
                <td><input type="number" class="f-hull" value="${s.hull}"></td>
                <td><input type="number" class="f-speed" value="${s.speed}"></td>
                <td><input type="number" class="f-cargo" value="${s.cargoCapacity}"></td>
                <td><input type="number" class="f-cost-titan" value="${s.costTitan}"></td>
                <td><input type="number" class="f-cost-deuterium" value="${s.costDeuterium}"></td>
                <td><input type="number" class="f-cost-credits" value="${s.costCredits}"></td>
                <td><input type="number" class="f-build-seconds" value="${s.buildSeconds}"></td>
                <td><input type="number" class="f-min-shipyard" value="${s.minShipyardLevel}"></td>
                <td><button class="btn primary save-ship-type">Speichern</button></td>
            </tr>`).join('');

        $('ship-types-table').innerHTML = `
            <table class="admin-table">
                <thead><tr>
                    <th>Schiff</th><th>Angriff</th><th>Schild</th><th>Hülle</th><th>Tempo</th><th>Fracht</th>
                    <th>Titan</th><th>Deuterium</th><th>Credits</th><th>Bauzeit (s)</th><th>Min. Werft-Lv.</th><th></th>
                </tr></thead>
                <tbody>${rows}</tbody>
            </table>`;

        document.querySelectorAll('.save-ship-type').forEach(btn => {
            btn.addEventListener('click', async () => {
                const tr = btn.closest('tr');
                try {
                    await api('POST', '/admin/catalog/ship-type', {
                        id: parseInt(tr.dataset.shipId, 10),
                        attack: parseInt(tr.querySelector('.f-attack').value, 10),
                        shield: parseInt(tr.querySelector('.f-shield').value, 10),
                        hull: parseInt(tr.querySelector('.f-hull').value, 10),
                        speed: parseInt(tr.querySelector('.f-speed').value, 10),
                        cargoCapacity: parseInt(tr.querySelector('.f-cargo').value, 10),
                        costTitan: parseInt(tr.querySelector('.f-cost-titan').value, 10),
                        costDeuterium: parseInt(tr.querySelector('.f-cost-deuterium').value, 10),
                        costCredits: parseInt(tr.querySelector('.f-cost-credits').value, 10),
                        buildSeconds: parseInt(tr.querySelector('.f-build-seconds').value, 10),
                        minShipyardLevel: parseInt(tr.querySelector('.f-min-shipyard').value, 10),
                    });
                    showToast('Gespeichert.');
                } catch (e) { showToast(e.message); }
            });
        });
    }

    // --- Spieler --------------------------------------------------------
    async function loadPlayers() {
        try {
            const universeId = $('players-universe-filter').value;
            const res = await api('GET', '/admin/players' + (universeId ? `?universeId=${universeId}` : ''));
            renderPlayers(res.players);
        } catch (e) { showToast(e.message); }
    }

    function renderPlayers(players) {
        const rows = players.length
            ? players.map(p => `
                <tr data-player-id="${p.id}">
                    <td><button class="btn toggle-player-bases" data-player-id="${p.id}">${escapeHtml(p.username)}</button></td>
                    <td>${escapeHtml(p.createdAt)}</td>
                    <td>${p.baseCount}</td>
                    <td>${p.totalTitan.toLocaleString('de-DE')}</td>
                    <td>${p.totalDeuterium.toLocaleString('de-DE')}</td>
                    <td>${p.totalCredits.toLocaleString('de-DE')}</td>
                    <td>
                        <button class="btn reissue-token">Zugangscode</button>
                        <button class="btn danger delete-player">Löschen</button>
                    </td>
                </tr>
                <tr class="player-bases-row hidden" data-bases-for="${p.id}"><td colspan="7"></td></tr>`).join('')
            : `<tr><td colspan="7">Keine Spieler.</td></tr>`;

        $('players-table').innerHTML = `
            <table class="admin-table">
                <thead><tr>
                    <th>Name</th><th>Erstellt</th><th>Basen</th><th>Titan</th><th>Deuterium</th><th>Credits</th><th></th>
                </tr></thead>
                <tbody>${rows}</tbody>
            </table>`;

        document.querySelectorAll('.toggle-player-bases').forEach(btn => {
            btn.addEventListener('click', () => togglePlayerBases(parseInt(btn.dataset.playerId, 10)));
        });

        document.querySelectorAll('.reissue-token').forEach(btn => {
            btn.addEventListener('click', async () => {
                const tr = btn.closest('tr');
                const playerId = parseInt(tr.dataset.playerId, 10);
                const username = tr.children[0].textContent;
                if (!confirm(`Neuen Zugangscode für "${username}" ausstellen? Ein eventuell noch gültiger alter Token bleibt nebenbei weiter gültig (mehrere Geräte sind erlaubt).`)) return;
                try {
                    const res = await api('POST', '/admin/players/reissue-token', { playerId });
                    prompt(`Zugangscode für "${username}" (zum Kopieren, einmalig anzeigen):`, res.token);
                } catch (e) { showToast(e.message); }
            });
        });

        document.querySelectorAll('.delete-player').forEach(btn => {
            btn.addEventListener('click', async () => {
                const tr = btn.closest('tr');
                const playerId = parseInt(tr.dataset.playerId, 10);
                const username = tr.children[0].textContent;
                if (!confirm(`Spieler "${username}" wirklich unwiderruflich löschen (inkl. aller Basen/Flotten)?`)) return;
                try {
                    await api('POST', '/admin/players/delete', { playerId });
                    showToast('Spieler gelöscht.');
                    loadPlayers();
                } catch (e) { showToast(e.message); }
            });
        });
    }

    async function togglePlayerBases(playerId) {
        const row = document.querySelector(`tr[data-bases-for="${playerId}"]`);
        const isOpen = expandedPlayerId === playerId;
        document.querySelectorAll('.player-bases-row').forEach(r => r.classList.add('hidden'));
        if (isOpen) {
            expandedPlayerId = null;
            return;
        }
        expandedPlayerId = playerId;
        row.classList.remove('hidden');
        row.querySelector('td').innerHTML = 'Lädt…';
        try {
            const res = await api('GET', `/admin/players/bases?playerId=${playerId}`);
            renderPlayerBases(row, playerId, res.bases);
        } catch (e) { showToast(e.message); }
    }

    function renderPlayerBases(row, playerId, bases) {
        row.querySelector('td').innerHTML = bases.length
            ? `<table class="admin-table admin-table--nested">
                 <thead><tr><th>Basis</th><th>Titan</th><th>Deuterium</th><th>Credits</th><th></th></tr></thead>
                 <tbody>${bases.map(b => `
                    <tr data-base-id="${b.id}">
                        <td>${escapeHtml(b.name)}</td>
                        <td><input type="number" class="f-base-titan" value="${b.titan}" min="0"></td>
                        <td><input type="number" class="f-base-deuterium" value="${b.deuterium}" min="0"></td>
                        <td><input type="number" class="f-base-credits" value="${b.credits}" min="0"></td>
                        <td><button class="btn primary save-base-resources">Speichern</button></td>
                    </tr>`).join('')}</tbody>
               </table>`
            : 'Keine Basen.';

        row.querySelectorAll('.save-base-resources').forEach(btn => {
            btn.addEventListener('click', async () => {
                const tr = btn.closest('tr');
                const baseId = parseInt(tr.dataset.baseId, 10);
                try {
                    const res = await api('POST', '/admin/bases/update', {
                        baseId,
                        titan: parseInt(tr.querySelector('.f-base-titan').value, 10),
                        deuterium: parseInt(tr.querySelector('.f-base-deuterium').value, 10),
                        credits: parseInt(tr.querySelector('.f-base-credits').value, 10),
                    });
                    showToast('Gespeichert.');
                    renderPlayerBases(row, playerId, res.bases);
                    loadPlayers();
                } catch (e) { showToast(e.message); }
            });
        });
    }

    function initTabs() {
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
                document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
                btn.classList.add('active');
                $('tab-' + btn.dataset.tab).classList.add('active');
            });
        });
    }

    function init() {
        $('login-submit').addEventListener('click', login);
        $('login-password').addEventListener('keydown', e => { if (e.key === 'Enter') login(); });
        $('logout-btn').addEventListener('click', logout);
        $('create-universe-btn').addEventListener('click', createUniverse);
        $('players-universe-filter').addEventListener('change', loadPlayers);
        initTabs();

        if (getToken()) showDashboard(); else showLogin();
    }

    return { init };
})();

document.addEventListener('DOMContentLoaded', AdminApp.init);
