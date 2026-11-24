// Basis-Szene: isometrisches Mini-Diorama der eigenen Station (nach Nutzer-Vorgabe/Referenzbild,
// Stil ähnlich Lords & Knights/Travian: Gebäude als einzelne Baukörper auf einem Boden-Raster statt
// abstrakter Icons). Jedes Gebäude ist eine simple Iso-Box (3 schattierte Flächen) mit einer
// kleinen Aufbau-Dekoration je Typ (Antenne/Kuppel/Tank/...) statt echter Pixel-Art-Sprites - die
// könnte ich nicht von Hand überzeugend pixeln, das Boxen+Dekoration-Prinzip trägt denselben
// Wiedererkennungswert (Form+Farbe pro Typ, wie bei Karten-Markern und Schiffs-Icons).
// Tippen auf ein Gebäude wählt es aus, app.js zeigt dann die Ausbau-Details dazu.
const BaseScene = (() => {
    let canvas, ctx, wrap;
    let onTap = null;
    let nodes = []; // {id, x, y, r} in CSS-Pixeln (Klick-Trefferkreis um den Baukörper)

    const COLOR = {
        command: '#4fd8ff',
        titan_production: '#9fb8d8',
        deuterium_production: '#4fc3f7',
        shipyard: '#ffa726',
        shield: '#9a6bff',
        sensor: '#6bd98a',
        hangar: '#e8a33d',
    };
    const SHORT_LABEL = {
        command: 'Kommando', titan_production: 'Titan-Raffinerie', deuterium_production: 'Deuterium-Sammler',
        shipyard: 'Werft', shield: 'Schildgenerator', sensor: 'Sensor', hangar: 'Hangar',
    };
    // Feste Diorama-Anordnung: 4 Gebäude vorne (größer, näher), 3 hinten (kleiner, zentrale
    // Kommandobrücke am höchsten) - gibt echte Tiefe statt einer flachen Reihe.
    const FRONT_ROW = ['hangar', 'deuterium_production', 'shipyard', 'sensor'];
    const BACK_ROW = ['titan_production', 'command', 'shield'];

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

    function shade(hex, factor) {
        const n = parseInt(hex.slice(1), 16);
        const r = Math.round(((n >> 16) & 255) * factor);
        const g = Math.round(((n >> 8) & 255) * factor);
        const b = Math.round((n & 255) * factor);
        return `rgb(${Math.min(255, r)},${Math.min(255, g)},${Math.min(255, b)})`;
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

        drawGround(w, h);

        const byEffect = {};
        (lastBase.buildings || []).forEach(b => { byEffect[b.effect] = b; });

        nodes = [];
        const frontY = h * 0.80;
        const backY = h * 0.50;

        // Hintere Reihe zuerst zeichnen (kleiner, weiter weg), dann vordere Reihe darüber -
        // sonst würden vordere Gebäude von hinteren verdeckt statt umgekehrt.
        BACK_ROW.forEach((effect, i) => {
            const b = byEffect[effect];
            if (!b) return;
            const x = w * (0.28 + i * 0.22);
            const isCommand = effect === 'command';
            drawBuilding(b, x, backY, isCommand ? 30 : 24, isCommand ? 58 : 38, 0.82);
        });
        FRONT_ROW.forEach((effect, i) => {
            const b = byEffect[effect];
            if (!b) return;
            const x = w * (0.1 + i * 0.27);
            drawBuilding(b, x, frontY, 32, 34, 1.0);
        });
    }

    function drawGround(w, h) {
        const top = h * 0.40, bottom = h * 0.96;
        const grd = ctx.createLinearGradient(0, top, 0, bottom);
        grd.addColorStop(0, 'rgba(79,216,255,0.05)');
        grd.addColorStop(1, 'rgba(79,216,255,0.02)');
        ctx.fillStyle = grd;
        ctx.fillRect(0, top, w, bottom - top);

        ctx.strokeStyle = 'rgba(110,150,210,0.12)';
        ctx.lineWidth = 1;
        const cols = 10;
        for (let i = 0; i <= cols; i++) {
            const x = (w / cols) * i;
            ctx.beginPath();
            ctx.moveTo(x, bottom);
            ctx.lineTo(w / 2 + (x - w / 2) * 0.35, top);
            ctx.stroke();
        }
        const rows = 5;
        for (let i = 0; i <= rows; i++) {
            const y = top + ((bottom - top) / rows) * i;
            const spread = 0.35 + (i / rows) * 0.65;
            ctx.beginPath();
            ctx.moveTo(w / 2 - (w / 2) * spread, y);
            ctx.lineTo(w / 2 + (w / 2) * spread, y);
            ctx.stroke();
        }
    }

    // Baukörper als Iso-Box: Grundpunkt (x, groundY) ist der vorderste Bodenpunkt, von dort aus
    // Links-/Rechts-/Deckfläche in klassischer 2:1-Isometrie aufgespannt (drei Helligkeitsstufen
    // derselben Farbe - das allein erzeugt schon den "kleiner Baukörper"-Eindruck ohne Sprites).
    function drawBuilding(b, x, groundY, halfW, height, scale) {
        const color = COLOR[b.effect] || '#8fa2c4';
        const hw = halfW * scale;
        const depth = hw * 0.55;
        const H = height * scale;
        const selected = b.id === selectedId;
        const pct = buildingPct(b);

        const front = { x, y: groundY };
        const left = { x: x - hw, y: groundY - depth };
        const right = { x: x + hw, y: groundY - depth };
        const back = { x, y: groundY - depth * 2 };
        const topFront = { x: front.x, y: front.y - H };
        const topLeft = { x: left.x, y: left.y - H };
        const topRight = { x: right.x, y: right.y - H };
        const topBack = { x: back.x, y: back.y - H };

        if (selected) {
            ctx.save();
            ctx.shadowColor = '#ffe066';
            ctx.shadowBlur = 16;
            ctx.strokeStyle = '#ffe066';
            ctx.lineWidth = 2;
            ctx.beginPath();
            ctx.ellipse(x, groundY + 2, hw * 1.15, depth * 0.9, 0, 0, Math.PI * 2);
            ctx.stroke();
            ctx.restore();
        }

        // Linke Fläche (dunkelste Schattierung)
        ctx.fillStyle = shade(color, 0.55);
        ctx.beginPath();
        ctx.moveTo(front.x, front.y); ctx.lineTo(left.x, left.y); ctx.lineTo(topLeft.x, topLeft.y); ctx.lineTo(topFront.x, topFront.y);
        ctx.closePath(); ctx.fill();

        // Rechte Fläche (mittlere Schattierung)
        ctx.fillStyle = shade(color, 0.75);
        ctx.beginPath();
        ctx.moveTo(front.x, front.y); ctx.lineTo(right.x, right.y); ctx.lineTo(topRight.x, topRight.y); ctx.lineTo(topFront.x, topFront.y);
        ctx.closePath(); ctx.fill();

        // Deckfläche (hellste Schattierung)
        ctx.fillStyle = shade(color, 1.1);
        ctx.beginPath();
        ctx.moveTo(topFront.x, topFront.y); ctx.lineTo(topLeft.x, topLeft.y); ctx.lineTo(topBack.x, topBack.y); ctx.lineTo(topRight.x, topRight.y);
        ctx.closePath(); ctx.fill();

        ctx.strokeStyle = hexToRgba('#000000', 0.25);
        ctx.lineWidth = 1;
        ctx.stroke();

        drawDecoration(b.effect, topFront, hw, H, color);

        if (pct !== null) {
            const barW = hw * 1.9;
            const barY = topFront.y - hw * 1.6;
            ctx.fillStyle = 'rgba(10,14,26,0.85)';
            ctx.fillRect(x - barW / 2, barY, barW, 5);
            ctx.fillStyle = color;
            ctx.fillRect(x - barW / 2, barY, barW * pct, 5);
        }

        // Level-Badge an der rechten Fläche.
        const badgeR = Math.max(7, hw * 0.26);
        const bx = right.x - hw * 0.15, by = (front.y + right.y) / 2 - H * 0.25;
        ctx.fillStyle = '#121a2e';
        ctx.beginPath(); ctx.arc(bx, by, badgeR, 0, Math.PI * 2); ctx.fill();
        ctx.strokeStyle = hexToRgba(color, 0.8);
        ctx.lineWidth = 1;
        ctx.stroke();
        ctx.font = `600 ${Math.round(badgeR * 1.15)}px sans-serif`;
        ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
        ctx.fillStyle = '#e3ecf7';
        ctx.fillText(String(b.level), bx, by + 0.5);

        // Label unter dem Baukörper.
        ctx.font = `${Math.max(10, Math.round(hw * 0.34))}px -apple-system, sans-serif`;
        ctx.fillStyle = 'rgba(227,236,247,0.85)';
        ctx.fillText(SHORT_LABEL[b.effect] || '', x, groundY + 16);

        nodes.push({ id: b.id, x, y: groundY - H * 0.4, r: Math.max(hw, H * 0.5) });
    }

    function drawDecoration(effect, top, hw, H, color) {
        const cx = top.x, cy = top.y;
        ctx.strokeStyle = color;
        ctx.fillStyle = color;
        ctx.lineWidth = 2;

        switch (effect) {
            case 'command': {
                ctx.beginPath(); ctx.moveTo(cx, cy); ctx.lineTo(cx, cy - hw * 0.9); ctx.stroke();
                const glow = ctx.createRadialGradient(cx, cy - hw * 1.2, 0, cx, cy - hw * 1.2, hw * 0.8);
                glow.addColorStop(0, hexToRgba(color, 0.9));
                glow.addColorStop(1, hexToRgba(color, 0));
                ctx.fillStyle = glow;
                ctx.beginPath(); ctx.arc(cx, cy - hw * 1.2, hw * 0.8, 0, Math.PI * 2); ctx.fill();
                ctx.fillStyle = '#ffffff';
                ctx.beginPath(); ctx.arc(cx, cy - hw * 1.2, hw * 0.3, 0, Math.PI * 2); ctx.fill();
                break;
            }
            case 'titan_production': {
                ctx.beginPath();
                ctx.moveTo(cx, cy - hw * 0.8); ctx.lineTo(cx - hw * 0.4, cy); ctx.lineTo(cx + hw * 0.4, cy);
                ctx.closePath(); ctx.fill();
                break;
            }
            case 'deuterium_production': {
                ctx.fillStyle = hexToRgba(color, 0.9);
                ctx.fillRect(cx - hw * 0.3, cy - hw * 0.9, hw * 0.6, hw * 0.9);
                ctx.beginPath(); ctx.ellipse(cx, cy - hw * 0.9, hw * 0.3, hw * 0.12, 0, 0, Math.PI * 2); ctx.fill();
                break;
            }
            case 'shipyard': {
                ctx.beginPath(); ctx.moveTo(cx - hw * 0.5, cy); ctx.lineTo(cx + hw * 0.1, cy - hw * 1.1); ctx.stroke();
                ctx.beginPath(); ctx.moveTo(cx + hw * 0.1, cy - hw * 1.1); ctx.lineTo(cx + hw * 0.6, cy - hw * 0.9); ctx.stroke();
                ctx.beginPath(); ctx.arc(cx + hw * 0.6, cy - hw * 0.9, 3, 0, Math.PI * 2); ctx.fill();
                break;
            }
            case 'shield': {
                ctx.fillStyle = hexToRgba(color, 0.35);
                ctx.beginPath(); ctx.arc(cx, cy, hw * 0.75, Math.PI, 0); ctx.closePath(); ctx.fill();
                ctx.strokeStyle = hexToRgba(color, 0.9);
                ctx.beginPath(); ctx.arc(cx, cy, hw * 0.75, Math.PI, 0); ctx.stroke();
                break;
            }
            case 'sensor': {
                ctx.beginPath(); ctx.moveTo(cx, cy); ctx.lineTo(cx, cy - hw * 0.6); ctx.stroke();
                ctx.save();
                ctx.translate(cx, cy - hw * 0.7);
                ctx.rotate(-0.5);
                ctx.beginPath(); ctx.ellipse(0, 0, hw * 0.5, hw * 0.2, 0, 0, Math.PI * 2); ctx.fill();
                ctx.restore();
                break;
            }
            case 'hangar': {
                const doorCenterY = cy + H * 0.55;
                ctx.fillStyle = 'rgba(10,14,26,0.6)';
                ctx.fillRect(cx - hw * 0.28, doorCenterY - hw * 0.32, hw * 0.56, hw * 0.64);
                break;
            }
        }
    }

    function onClick(e) {
        const rect = canvas.getBoundingClientRect();
        const x = e.clientX - rect.left;
        const y = e.clientY - rect.top;
        let best = null, bestDist = Infinity;
        nodes.forEach(nd => {
            const d = Math.hypot(nd.x - x, nd.y - y);
            if (d <= nd.r + 8 && d < bestDist) { best = nd; bestDist = d; }
        });
        if (onTap) onTap(best ? best.id : null);
    }

    return { init, render };
})();
