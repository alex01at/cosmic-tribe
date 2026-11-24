// Basis-Szene: statisches Canvas-Schema der eigenen Station (Kommandobrücke als Nabe, die
// übrigen sechs Gebäudetypen als Satelliten ringsherum, feste Positionen - kein Pan/Zoom wie bei
// der Weltkarte). Tippen auf ein Gebäude wählt es aus, app.js zeigt dann die Ausbau-Details dazu.
// Bewusst andere Bildsprache als map.js (schematisches Stations-HUD statt Sternenfeld), damit sich
// Basis und Karte unterscheiden statt wie dieselbe Zeichnung mit anderen Farben zu wirken.
const BaseScene = (() => {
    let canvas, ctx, wrap;
    let onTap = null;
    let nodes = []; // {id, x, y, r, effect} in CSS-Pixeln, von render() befüllt, von Klicks gelesen

    const COLOR = {
        command: '#4fd8ff',
        titan_production: '#9fb8d8',
        deuterium_production: '#4fc3f7',
        shipyard: '#ffa726',
        shield: '#9a6bff',
        sensor: '#6bd98a',
        hangar: '#e8a33d',
    };
    const GLYPH = {
        command: '🛰️', titan_production: '⛏️', deuterium_production: '🛢️',
        shipyard: '⚙️', shield: '🛡️', sensor: '📡', hangar: '📦',
    };
    const SHORT_LABEL = {
        command: 'Kommando', titan_production: 'Titan', deuterium_production: 'Deuterium',
        shipyard: 'Werft', shield: 'Schild', sensor: 'Sensor', hangar: 'Hangar',
    };

    function init(canvasEl, wrapEl, tapCallback) {
        canvas = canvasEl;
        wrap = wrapEl;
        ctx = canvas.getContext('2d');
        onTap = tapCallback;

        canvas.addEventListener('click', onClick);
        const resizeObserver = new ResizeObserver(() => resize());
        resizeObserver.observe(wrap);
        resize();
    }

    let lastBase = null;
    let selectedId = null;

    function resize() {
        const dpr = window.devicePixelRatio || 1;
        const rect = wrap.getBoundingClientRect();
        canvas.width = Math.round(rect.width * dpr);
        canvas.height = Math.round(rect.height * dpr);
        canvas.style.width = rect.width + 'px';
        canvas.style.height = rect.height + 'px';
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        if (lastBase) draw();
    }

    function render(base, selected) {
        lastBase = base;
        selectedId = selected;
        draw();
    }

    function hexToRgba(hex, alpha) {
        const n = parseInt(hex.slice(1), 16);
        const r = (n >> 16) & 255, g = (n >> 8) & 255, b = n & 255;
        return `rgba(${r},${g},${b},${alpha})`;
    }

    function buildingPct(b) {
        if (b.status !== 'building') return null;
        const totalSeconds = b.nextLevel ? b.nextLevel.buildSeconds : b.secondsRemaining;
        return totalSeconds > 0 ? Math.max(0, Math.min(1, 1 - b.secondsRemaining / totalSeconds)) : 1;
    }

    function draw() {
        const rect = wrap.getBoundingClientRect();
        const w = rect.width, h = rect.height;
        ctx.clearRect(0, 0, w, h);

        const hub = (lastBase.buildings || []).find(b => b.effect === 'command');
        const satellites = (lastBase.buildings || []).filter(b => b.effect !== 'command');

        const cx = w / 2, cy = h * 0.46;
        const hubR = Math.min(w, h) * 0.15;
        const orbitR = Math.min(w, h) * 0.37;
        const satR = Math.min(w, h) * 0.105;

        // Stations-Kern-Glühen als Hintergrund - gibt dem sonst flachen Panel etwas Tiefe.
        const glow = ctx.createRadialGradient(cx, cy, 0, cx, cy, orbitR * 1.6);
        glow.addColorStop(0, hexToRgba(COLOR.command, 0.12));
        glow.addColorStop(1, hexToRgba(COLOR.command, 0));
        ctx.fillStyle = glow;
        ctx.fillRect(0, 0, w, h);

        nodes = [];
        const n = satellites.length || 1;
        satellites.forEach((b, i) => {
            const angle = -Math.PI / 2 + (i / n) * Math.PI * 2;
            const x = cx + Math.cos(angle) * orbitR;
            const y = cy + Math.sin(angle) * orbitR;
            nodes.push({ id: b.id, x, y, r: satR, effect: b.effect });

            // Verbindungslinie zur Nabe, in der Farbe des jeweiligen Gebäudetyps.
            ctx.strokeStyle = hexToRgba(COLOR[b.effect] || '#8fa2c4', 0.22);
            ctx.lineWidth = 2;
            ctx.beginPath();
            ctx.moveTo(cx, cy);
            ctx.lineTo(x, y);
            ctx.stroke();

            drawNode(x, y, satR, b, b.id === selectedId);
        });

        if (hub) {
            nodes.push({ id: hub.id, x: cx, y: cy, r: hubR, effect: 'command' });
            drawNode(cx, cy, hubR, hub, hub.id === selectedId);
        }
    }

    function drawNode(x, y, r, b, selected) {
        const color = COLOR[b.effect] || '#8fa2c4';
        const pct = buildingPct(b);

        ctx.fillStyle = hexToRgba(color, 0.18);
        ctx.beginPath();
        ctx.arc(x, y, r, 0, Math.PI * 2);
        ctx.fill();

        ctx.strokeStyle = hexToRgba(color, 0.9);
        ctx.lineWidth = 2;
        ctx.stroke();

        if (pct !== null) {
            ctx.strokeStyle = color;
            ctx.lineWidth = 3;
            ctx.beginPath();
            ctx.arc(x, y, r + 5, -Math.PI / 2, -Math.PI / 2 + pct * Math.PI * 2);
            ctx.stroke();
        }

        if (selected) {
            ctx.strokeStyle = '#ffe066';
            ctx.lineWidth = 2;
            ctx.beginPath();
            ctx.arc(x, y, r + 9, 0, Math.PI * 2);
            ctx.stroke();
        }

        ctx.font = `${Math.round(r * 0.95)}px sans-serif`;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillStyle = '#e3ecf7';
        ctx.fillText(GLYPH[b.effect] || '●', x, y);

        // Level-Badge unten rechts am Knoten.
        const badgeR = Math.max(8, r * 0.32);
        const bx = x + r * 0.66, by = y + r * 0.66;
        ctx.fillStyle = '#121a2e';
        ctx.beginPath();
        ctx.arc(bx, by, badgeR, 0, Math.PI * 2);
        ctx.fill();
        ctx.strokeStyle = hexToRgba(color, 0.7);
        ctx.lineWidth = 1;
        ctx.stroke();
        ctx.font = `600 ${Math.round(badgeR * 1.1)}px sans-serif`;
        ctx.fillStyle = '#e3ecf7';
        ctx.fillText(String(b.level), bx, by + 0.5);

        // Kurzlabel unter dem Knoten.
        ctx.font = `${Math.max(10, Math.round(r * 0.34))}px -apple-system, sans-serif`;
        ctx.fillStyle = 'rgba(227,236,247,0.75)';
        ctx.fillText(SHORT_LABEL[b.effect] || '', x, y + r + 14);
    }

    function onClick(e) {
        const rect = canvas.getBoundingClientRect();
        const x = e.clientX - rect.left;
        const y = e.clientY - rect.top;
        const hit = nodes.find(nd => Math.hypot(nd.x - x, nd.y - y) <= nd.r + 6);
        if (onTap) onTap(hit ? hit.id : null);
    }

    return { init, render };
})();
