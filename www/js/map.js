// Canvas-Sektorkarte: Pointer-Events fürs Pannen (Maus UND Touch einheitlich), Wheel/Pinch fürs
// Zoomen, Tap-vs-Drag-Schwelle damit ein Fingertipp nicht als Pan-Geste verschluckt wird.
//
// Optik (Phase 4b): Tiefenverlauf + gecachtes Sternfeld pro Sektor statt flacher Füllfarbe,
// eigene Silhouetten je Slot-Typ statt einheitlicher Punkte, Glow-Akzente für Basen/Außenposten.
// Performance-Regel: alles, was pro Sektor einmalig generiert werden kann (Sternpositionen),
// wird NUR bei loadWindow() berechnet und am Sektor-Objekt zwischengespeichert - draw() läuft
// bei jedem Pan/Zoom-Frame und darf dafür nichts neu würfeln.
const MapView = (() => {
    let canvas, ctx, wrap;
    let cellSize = 48;
    let offsetX = 0, offsetY = 0;
    let centerSectorX = 0, centerSectorY = 0;
    let sectors = [];
    let onSlotTap = null;
    let ownUsername = null;

    let dragging = false, dragStartX = 0, dragStartY = 0, dragMoved = false;
    let pinchStartDist = null, pinchStartCellSize = 48;
    let lastRequestedCenter = null;

    // --- Farb-/Form-Tokens -------------------------------------------------
    const VOID_BG_TOP = '#070b16';
    const VOID_BG_BOTTOM = '#02030a';
    const GRID_LINE = 'rgba(110,150,210,0.07)';
    const COORD_LABEL = 'rgba(150,180,230,0.38)';
    const STAR_COLORS = ['#cfe3ff', '#ffe9c4', '#ffffff'];
    const COLOR_BASE_PROTECTED = '#4fd8ff';
    const COLOR_BASE_OPEN = '#6bd98a';
    const COLOR_OWN_RING = '#ffe066';
    const COLOR_NPC = '#ff5c5c';
    const COLOR_ASTEROID = '#e8a33d';
    const COLOR_NEBULA = '#9a6bff';

    function init(canvasEl, wrapEl, slotTapCallback) {
        canvas = canvasEl;
        wrap = wrapEl;
        ctx = canvas.getContext('2d');
        onSlotTap = slotTapCallback;

        wrap.addEventListener('pointerdown', onPointerDown);
        window.addEventListener('pointermove', onPointerMove);
        window.addEventListener('pointerup', onPointerUp);
        wrap.addEventListener('wheel', onWheel, { passive: false });

        const resizeObserver = new ResizeObserver(() => resize());
        resizeObserver.observe(wrap);
        resize();
    }

    function setOwnUsername(username) {
        ownUsername = username;
    }

    function resize() {
        const dpr = window.devicePixelRatio || 1;
        const rect = wrap.getBoundingClientRect();
        canvas.width = Math.round(rect.width * dpr);
        canvas.height = Math.round(rect.height * dpr);
        canvas.style.width = rect.width + 'px';
        canvas.style.height = rect.height + 'px';
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        draw();
    }

    const pointers = new Map();

    function onPointerDown(e) {
        pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
        if (pointers.size === 1) {
            dragging = true;
            dragMoved = false;
            dragStartX = e.clientX;
            dragStartY = e.clientY;
        } else if (pointers.size === 2) {
            dragging = false;
            pinchStartDist = pinchDistance();
            pinchStartCellSize = cellSize;
        }
    }

    function pinchDistance() {
        const pts = [...pointers.values()];
        return Math.hypot(pts[0].x - pts[1].x, pts[0].y - pts[1].y);
    }

    function onPointerMove(e) {
        if (!pointers.has(e.pointerId)) return;
        pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });

        if (pointers.size === 2) {
            const dist = pinchDistance();
            if (pinchStartDist) {
                cellSize = Math.max(16, Math.min(120, pinchStartCellSize * (dist / pinchStartDist)));
                draw();
            }
            return;
        }

        if (dragging) {
            const dx = e.clientX - dragStartX;
            const dy = e.clientY - dragStartY;
            if (Math.abs(dx) > 4 || Math.abs(dy) > 4) dragMoved = true;
            offsetX += e.movementX || 0;
            offsetY += e.movementY || 0;
            draw();
        }
    }

    function onPointerUp(e) {
        const wasTap = dragging && !dragMoved;
        if (wasTap) {
            handleTap(e.clientX, e.clientY);
        }
        pointers.delete(e.pointerId);
        if (pointers.size < 2) pinchStartDist = null;
        if (pointers.size === 0) {
            dragging = false;
            maybeRefetch();
        }
    }

    function onWheel(e) {
        e.preventDefault();
        cellSize = Math.max(16, Math.min(120, cellSize * (e.deltaY < 0 ? 1.1 : 0.9)));
        draw();
        maybeRefetch();
    }

    function handleTap(clientX, clientY) {
        const rect = canvas.getBoundingClientRect();
        const localX = clientX - rect.left - offsetX;
        const localY = clientY - rect.top - offsetY;
        const sx = Math.floor(localX / cellSize) + centerSectorX;
        const sy = Math.floor(localY / cellSize) + centerSectorY;
        const sector = sectors.find(s => s.x === sx && s.y === sy);
        if (onSlotTap) onSlotTap(sx, sy, sector || null);
    }

    function maybeRefetch() {
        const rect = wrap.getBoundingClientRect();
        const cx = centerSectorX + Math.round((rect.width / 2 - offsetX) / cellSize);
        const cy = centerSectorY + Math.round((rect.height / 2 - offsetY) / cellSize);
        if (!lastRequestedCenter || Math.abs(lastRequestedCenter.x - cx) > 2 || Math.abs(lastRequestedCenter.y - cy) > 2) {
            lastRequestedCenter = { x: cx, y: cy };
            loadWindow(cx, cy);
        }
    }

    async function loadWindow(cx, cy) {
        try {
            const radius = 8;
            const data = await Api.get(`/map?cx=${cx}&cy=${cy}&r=${radius}`);
            // Beim ersten Laden Zentrum fix auf cx/cy setzen, danach bleibt der Nutzer-Pan erhalten
            // (Offset wird NICHT zurückgesetzt, nur die Datengrundlage erweitert).
            if (sectors.length === 0) {
                centerSectorX = cx;
                centerSectorY = cy;
            }
            // Sternpositionen EINMALIG hier je Sektor erzeugen (deterministisch aus x/y, damit sie
            // bei einem Neuladen nicht "springen") und am Objekt cachen - draw() liest nur noch.
            sectors = data.sectors.map(s => ({ ...s, _stars: starsForSector(s.x, s.y) }));
            draw();
        } catch (e) { /* stilles Scheitern - Karte bleibt beim letzten Stand */ }
    }

    function centerOn(x, y) {
        // "Zentrieren" hieß bisher nur dem Namen nach so: offsetX/Y blieben 0, wodurch der
        // Sektor (x,y) an der OBEREN LINKEN Ecke des Canvas landete statt in der Mitte - bei
        // einer Basis nahe dem Galaxierand blieb dadurch der Großteil des Canvas leer (erst beim
        // echten Screenshot-Vergleich fürs Design aufgefallen, nicht beim Code-Lesen).
        const rect = wrap.getBoundingClientRect();
        centerSectorX = x;
        centerSectorY = y;
        offsetX = rect.width / 2 - cellSize / 2;
        offsetY = rect.height / 2 - cellSize / 2;
        lastRequestedCenter = null;
        loadWindow(x, y);
    }

    const SLOT_OFFSETS = [
        [0.5, 0.2], [0.8, 0.35], [0.8, 0.65], [0.5, 0.8],
        [0.2, 0.65], [0.2, 0.35], [0.5, 0.5], [0.65, 0.5],
    ];

    // --- Deterministisches Sternfeld -----------------------------------
    function mulberry32(seed) {
        let a = seed | 0;
        return function () {
            a |= 0;
            a = (a + 0x6D2B79F5) | 0;
            let t = Math.imul(a ^ (a >>> 15), 1 | a);
            t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
            return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
        };
    }

    function starsForSector(x, y) {
        const rand = mulberry32((x * 374761393 + y * 668265263) | 0);
        const count = 4 + Math.floor(rand() * 4);
        const stars = [];
        for (let i = 0; i < count; i++) {
            stars.push({
                ox: rand(), oy: rand(),
                r: 0.4 + rand() * 0.9,
                color: STAR_COLORS[Math.floor(rand() * STAR_COLORS.length)],
                alpha: 0.25 + rand() * 0.55,
            });
        }
        return stars;
    }

    function hexToRgba(hex, alpha) {
        const n = parseInt(hex.slice(1), 16);
        return `rgba(${(n >> 16) & 255},${(n >> 8) & 255},${n & 255},${alpha})`;
    }

    function drawGlow(x, y, radius, color, alpha) {
        const g = ctx.createRadialGradient(x, y, 0, x, y, radius);
        g.addColorStop(0, hexToRgba(color, alpha));
        g.addColorStop(1, hexToRgba(color, 0));
        ctx.fillStyle = g;
        ctx.beginPath();
        ctx.arc(x, y, radius, 0, Math.PI * 2);
        ctx.fill();
    }

    function draw() {
        if (!ctx) return;
        const rect = wrap.getBoundingClientRect();
        ctx.clearRect(0, 0, rect.width, rect.height);

        const bg = ctx.createLinearGradient(0, 0, 0, rect.height);
        bg.addColorStop(0, VOID_BG_TOP);
        bg.addColorStop(1, VOID_BG_BOTTOM);
        ctx.fillStyle = bg;
        ctx.fillRect(0, 0, rect.width, rect.height);

        const visible = [];
        for (const sector of sectors) {
            const px = (sector.x - centerSectorX) * cellSize + offsetX;
            const py = (sector.y - centerSectorY) * cellSize + offsetY;
            if (px < -cellSize || py < -cellSize || px > rect.width + cellSize || py > rect.height + cellSize) continue;
            visible.push({ sector, px, py });
        }

        // Hintergrundebene zuerst (Nebel-Dunst + Sterne), danach Gitter+Marker obenauf - sonst
        // würden die Marker-Glows vom Sternfeld überdeckt.
        for (const { sector, px, py } of visible) {
            if (sector.slots.some(s => s.type === 'nebula')) {
                const cx = px + cellSize / 2, cy = py + cellSize / 2;
                const haze = ctx.createRadialGradient(cx, cy, 0, cx, cy, cellSize * 0.9);
                haze.addColorStop(0, hexToRgba(COLOR_NEBULA, 0.14));
                haze.addColorStop(1, hexToRgba(COLOR_NEBULA, 0));
                ctx.fillStyle = haze;
                ctx.fillRect(px - cellSize * 0.4, py - cellSize * 0.4, cellSize * 1.8, cellSize * 1.8);
            }
            for (const star of sector._stars || []) {
                ctx.globalAlpha = star.alpha;
                ctx.fillStyle = star.color;
                ctx.beginPath();
                ctx.arc(px + star.ox * cellSize, py + star.oy * cellSize, star.r, 0, Math.PI * 2);
                ctx.fill();
            }
        }
        ctx.globalAlpha = 1;

        for (const { sector, px, py } of visible) {
            ctx.strokeStyle = GRID_LINE;
            ctx.lineWidth = 1;
            ctx.strokeRect(px + 0.5, py + 0.5, cellSize, cellSize);

            if (cellSize > 30) {
                ctx.fillStyle = COORD_LABEL;
                ctx.font = '9px ui-monospace, SFMono-Regular, Menlo, monospace';
                ctx.fillText(`${sector.x}:${sector.y}`, px + 3, py + 10);
            }

            for (const slot of sector.slots) {
                const [ox, oy] = SLOT_OFFSETS[slot.position] || [0.5, 0.5];
                drawSlotMarker(px + ox * cellSize, py + oy * cellSize, slot, cellSize);
            }
        }
    }

    function drawSlotMarker(x, y, slot, size) {
        const r = Math.max(2.5, size * 0.1);

        if (slot.type === 'base') {
            const color = slot.protected ? COLOR_BASE_PROTECTED : COLOR_BASE_OPEN;
            drawGlow(x, y, r * 2.2, color, 0.22);
            ctx.fillStyle = color;
            ctx.beginPath();
            ctx.arc(x, y, r, 0, Math.PI * 2);
            ctx.fill();
            if (slot.protected) {
                ctx.strokeStyle = color;
                ctx.lineWidth = Math.max(1, r * 0.25);
                ctx.setLineDash([r * 0.5, r * 0.4]);
                ctx.beginPath();
                ctx.arc(x, y, r * 1.8, 0, Math.PI * 2);
                ctx.stroke();
                ctx.setLineDash([]);
            }
            if (ownUsername !== null && slot.owner === ownUsername) {
                ctx.strokeStyle = COLOR_OWN_RING;
                ctx.lineWidth = Math.max(1, r * 0.3);
                ctx.beginPath();
                ctx.arc(x, y, r * 1.4, 0, Math.PI * 2);
                ctx.stroke();
            }
            return;
        }

        if (slot.type === 'npc_outpost') {
            drawGlow(x, y, r * 2, COLOR_NPC, 0.2);
            ctx.fillStyle = COLOR_NPC;
            ctx.beginPath();
            ctx.moveTo(x, y - r * 1.2);
            ctx.lineTo(x + r * 1.1, y + r * 0.8);
            ctx.lineTo(x - r * 1.1, y + r * 0.8);
            ctx.closePath();
            ctx.fill();
            return;
        }

        if (slot.type === 'asteroid_field') {
            const scale = 0.8 + Math.min(1, slot.bonusPercent / 40) * 0.6;
            const chunks = [
                { dx: -0.5, dy: -0.2, s: 1.0 },
                { dx: 0.4, dy: 0.1, s: 0.8 },
                { dx: -0.1, dy: 0.5, s: 0.65 },
            ];
            ctx.fillStyle = COLOR_ASTEROID;
            for (const c of chunks) {
                const rr = r * c.s * scale;
                ctx.save();
                ctx.translate(x + c.dx * r, y + c.dy * r);
                ctx.rotate(0.5);
                ctx.fillRect(-rr / 2, -rr / 2, rr, rr);
                ctx.restore();
            }
            return;
        }

        if (slot.type === 'nebula') {
            const glow = ctx.createRadialGradient(x, y, 0, x, y, r * 2.4);
            glow.addColorStop(0, hexToRgba(COLOR_NEBULA, 0.55));
            glow.addColorStop(1, hexToRgba(COLOR_NEBULA, 0));
            ctx.fillStyle = glow;
            ctx.beginPath();
            ctx.arc(x, y, r * 2.4, 0, Math.PI * 2);
            ctx.fill();
        }
    }

    return { init, loadWindow, centerOn, resize, setOwnUsername };
})();
