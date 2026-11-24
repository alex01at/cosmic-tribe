// Dünner Fetch-Wrapper: Token-Verwaltung, 401-Behandlung, Zeit-Offset-Berechnung.
const Api = (() => {
    const BASE_URL = window.STARFORGE_CONFIG.API_BASE_URL;
    let clockOffsetSeconds = 0; // serverNow - lokale Zeit, einmal pro Antwort aktualisiert

    // Tokens liegen als Map {universeId: token} unter EINEM Key, nicht mehr als einzelner
    // sf_token - ein Gerät kann in mehreren Universen je einen eigenen Charakter haben, und jedes
    // Universum braucht dafür seinen eigenen Token im selben Browser.
    function getTokensMap() {
        try {
            return JSON.parse(localStorage.getItem('sf_tokens') || '{}');
        } catch (e) {
            return {};
        }
    }

    function saveTokensMap(map) {
        try {
            localStorage.setItem('sf_tokens', JSON.stringify(map));
        } catch (e) { /* privater Modus o.ä. - Session bleibt dann nur im Speicher */ }
    }

    function getActiveUniverse() {
        try {
            const id = localStorage.getItem('sf_active_universe');
            return id ? parseInt(id, 10) : null;
        } catch (e) {
            return null;
        }
    }

    function setActiveUniverse(universeId) {
        try {
            localStorage.setItem('sf_active_universe', String(universeId));
        } catch (e) { /* ignore */ }
    }

    function clearActiveUniverse() {
        try {
            localStorage.removeItem('sf_active_universe');
        } catch (e) { /* ignore */ }
    }

    function hasTokenFor(universeId) {
        return !!getTokensMap()[universeId];
    }

    function getToken() {
        const universeId = getActiveUniverse();
        if (universeId === null) {
            return null;
        }
        return getTokensMap()[universeId] || null;
    }

    function setToken(token) {
        const universeId = getActiveUniverse();
        if (universeId === null) {
            return;
        }
        const map = getTokensMap();
        map[universeId] = token;
        saveTokensMap(map);
    }

    function getDeviceId() {
        try {
            let id = localStorage.getItem('sf_device_id');
            if (!id) {
                id = generateUuid();
                localStorage.setItem('sf_device_id', id);
            }
            return id;
        } catch (e) {
            return generateUuid();
        }
    }

    function generateUuid() {
        // crypto.randomUUID() ist über HTTP (z.B. per LAN-IP auf dem Handy getestet) oft nicht
        // verfügbar (nur in sicheren Kontexten) - Fallback über getRandomValues.
        if (window.crypto && window.crypto.randomUUID) {
            try {
                return window.crypto.randomUUID();
            } catch (e) { /* fällt durch zum Fallback */ }
        }
        const bytes = new Uint8Array(16);
        (window.crypto || { getRandomValues: arr => arr }).getRandomValues(bytes);
        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;
        const hex = [...bytes].map(b => b.toString(16).padStart(2, '0')).join('');
        return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
    }

    // Löscht NUR den Token des aktuell aktiven Universums (z.B. nach 401, abgelaufener Token) -
    // device_id und die Tokens anderer Universen bleiben erhalten, sonst würde ein ungültig
    // gewordener Token in Welt A auch den Zugang zu Welt B kosten.
    function clearSession() {
        const universeId = getActiveUniverse();
        if (universeId === null) {
            return;
        }
        const map = getTokensMap();
        delete map[universeId];
        saveTokensMap(map);
    }

    async function call(method, path, body) {
        const headers = { 'Content-Type': 'application/json' };
        const token = getToken();
        if (token) {
            headers.Authorization = `Bearer ${token}`;
        }
        let res;
        try {
            res = await fetch(BASE_URL + path, {
                method,
                headers,
                body: body !== undefined ? JSON.stringify(body) : undefined,
            });
        } catch (e) {
            // fetch() wirft bei fehlender Verbindung (kein Netz, Server nicht erreichbar) ein
            // TypeError - das ist KEIN 401 und darf Token/Device-ID nicht löschen, sonst verliert
            // ein Spieler bei einem kurzen Netzaussetzer seine Session.
            throw new ApiError('NETWORK_ERROR', 0, null, true);
        }

        let data = null;
        try {
            data = await res.json();
        } catch (e) { /* leerer Body, z.B. bei 204 */ }

        if (res.status === 401) {
            // Dev-DB-Reset o.ä. hinterlässt sonst einen Nutzer, der für immer an der
            // 409-Fehlermeldung ("Geräte-ID bereits registriert") hängen bleibt, weil device_id
            // zwar noch da ist, der Token aber nicht mehr gilt.
            clearSession();
            throw new ApiError('UNAUTHORIZED', 401, data);
        }
        if (!res.ok) {
            throw new ApiError((data && data.error) || 'Fehler', res.status, data);
        }

        if (data && typeof data.serverNow === 'number') {
            clockOffsetSeconds = data.serverNow - Math.floor(Date.now() / 1000);
        }

        return data;
    }

    function serverNowEpoch() {
        return Math.floor(Date.now() / 1000) + clockOffsetSeconds;
    }

    return {
        get: path => call('GET', path),
        post: (path, body) => call('POST', path, body || {}),
        getToken,
        setToken,
        getDeviceId,
        clearSession,
        serverNowEpoch,
        getActiveUniverse,
        setActiveUniverse,
        clearActiveUniverse,
        hasTokenFor,
        listUniverses: () => call('GET', '/universes'),
    };
})();

class ApiError extends Error {
    constructor(message, status, body, isNetworkError) {
        super(message);
        this.status = status;
        this.body = body;
        this.isNetworkError = !!isNetworkError;
    }
}
