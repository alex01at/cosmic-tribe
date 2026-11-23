// Dünner Fetch-Wrapper: Token-Verwaltung, 401-Behandlung, Zeit-Offset-Berechnung.
const Api = (() => {
    const BASE_URL = window.STARFORGE_CONFIG.API_BASE_URL;
    let clockOffsetSeconds = 0; // serverNow - lokale Zeit, einmal pro Antwort aktualisiert

    function getToken() {
        try {
            return localStorage.getItem('sf_token');
        } catch (e) {
            return null;
        }
    }

    function setToken(token) {
        try {
            localStorage.setItem('sf_token', token);
        } catch (e) { /* privater Modus o.ä. - Session bleibt dann nur im Speicher */ }
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

    function clearSession() {
        try {
            localStorage.removeItem('sf_token');
            localStorage.removeItem('sf_device_id');
        } catch (e) { /* ignore */ }
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
