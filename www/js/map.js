// Canvas-Sektorkarte: Pointer-Events fürs Pannen (Maus UND Touch einheitlich), Wheel/Pinch fürs
// Zoomen, Tap-vs-Drag-Schwelle damit ein Fingertipp nicht als Pan-Geste verschluckt wird.
const MapView = (() => {
    let canvas, ctx, wrap;
    let cellSize = 48;
    let offsetX = 0, offsetY = 0;
    let centerSectorX = 0, centerSectorY = 0;
    let sectors = [];
    let onSlotTap = null;

    let dragging = false, dragStartX = 0, dragStartY = 0, dragMoved = false;
    let pinchStartDist = null, pinchStartCellSize = 48;
    let lastRequestedCenter = null;

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
            sectors = data.sectors;
            draw();
        } catch (e) { /* stilles Scheitern - Karte bleibt beim letzten Stand */ }
    }

    function centerOn(x, y) {
        const rect = wrap.getBoundingClientRect();
        centerSectorX = x;
        centerSectorY = y;
        offsetX = 0;
        offsetY = 0;
        lastRequestedCenter = null;
        loadWindow(x, y);
    }

    const SLOT_OFFSETS = [
        [0.5, 0.2], [0.8, 0.35], [0.8, 0.65], [0.5, 0.8],
        [0.2, 0.65], [0.2, 0.35], [0.5, 0.5], [0.65, 0.5],
    ];

    function draw() {
        if (!ctx) return;
        const rect = wrap.getBoundingClientRect();
        ctx.clearRect(0, 0, rect.width, rect.height);
        ctx.fillStyle = '#060a14';
        ctx.fillRect(0, 0, rect.width, rect.height);

        for (const sector of sectors) {
            const px = (sector.x - centerSectorX) * cellSize + offsetX;
            const py = (sector.y - centerSectorY) * cellSize + offsetY;
            if (px < -cellSize || py < -cellSize || px > rect.width + cellSize || py > rect.height + cellSize) continue;

            ctx.strokeStyle = '#1a2440';
            ctx.lineWidth = 1;
            ctx.strokeRect(px + 0.5, py + 0.5, cellSize, cellSize);

            if (cellSize > 26) {
                ctx.fillStyle = '#2a3a5c';
                ctx.font = '9px sans-serif';
                ctx.fillText(`${sector.x}:${sector.y}`, px + 2, py + 10);
            }

            for (const slot of sector.slots) {
                const [ox, oy] = SLOT_OFFSETS[slot.position] || [0.5, 0.5];
                const sxp = px + ox * cellSize;
                const syp = py + oy * cellSize;
                drawSlotMarker(sxp, syp, slot, cellSize);
            }
        }
    }

    function drawSlotMarker(x, y, slot, size) {
        const r = Math.max(2, size * 0.09);
        let color = '#888';
        if (slot.type === 'base') color = slot.protected ? '#4fc3f7' : '#66bb6a';
        else if (slot.type === 'npc_outpost') color = '#ef5350';
        else if (slot.type === 'asteroid_field') color = '#ffa726';
        else if (slot.type === 'nebula') color = '#ab47bc';

        ctx.fillStyle = color;
        ctx.beginPath();
        ctx.arc(x, y, r, 0, Math.PI * 2);
        ctx.fill();
    }

    return { init, loadWindow, centerOn, resize };
})();
