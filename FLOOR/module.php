<?php

declare(strict_types=1);

/*
 * Floorplaner
 * Prefix in module.json: FLOOR
 *
 * Basis / Zielprojekt:
 * Easy Floorplan by Nicolas Sandller
 * https://github.com/nicosandller/easy-floorplan
 * License: MIT
 *
 * Hinweis:
 * Diese erste Version übernimmt bewusst noch nicht den kompletten
 * Home-Assistant-Build von Easy Floorplan. Sie verwendet aber bereits
 * ein kompatibel angelehntes JSON-Datenmodell (walls, openings, items,
 * texts, floors) und stellt einen eigenen HTML-SDK-Editor bereit.
 *
 * Dadurch können wir Easy Floorplan schrittweise portieren, ohne
 * Home-Assistant-Abhängigkeiten in IP-Symcon einzuschleppen.
 */

class Floorplaner extends IPSModuleStrict
{
    private const ATTRIBUTE_DATA = 'FloorplanData';
    private const VISUALIZATION_TYPE_HTML = 1;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyInteger('GridSize', 20);
        $this->RegisterPropertyInteger('SnapSize', 20);
        $this->RegisterPropertyString('BackgroundColor', '#303030');
        $this->RegisterPropertyBoolean('ShowGrid', true);

        $this->RegisterAttributeString(self::ATTRIBUTE_DATA, '');

        $this->SetVisualizationType(self::VISUALIZATION_TYPE_HTML);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SetVisualizationType(self::VISUALIZATION_TYPE_HTML);

        if ($this->ReadAttributeString(self::ATTRIBUTE_DATA) === '') {
            $this->WriteAttributeString(
                self::ATTRIBUTE_DATA,
                json_encode(
                    $this->CreateDefaultProject(),
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                )
            );
        }

        $this->SetSummary('Floorplan Editor');
    }

    public function GetConfigurationForm(): string
    {
        $project = $this->GetProject();
        $counts = $this->CountElements($project);

        $elements = [
            [
                'type'    => 'Label',
                'caption' => 'Floorplaner – Floorplan Editor für IP-Symcon'
            ],
            [
                'type'    => 'Label',
                'caption' => 'Basis: Easy Floorplan (MIT). Die Zeichenfläche hat keine feste Projektgröße und passt sich dynamisch an die verfügbare HTML-SDK-Fläche an.'
            ],
            [
                'type'     => 'ExpansionPanel',
                'caption'  => 'Projekt',
                'expanded' => true,
                'items'    => [

                    [
                        'type'    => 'NumberSpinner',
                        'name'    => 'GridSize',
                        'caption' => 'Raster',
                        'minimum' => 5,
                        'maximum' => 200
                    ],
                    [
                        'type'    => 'NumberSpinner',
                        'name'    => 'SnapSize',
                        'caption' => 'Snap-Schritt',
                        'minimum' => 0,
                        'maximum' => 200
                    ],
                    [
                        'type'    => 'SelectColor',
                        'name'    => 'BackgroundColor',
                        'caption' => 'Hintergrund'
                    ],
                    [
                        'type'    => 'CheckBox',
                        'name'    => 'ShowGrid',
                        'caption' => 'Raster im Editor anzeigen'
                    ]
                ]
            ],
            [
                'type'     => 'ExpansionPanel',
                'caption'  => 'Projektstatus',
                'expanded' => true,
                'items'    => [
                    [
                        'type'    => 'Label',
                        'caption' => sprintf(
                            'Etagen: %d | Wände: %d | Türen/Fenster: %d | Geräte: %d | Texte: %d',
                            $counts['floors'],
                            $counts['walls'],
                            $counts['openings'],
                            $counts['items'],
                            $counts['texts']
                        )
                    ],
                    [
                        'type'    => 'Label',
                        'caption' => 'Hinweis: IP-Symcon-Konfigurationsformulare können kein beliebiges HTML/JavaScript einbetten. Deshalb ist der Zeicheneditor als HTML-SDK-Darstellung derselben Instanz umgesetzt. Die Projekteinstellungen bleiben hier im Konfigurationsformular.'
                    ]
                ]
            ]
        ];

        $actions = [
            [
                'type'    => 'Label',
                'caption' => 'Projektwerkzeuge'
            ],
            [
                'type'    => 'Button',
                'caption' => 'Floorplan JSON anzeigen',
                'onClick' => 'echo FLOOR_GetFloorplanJSON($id);'
            ],
            [
                'type'     => 'Button',
                'caption'  => 'Floorplan JSON herunterladen',
                'download' => 'floorplan.json',
                'onClick'  => 'echo "data:application/json;charset=utf-8," . rawurlencode(FLOOR_GetFloorplanJSON($id));'
            ],
            [
                'type'    => 'Button',
                'caption' => 'Editor-Daten auf Projekteinstellungen synchronisieren',
                'onClick' => 'FLOOR_SyncProjectSettings($id); echo "Synchronisiert";'
            ],
            [
                'type'    => 'Button',
                'caption' => 'Floorplan zurücksetzen',
                'confirm' => 'Soll der komplette Floorplan wirklich gelöscht werden?',
                'onClick' => 'FLOOR_ResetFloorplan($id); echo "Floorplan wurde zurückgesetzt";'
            ]
        ];

        return json_encode(
            [
                'elements' => $elements,
                'actions'  => $actions,
                'status'   => [
                    ['code' => 102, 'icon' => 'active', 'caption' => 'Aktiv']
                ]
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    public function GetVisualizationTile(): string
    {
        $project = $this->GetProject();

        /*
         * Properties aus dem Konfigurationsformular sind führend für
         * Canvas-Größe, Raster und Hintergrund.
         */
        $project['grid'] = max(5, $this->ReadPropertyInteger('GridSize'));
        $project['snap'] = max(0, $this->ReadPropertyInteger('SnapSize'));
        $project['background'] = $this->ReadPropertyString('BackgroundColor');
        $project['showGrid'] = $this->ReadPropertyBoolean('ShowGrid');
        $project = $this->AddRuntimeValues($project);

        $initial = json_encode(
            $project,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
        );

        $html = <<<'HTML'
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <style>
        :root {
            color-scheme: dark;
            --fp-bg: #242424;
            --fp-panel: #303030;
            --fp-panel-2: #383838;
            --fp-border: rgba(255,255,255,.16);
            --fp-text: #f2f2f2;
            --fp-muted: #b8b8b8;
            --fp-accent: #4da3ff;
            --fp-danger: #e35d6a;
        }

        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            background: var(--fp-bg);
            color: var(--fp-text);
            font-family: Arial, Helvetica, sans-serif;
        }

        button, input, select {
            font: inherit;
        }

        #app {
            display: grid;
            grid-template-rows: 1fr auto;
            width: 100%;
            height: 100%;
            min-height: 420px;
        }

        /* HTML-SDK: Bedienelemente bewusst UNTEN.
           Im oberen Bereich können Symcon-Overlays Pointer-Ereignisse abfangen. */
        .toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            align-items: center;
            padding: 8px;
            background: var(--fp-panel);
            border-top: 1px solid var(--fp-border);
        }

        .toolbar .group {
            display: flex;
            gap: 4px;
            align-items: center;
            padding-right: 8px;
            margin-right: 2px;
            border-right: 1px solid var(--fp-border);
        }

        .toolbar button,
        .toolbar select {
            min-height: 32px;
            border: 1px solid var(--fp-border);
            border-radius: 6px;
            background: var(--fp-panel-2);
            color: var(--fp-text);
            padding: 5px 10px;
            cursor: pointer;
        }

        .toolbar button.active {
            outline: 2px solid var(--fp-accent);
            background: #244c72;
        }

        .toolbar button.danger {
            color: #ffd4d8;
        }

        .toolbar .spacer { flex: 1; }

        .status {
            color: var(--fp-muted);
            font-size: 12px;
            white-space: nowrap;
        }

        .main {
            display: grid;
            grid-template-columns: 1fr 300px;
            min-height: 0;
        }

        .canvas-wrap {
            position: relative;
            min-width: 0;
            min-height: 0;
            overflow: hidden;
            background: #191919;
        }

        #viewport {
            width: 100%;
            height: 100%;
            display: block;
            user-select: none;
            touch-action: none;
        }

        .sidebar {
            min-width: 0;
            overflow: auto;
            padding: 12px;
            background: var(--fp-panel);
            border-left: 1px solid var(--fp-border);
        }

        .sidebar h3 {
            margin: 0 0 12px 0;
            font-size: 15px;
        }

        .field {
            display: grid;
            gap: 4px;
            margin-bottom: 10px;
        }

        .field label {
            color: var(--fp-muted);
            font-size: 12px;
        }

        .field input,
        .field select {
            width: 100%;
            min-height: 32px;
            padding: 5px 7px;
            border: 1px solid var(--fp-border);
            border-radius: 5px;
            background: #222;
            color: var(--fp-text);
        }

        .row2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }

        .help {
            margin-top: 12px;
            color: var(--fp-muted);
            font-size: 12px;
            line-height: 1.45;
        }

        .selection-box {
            fill: none;
            stroke: var(--fp-accent);
            stroke-width: 2;
            vector-effect: non-scaling-stroke;
            stroke-dasharray: 7 4;
            pointer-events: none;
        }

        .wall {
            stroke: #ececec;
            stroke-width: 12;
            stroke-linecap: square;
            vector-effect: non-scaling-stroke;
            cursor: pointer;
        }

        .wall.selected {
            stroke: #74b9ff;
        }

        .opening {
            cursor: pointer;
        }

        .opening-gap {
            stroke: #303030;
            stroke-width: 16;
            vector-effect: non-scaling-stroke;
        }

        .opening-line {
            stroke: #d7d7d7;
            stroke-width: 3;
            fill: none;
            vector-effect: non-scaling-stroke;
        }

        .opening.selected .opening-line {
            stroke: #74b9ff;
        }

        .device {
            cursor: pointer;
        }

        .device circle {
            fill: #404040;
            stroke: #dedede;
            stroke-width: 2;
            vector-effect: non-scaling-stroke;
        }

        .device.selected circle {
            stroke: #74b9ff;
            stroke-width: 3;
        }

        .device text,
        .plan-text {
            fill: white;
            font-family: Arial, Helvetica, sans-serif;
            paint-order: stroke;
            stroke: rgba(0,0,0,.35);
            stroke-width: 2px;
        }

        .grid-line {
            stroke: rgba(255,255,255,.09);
            stroke-width: 1;
            vector-effect: non-scaling-stroke;
            pointer-events: none;
        }

        .preview-line {
            stroke: #74b9ff;
            stroke-width: 3;
            stroke-dasharray: 7 5;
            vector-effect: non-scaling-stroke;
            pointer-events: none;
        }

        #viewbar {
            display: none;
            gap: 6px;
            align-items: center;
            justify-content: center;
            padding: 8px;
            background: var(--fp-panel);
            border-top: 1px solid var(--fp-border);
        }

        #viewbar button {
            min-height: 32px;
            border: 1px solid var(--fp-border);
            border-radius: 6px;
            background: var(--fp-panel-2);
            color: var(--fp-text);
            padding: 5px 14px;
            cursor: pointer;
        }

        #app.view-mode .toolbar { display: none; }
        #app.view-mode #viewbar { display: flex; }
        #app.view-mode .main { grid-template-columns: 1fr; }
        #app.view-mode .sidebar { display: none; }

        .modal-backdrop {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: rgba(0,0,0,.62);
            z-index: 1000;
        }

        .modal-backdrop.open { display: flex; }

        .modal {
            width: min(760px, 96vw);
            max-height: min(720px, 90vh);
            display: grid;
            grid-template-rows: auto auto 1fr auto;
            overflow: hidden;
            border: 1px solid var(--fp-border);
            border-radius: 10px;
            background: var(--fp-panel);
            box-shadow: 0 16px 60px rgba(0,0,0,.45);
        }

        .modal h3 {
            margin: 0;
            padding: 14px;
            border-bottom: 1px solid var(--fp-border);
        }

        .modal-search {
            padding: 10px 14px;
            border-bottom: 1px solid var(--fp-border);
        }

        .modal-search input {
            width: 100%;
            min-height: 34px;
            padding: 6px 9px;
            color: var(--fp-text);
            background: #222;
            border: 1px solid var(--fp-border);
            border-radius: 6px;
        }

        .variable-list {
            overflow: auto;
            padding: 6px;
        }

        .variable-row {
            display: grid;
            grid-template-columns: 90px 1fr auto;
            gap: 10px;
            align-items: center;
            padding: 8px 10px;
            border-radius: 6px;
            cursor: pointer;
        }

        .variable-row:hover { background: rgba(255,255,255,.08); }
        .variable-id { color: #9fc7ff; font-family: monospace; }
        .variable-path { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .variable-type { color: var(--fp-muted); font-size: 11px; }

        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 6px;
            padding: 10px 14px;
            border-top: 1px solid var(--fp-border);
        }

        .modal-actions button {
            min-height: 32px;
            border: 1px solid var(--fp-border);
            border-radius: 6px;
            background: var(--fp-panel-2);
            color: var(--fp-text);
            padding: 5px 12px;
            cursor: pointer;
        }

        .runtime-value {
            fill: #d7e9ff !important;
            font-size: 11px;
        }

        @media (max-width: 800px) {
            .main {
                grid-template-columns: 1fr;
                grid-template-rows: 1fr auto;
            }
            .sidebar {
                max-height: 220px;
                border-left: 0;
                border-top: 1px solid var(--fp-border);
            }
        }
    </style>
</head>
<body>
<div id="app">
    <div class="main">
        <div class="canvas-wrap">
            <svg id="viewport" xmlns="http://www.w3.org/2000/svg">
                <g id="scene"></g>
            </svg>
        </div>

        <aside class="sidebar">
            <h3 id="propTitle">Projekteigenschaften</h3>
            <div id="properties"></div>
            <div class="help">
                <b>Bedienung</b><br>
                Wand: Start- und Endpunkt anklicken.<br>
                Tür/Fenster: auf eine Wand klicken.<br>
                Gerät/Text: Position anklicken.<br>
                Auswahl: Element anklicken und ziehen.<br>
                Mausrad: zoomen. Mittlere Maustaste: verschieben.<br>
                Entf: ausgewähltes Element löschen.
            </div>
        </aside>
    </div>
    <div class="toolbar">
        <div class="group">
            <button data-tool="select" class="active">Auswahl</button>
            <button data-tool="wall">Wand</button>
            <button data-tool="door">Tür</button>
            <button data-tool="window">Fenster</button>
            <button data-tool="device">Gerät</button>
            <button data-tool="text">Text</button>
        </div>

        <div class="group">
            <button id="undoBtn" title="Rückgängig">↶</button>
            <button id="redoBtn" title="Wiederholen">↷</button>
            <button id="deleteBtn" class="danger">Löschen</button>
        </div>

        <div class="group">
            <button id="addFloorBtn">+ Etage</button>
            <select id="floorSelect"></select>
        </div>

        <div class="group">
            <button id="fitBtn">Einpassen</button>
            <button id="saveBtn">Speichern</button>
            <button id="finishBtn">Fertig / Bedienen</button>
        </div>

        <div class="spacer"></div>
        <div id="status" class="status">Bereit</div>
    </div>
</div>

<script>
(() => {
    const initial = __INITIAL_PROJECT__;
    const svg = document.getElementById('viewport');
    const scene = document.getElementById('scene');
    const properties = document.getElementById('properties');
    const propTitle = document.getElementById('propTitle');
    const floorSelect = document.getElementById('floorSelect');
    const statusEl = document.getElementById('status');
    const app = document.getElementById('app');
    const variableModal = document.getElementById('variableModal');
    const variableList = document.getElementById('variableList');
    const variableSearch = document.getElementById('variableSearch');

    let state = normalizeProject(initial);
    let variablePickerTarget = null;
    let variableRows = [];
    let tool = 'select';
    let selected = null;
    let wallStart = null;
    let preview = null;
    let drag = null;

    let zoom = 1;
    let panX = 0;
    let panY = 0;

    let history = [];
    let historyIndex = -1;
    let saveTimer = null;
    let dirty = false;

    function uid(prefix) {
        return prefix + '_' + Math.random().toString(36).slice(2, 9) + Date.now().toString(36);
    }

    function normalizeProject(p) {
        const q = (p && typeof p === 'object') ? structuredClone(p) : {};
        // Kein festes Projektformat: der Zeichenbereich entspricht immer
        // dynamisch der verfügbaren HTML-SDK-Fläche.
        delete q.width;
        delete q.height;
        q.grid = Number(q.grid) || 20;
        q.snap = Number.isFinite(Number(q.snap)) ? Number(q.snap) : q.grid;
        q.background = q.background || '#303030';
        q.showGrid = q.showGrid !== false;
        q.mode = q.mode === 'view' ? 'view' : 'edit';
        q.floors = Array.isArray(q.floors) && q.floors.length ? q.floors : [{
            id: 'floor_1',
            name: 'Erdgeschoss',
            walls: [],
            openings: [],
            items: [],
            texts: [],
            furniture: [],
            areas: [],
            trackers: []
        }];
        for (const floor of q.floors) {
            floor.id ||= uid('floor');
            floor.name ||= 'Etage';
            floor.walls = Array.isArray(floor.walls) ? floor.walls : [];
            floor.openings = Array.isArray(floor.openings) ? floor.openings : [];
            floor.items = Array.isArray(floor.items) ? floor.items : [];
            floor.texts = Array.isArray(floor.texts) ? floor.texts : [];
            floor.furniture = Array.isArray(floor.furniture) ? floor.furniture : [];
            floor.areas = Array.isArray(floor.areas) ? floor.areas : [];
            floor.trackers = Array.isArray(floor.trackers) ? floor.trackers : [];
        }
        q.defaultFloor ||= q.floors[0].id;
        q.activeFloor ||= q.defaultFloor;
        if (!q.floors.some(f => f.id === q.activeFloor)) {
            q.activeFloor = q.floors[0].id;
        }
        return q;
    }

    function currentFloor() {
        return state.floors.find(f => f.id === state.activeFloor) || state.floors[0];
    }

    function pushHistory() {
        const snapshot = JSON.stringify(state);
        if (historyIndex >= 0 && history[historyIndex] === snapshot) return;

        history = history.slice(0, historyIndex + 1);
        history.push(snapshot);
        if (history.length > 80) history.shift();
        historyIndex = history.length - 1;
        updateUndoButtons();
    }

    function restoreHistory(index) {
        if (index < 0 || index >= history.length) return;
        historyIndex = index;
        state = normalizeProject(JSON.parse(history[historyIndex]));
        selected = null;
        wallStart = null;
        renderAll();
        markDirty();
        updateUndoButtons();
    }

    function updateUndoButtons() {
        document.getElementById('undoBtn').disabled = historyIndex <= 0;
        document.getElementById('redoBtn').disabled = historyIndex >= history.length - 1;
    }

    function markDirty() {
        dirty = true;
        statusEl.textContent = 'Nicht gespeichert';
        clearTimeout(saveTimer);
        saveTimer = setTimeout(saveProject, 1200);
    }

    function saveProject() {
        clearTimeout(saveTimer);
        saveTimer = null;
        try {
            requestAction('save', JSON.stringify(state));
            dirty = false;
            statusEl.textContent = 'Gespeichert';
        } catch (e) {
            statusEl.textContent = 'Speichern fehlgeschlagen';
            console.error(e);
        }
    }

    function setTool(next) {
        tool = next;
        wallStart = null;
        preview = null;
        document.querySelectorAll('[data-tool]').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.tool === tool);
        });
        render();
    }

    function updateModeUI() {
        const isView = state.mode === 'view';
        app.classList.toggle('view-mode', isView);
        statusEl.textContent = isView ? 'Bedienmodus' : (dirty ? 'Nicht gespeichert' : 'Editor');
    }

    function setMode(mode) {
        state.mode = mode === 'view' ? 'view' : 'edit';
        selected = null;
        wallStart = null;
        preview = null;
        pushHistory();
        saveProject();
        updateModeUI();
        render();
        requestAnimationFrame(fit);
    }

    function snapValue(v) {
        const s = Number(state.snap) || 0;
        return s > 0 ? Math.round(v / s) * s : v;
    }

    function svgPoint(evt) {
        const pt = svg.createSVGPoint();
        pt.x = evt.clientX;
        pt.y = evt.clientY;
        const matrix = scene.getScreenCTM();
        if (!matrix) return {x: 0, y: 0};
        const p = pt.matrixTransform(matrix.inverse());
        return {
            x: snapValue(p.x),
            y: snapValue(p.y)
        };
    }

    function setTransform() {
        scene.setAttribute('transform', `translate(${panX} ${panY}) scale(${zoom})`);
    }

    function visibleWorldBounds(extra = 80) {
        const box = svg.getBoundingClientRect();
        const z = Math.max(0.0001, zoom);
        return {
            minX: (-panX / z) - extra,
            minY: (-panY / z) - extra,
            maxX: ((box.width - panX) / z) + extra,
            maxY: ((box.height - panY) / z) + extra
        };
    }

    function contentBounds() {
        const floor = currentFloor();
        const points = [];

        for (const w of floor.walls) {
            points.push([w.x1, w.y1], [w.x2, w.y2]);
        }

        for (const o of floor.openings) {
            const wall = floor.walls.find(w => w.id === o.wallId);
            if (!wall) continue;
            const g = openingGeometry(wall, o);
            points.push(
                [g.x1, g.y1], [g.x2, g.y2],
                [g.dx, g.dy], [g.wx1, g.wy1], [g.wx2, g.wy2]
            );
        }

        for (const item of floor.items) {
            const r = Number(item.size || 18) + 35;
            points.push([item.x - r, item.y - r], [item.x + r, item.y + r]);
        }

        for (const t of floor.texts) {
            const s = Number(t.size || 18);
            const width = Math.max(40, String(t.text || 'Text').length * s * 0.65);
            points.push([t.x, t.y - s * 1.4], [t.x + width, t.y + s * 0.4]);
        }

        if (!points.length) return null;

        return {
            minX: Math.min(...points.map(p => p[0])),
            minY: Math.min(...points.map(p => p[1])),
            maxX: Math.max(...points.map(p => p[0])),
            maxY: Math.max(...points.map(p => p[1]))
        };
    }

    function fit() {
        const box = svg.getBoundingClientRect();
        if (!box.width || !box.height) return;

        const bounds = contentBounds();
        if (!bounds) {
            zoom = 1;
            panX = 40;
            panY = 40;
            setTransform();
            render();
            return;
        }

        const margin = 60;
        const width = Math.max(1, bounds.maxX - bounds.minX);
        const height = Math.max(1, bounds.maxY - bounds.minY);

        zoom = Math.max(
            0.05,
            Math.min(
                20,
                Math.min(
                    (box.width - margin * 2) / width,
                    (box.height - margin * 2) / height
                )
            )
        );

        panX = (box.width - width * zoom) / 2 - bounds.minX * zoom;
        panY = (box.height - height * zoom) / 2 - bounds.minY * zoom;
        setTransform();
        render();
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function renderFloorSelect() {
        floorSelect.innerHTML = state.floors.map(f =>
            `<option value="${escapeHtml(f.id)}"${f.id === state.activeFloor ? ' selected' : ''}>${escapeHtml(f.name)}</option>`
        ).join('');
    }

    function renderGrid(parts, bounds) {
        if (!state.showGrid || state.grid <= 0) return;

        const g = Number(state.grid);
        const startX = Math.floor(bounds.minX / g) * g;
        const endX = Math.ceil(bounds.maxX / g) * g;
        const startY = Math.floor(bounds.minY / g) * g;
        const endY = Math.ceil(bounds.maxY / g) * g;

        // Sicherheitsgrenze bei sehr weitem Herauszoomen.
        const maxLines = 500;
        let count = 0;

        for (let x = startX; x <= endX && count < maxLines; x += g, count++) {
            parts.push(`<line class="grid-line" x1="${x}" y1="${startY}" x2="${x}" y2="${endY}"/>`);
        }

        count = 0;
        for (let y = startY; y <= endY && count < maxLines; y += g, count++) {
            parts.push(`<line class="grid-line" x1="${startX}" y1="${y}" x2="${endX}" y2="${y}"/>`);
        }
    }

    function iconForKind(kind) {
        const icons = {
            light: '☀',
            switch: '⏻',
            socket: '⌁',
            shutter: '▥',
            temperature: '°',
            humidity: '%',
            motion: '◉',
            window: '▯',
            door: '▭',
            climate: '◎',
            generic: '●'
        };
        return icons[kind] || icons.generic;
    }

    function render() {
        const floor = currentFloor();
        const parts = [];
        const bounds = visibleWorldBounds(120);

        // Der Hintergrund deckt immer nur den aktuell sichtbaren Bereich ab.
        // Es existiert bewusst keine feste Projektbreite oder Projekthöhe.
        parts.push(
            `<rect x="${bounds.minX}" y="${bounds.minY}" ` +
            `width="${bounds.maxX - bounds.minX}" height="${bounds.maxY - bounds.minY}" ` +
            `fill="${escapeHtml(state.background)}"/>`
        );
        renderGrid(parts, bounds);

        for (const w of floor.walls) {
            const sel = selected?.type === 'wall' && selected.id === w.id ? ' selected' : '';
            parts.push(
                `<line class="wall${sel}" data-type="wall" data-id="${w.id}" x1="${w.x1}" y1="${w.y1}" x2="${w.x2}" y2="${w.y2}"/>`
            );
        }

        for (const o of floor.openings) {
            const wall = floor.walls.find(w => w.id === o.wallId);
            if (!wall) continue;
            const geom = openingGeometry(wall, o);
            const sel = selected?.type === 'opening' && selected.id === o.id ? ' selected' : '';
            parts.push(`<g class="opening${sel}" data-type="opening" data-id="${o.id}">`);
            parts.push(`<line class="opening-gap" x1="${geom.x1}" y1="${geom.y1}" x2="${geom.x2}" y2="${geom.y2}"/>`);

            if (o.type === 'door') {
                parts.push(`<line class="opening-line" x1="${geom.x1}" y1="${geom.y1}" x2="${geom.dx}" y2="${geom.dy}"/>`);
                parts.push(`<path class="opening-line" d="M ${geom.x2} ${geom.y2} Q ${geom.cx} ${geom.cy} ${geom.dx} ${geom.dy}"/>`);
            } else {
                parts.push(`<line class="opening-line" x1="${geom.x1}" y1="${geom.y1}" x2="${geom.x2}" y2="${geom.y2}"/>`);
                parts.push(`<line class="opening-line" x1="${geom.wx1}" y1="${geom.wy1}" x2="${geom.wx2}" y2="${geom.wy2}"/>`);
            }
            parts.push(`</g>`);
        }

        for (const item of floor.items) {
            const sel = selected?.type === 'item' && selected.id === item.id ? ' selected' : '';
            const label = item.name || (item.variableID ? '#' + item.variableID : 'Gerät');
            const icon = item.icon || iconForKind(item.kind);
            const valueText = item._valueText ? String(item._valueText) : '';
            parts.push(
                `<g class="device${sel}" data-type="item" data-id="${item.id}" transform="translate(${item.x} ${item.y})">` +
                `<circle r="${item.size || 18}"/>` +
                `<text text-anchor="middle" dominant-baseline="central" font-size="${Math.max(10,(item.size || 18)*0.7)}">${escapeHtml(icon)}</text>` +
                `<text x="0" y="${(item.size || 18)+17}" text-anchor="middle" font-size="13">${escapeHtml(label)}</text>` +
                (valueText ? `<text class="runtime-value" x="0" y="${(item.size || 18)+32}" text-anchor="middle">${escapeHtml(valueText)}</text>` : '') +
                `</g>`
            );
        }

        for (const t of floor.texts) {
            const sel = selected?.type === 'text' && selected.id === t.id;
            parts.push(
                `<text class="plan-text" data-type="text" data-id="${t.id}" x="${t.x}" y="${t.y}" font-size="${t.size || 18}"` +
                `${sel ? ' style="fill:#74b9ff"' : ''}>${escapeHtml(t.text || 'Text')}</text>`
            );
        }

        if (preview && tool === 'wall') {
            parts.push(`<line class="preview-line" x1="${preview.x1}" y1="${preview.y1}" x2="${preview.x2}" y2="${preview.y2}"/>`);
        }

        scene.innerHTML = parts.join('');
        setTransform();
        renderProperties();
        renderFloorSelect();
    }

    function renderAll() {
        render();
        updateUndoButtons();
    }

    function openingGeometry(w, o) {
        const vx = w.x2 - w.x1;
        const vy = w.y2 - w.y1;
        const len = Math.hypot(vx, vy) || 1;
        const ux = vx / len;
        const uy = vy / len;
        const nx = -uy;
        const ny = ux;

        const centerPos = Math.max(0, Math.min(1, Number(o.position ?? .5)));
        const cx = w.x1 + vx * centerPos;
        const cy = w.y1 + vy * centerPos;
        const half = Math.min(Number(o.length || 80) / 2, len / 2);

        const x1 = cx - ux * half;
        const y1 = cy - uy * half;
        const x2 = cx + ux * half;
        const y2 = cy + uy * half;

        const depth = Math.min(Number(o.length || 80), 100);
        const dx = x1 + nx * depth;
        const dy = y1 + ny * depth;

        return {
            x1, y1, x2, y2, cx, cy, dx, dy,
            wx1: x1 + nx * 4,
            wy1: y1 + ny * 4,
            wx2: x2 + nx * 4,
            wy2: y2 + ny * 4
        };
    }

    function nearestWall(p) {
        const floor = currentFloor();
        let best = null;

        for (const w of floor.walls) {
            const vx = w.x2 - w.x1;
            const vy = w.y2 - w.y1;
            const l2 = vx*vx + vy*vy;
            if (!l2) continue;

            let t = ((p.x - w.x1)*vx + (p.y - w.y1)*vy) / l2;
            t = Math.max(0, Math.min(1, t));
            const px = w.x1 + t*vx;
            const py = w.y1 + t*vy;
            const d = Math.hypot(p.x - px, p.y - py);

            if (!best || d < best.distance) {
                best = {wall: w, position: t, distance: d};
            }
        }

        return best;
    }

    function findEntity(type, id) {
        const floor = currentFloor();
        if (type === 'wall') return floor.walls.find(v => v.id === id);
        if (type === 'opening') return floor.openings.find(v => v.id === id);
        if (type === 'item') return floor.items.find(v => v.id === id);
        if (type === 'text') return floor.texts.find(v => v.id === id);
        return null;
    }

    function deleteSelected() {
        if (!selected) return;
        const floor = currentFloor();

        if (selected.type === 'wall') {
            floor.walls = floor.walls.filter(v => v.id !== selected.id);
            floor.openings = floor.openings.filter(v => v.wallId !== selected.id);
        } else if (selected.type === 'opening') {
            floor.openings = floor.openings.filter(v => v.id !== selected.id);
        } else if (selected.type === 'item') {
            floor.items = floor.items.filter(v => v.id !== selected.id);
        } else if (selected.type === 'text') {
            floor.texts = floor.texts.filter(v => v.id !== selected.id);
        }

        selected = null;
        pushHistory();
        markDirty();
        render();
    }

    function renderProperties() {
        const floor = currentFloor();

        if (!selected) {
            propTitle.textContent = 'Projekteigenschaften';
            properties.innerHTML = `
                <div class="field">
                    <label>Etagenname</label>
                    <input data-project="floorName" value="${escapeHtml(floor.name)}">
                </div>
                <div class="field">
                    <label>Zeichenfläche</label>
                    <input value="Dynamisch – passt sich dem Fenster an" disabled>
                </div>
                <div class="row2">
                    <div class="field">
                        <label>Raster</label>
                        <input value="${state.grid}" disabled>
                    </div>
                    <div class="field">
                        <label>Snap</label>
                        <input value="${state.snap}" disabled>
                    </div>
                </div>
                <div class="field">
                    <label>Elemente</label>
                    <input value="${floor.walls.length} Wände, ${floor.openings.length} Öffnungen, ${floor.items.length} Geräte" disabled>
                </div>
            `;
            bindPropertyInputs();
            return;
        }

        const obj = findEntity(selected.type, selected.id);
        if (!obj) {
            selected = null;
            renderProperties();
            return;
        }

        if (selected.type === 'wall') {
            propTitle.textContent = 'Wand';
            properties.innerHTML = `
                <div class="row2">
                    <div class="field"><label>X1</label><input data-field="x1" type="number" value="${obj.x1}"></div>
                    <div class="field"><label>Y1</label><input data-field="y1" type="number" value="${obj.y1}"></div>
                </div>
                <div class="row2">
                    <div class="field"><label>X2</label><input data-field="x2" type="number" value="${obj.x2}"></div>
                    <div class="field"><label>Y2</label><input data-field="y2" type="number" value="${obj.y2}"></div>
                </div>
            `;
        } else if (selected.type === 'opening') {
            propTitle.textContent = obj.type === 'door' ? 'Tür' : 'Fenster';
            properties.innerHTML = `
                <div class="field">
                    <label>Typ</label>
                    <select data-field="type">
                        <option value="door"${obj.type === 'door' ? ' selected' : ''}>Tür</option>
                        <option value="window"${obj.type === 'window' ? ' selected' : ''}>Fenster</option>
                    </select>
                </div>
                <div class="field"><label>Länge</label><input data-field="length" type="number" min="20" value="${obj.length || 80}"></div>
                <div class="field"><label>Position auf Wand (0–1)</label><input data-field="position" type="number" min="0" max="1" step="0.01" value="${obj.position ?? .5}"></div>
                <div class="field"><label>IP-Symcon VariableID</label><input data-field="variableID" type="number" min="0" value="${obj.variableID || 0}"></div>
            `;
        } else if (selected.type === 'item') {
            propTitle.textContent = 'Gerät';
            const kind = obj.kind || 'generic';
            properties.innerHTML = `
                <div class="field"><label>Name</label><input data-field="name" value="${escapeHtml(obj.name || '')}"></div>
                <div class="field">
                    <label>Gerätetyp</label>
                    <select data-field="kind">
                        <option value="generic"${kind === 'generic' ? ' selected' : ''}>Allgemein</option>
                        <option value="light"${kind === 'light' ? ' selected' : ''}>Licht</option>
                        <option value="switch"${kind === 'switch' ? ' selected' : ''}>Schalter</option>
                        <option value="socket"${kind === 'socket' ? ' selected' : ''}>Steckdose</option>
                        <option value="shutter"${kind === 'shutter' ? ' selected' : ''}>Rollladen / Jalousie</option>
                        <option value="temperature"${kind === 'temperature' ? ' selected' : ''}>Temperatur</option>
                        <option value="humidity"${kind === 'humidity' ? ' selected' : ''}>Feuchte</option>
                        <option value="motion"${kind === 'motion' ? ' selected' : ''}>Bewegung / Präsenz</option>
                        <option value="window"${kind === 'window' ? ' selected' : ''}>Fensterkontakt</option>
                        <option value="door"${kind === 'door' ? ' selected' : ''}>Türkontakt</option>
                        <option value="climate"${kind === 'climate' ? ' selected' : ''}>Klima / Heizung</option>
                    </select>
                </div>
                <div class="field">
                    <label>IP-Symcon Variable</label>
                    <input value="${obj.variableID ? '#' + obj.variableID + (obj._variablePath ? ' – ' + escapeHtml(obj._variablePath) : '') : 'nicht zugeordnet'}" disabled>
                    <button id="chooseVariableBtn" type="button">Aus Objektbaum auswählen …</button>
                </div>
                <div class="field"><label>Eigenes Symbol (optional)</label><input data-field="icon" value="${escapeHtml(obj.icon || '')}" placeholder="${escapeHtml(iconForKind(kind))}"></div>
                <div class="row2">
                    <div class="field"><label>X</label><input data-field="x" type="number" value="${obj.x}"></div>
                    <div class="field"><label>Y</label><input data-field="y" type="number" value="${obj.y}"></div>
                </div>
                <div class="field"><label>Größe</label><input data-field="size" type="number" min="8" max="80" value="${obj.size || 18}"></div>
            `;
        } else if (selected.type === 'text') {
            propTitle.textContent = 'Text';
            properties.innerHTML = `
                <div class="field"><label>Text</label><input data-field="text" value="${escapeHtml(obj.text || 'Text')}"></div>
                <div class="row2">
                    <div class="field"><label>X</label><input data-field="x" type="number" value="${obj.x}"></div>
                    <div class="field"><label>Y</label><input data-field="y" type="number" value="${obj.y}"></div>
                </div>
                <div class="field"><label>Schriftgröße</label><input data-field="size" type="number" min="8" max="100" value="${obj.size || 18}"></div>
            `;
        }

        bindPropertyInputs();
    }

    function bindPropertyInputs() {
        properties.querySelectorAll('[data-field]').forEach(input => {
            input.addEventListener('change', () => {
                if (!selected) return;
                const obj = findEntity(selected.type, selected.id);
                if (!obj) return;

                let value = input.value;
                if (input.type === 'number') value = Number(value);
                obj[input.dataset.field] = value;

                pushHistory();
                markDirty();
                render();
            });
        });

        const chooseVariableBtn = document.getElementById('chooseVariableBtn');
        if (chooseVariableBtn && selected?.type === 'item') {
            chooseVariableBtn.addEventListener('click', () => {
                variablePickerTarget = {floorId: state.activeFloor, itemId: selected.id};
                statusEl.textContent = 'Objektbaum wird geladen …';
                requestAction('getVariableTree', '');
            });
        }

        properties.querySelectorAll('[data-project]').forEach(input => {
            input.addEventListener('change', () => {
                if (input.dataset.project === 'floorName') {
                    currentFloor().name = input.value.trim() || 'Etage';
                }
                pushHistory();
                markDirty();
                render();
            });
        });
    }

    document.querySelectorAll('[data-tool]').forEach(btn => {
        btn.addEventListener('click', () => setTool(btn.dataset.tool));
    });

    document.getElementById('deleteBtn').addEventListener('click', deleteSelected);
    document.getElementById('undoBtn').addEventListener('click', () => restoreHistory(historyIndex - 1));
    document.getElementById('redoBtn').addEventListener('click', () => restoreHistory(historyIndex + 1));
    document.getElementById('fitBtn').addEventListener('click', fit);
    document.getElementById('saveBtn').addEventListener('click', saveProject);
    document.getElementById('finishBtn').addEventListener('click', () => setMode('view'));
    document.getElementById('editBtn').addEventListener('click', () => setMode('edit'));

    document.getElementById('addFloorBtn').addEventListener('click', () => {
        const name = prompt('Name der neuen Etage:', 'Obergeschoss');
        if (name === null) return;
        const floor = {
            id: uid('floor'),
            name: name.trim() || 'Etage',
            walls: [],
            openings: [],
            items: [],
            texts: [],
            furniture: [],
            areas: [],
            trackers: []
        };
        state.floors.push(floor);
        state.activeFloor = floor.id;
        selected = null;
        pushHistory();
        markDirty();
        render();
        fit();
    });

    floorSelect.addEventListener('change', () => {
        state.activeFloor = floorSelect.value;
        selected = null;
        wallStart = null;
        render();
    });

    svg.addEventListener('pointerdown', evt => {
        if (evt.button === 1) {
            drag = {mode: 'pan', x: evt.clientX, y: evt.clientY, panX, panY};
            svg.setPointerCapture(evt.pointerId);
            return;
        }

        if (evt.button !== 0) return;

        const target = evt.target.closest('[data-type]');
        const p = svgPoint(evt);
        const floor = currentFloor();

        if (state.mode === 'view') {
            if (target && target.dataset.type === 'item') {
                requestAction('operate', JSON.stringify({
                    floorId: state.activeFloor,
                    itemId: target.dataset.id
                }));
            }
            return;
        }

        if (tool === 'select') {
            if (target) {
                selected = {type: target.dataset.type, id: target.dataset.id};
                const obj = findEntity(selected.type, selected.id);
                drag = {
                    mode: 'move',
                    type: selected.type,
                    id: selected.id,
                    start: p,
                    original: obj ? structuredClone(obj) : null
                };
                svg.setPointerCapture(evt.pointerId);
            } else {
                selected = null;
            }
            render();
            return;
        }

        if (tool === 'wall') {
            if (!wallStart) {
                wallStart = p;
                preview = {x1: p.x, y1: p.y, x2: p.x, y2: p.y};
            } else {
                if (wallStart.x !== p.x || wallStart.y !== p.y) {
                    const w = {id: uid('wall'), x1: wallStart.x, y1: wallStart.y, x2: p.x, y2: p.y};
                    floor.walls.push(w);
                    selected = {type: 'wall', id: w.id};
                    wallStart = p;
                    preview = {x1: p.x, y1: p.y, x2: p.x, y2: p.y};
                    pushHistory();
                    markDirty();
                }
            }
            render();
            return;
        }

        if (tool === 'door' || tool === 'window') {
            const near = nearestWall(p);
            if (!near || near.distance > 45 / Math.max(.2, zoom)) {
                statusEl.textContent = 'Bitte näher an eine Wand klicken';
                return;
            }

            const o = {
                id: uid('opening'),
                type: tool,
                wallId: near.wall.id,
                position: near.position,
                length: tool === 'door' ? 80 : 120,
                variableID: 0
            };
            floor.openings.push(o);
            selected = {type: 'opening', id: o.id};
            pushHistory();
            markDirty();
            setTool('select');
            render();
            return;
        }

        if (tool === 'device') {
            const item = {
                id: uid('item'),
                x: p.x,
                y: p.y,
                name: 'Gerät',
                variableID: 0,
                icon: '',
                size: 18,
                kind: 'generic'
            };
            floor.items.push(item);
            selected = {type: 'item', id: item.id};
            pushHistory();
            markDirty();
            setTool('select');
            render();
            return;
        }

        if (tool === 'text') {
            const t = {
                id: uid('text'),
                x: p.x,
                y: p.y,
                text: 'Text',
                size: 18
            };
            floor.texts.push(t);
            selected = {type: 'text', id: t.id};
            pushHistory();
            markDirty();
            setTool('select');
            render();
        }
    });

    svg.addEventListener('pointermove', evt => {
        const p = svgPoint(evt);

        if (tool === 'wall' && wallStart && !drag) {
            preview = {x1: wallStart.x, y1: wallStart.y, x2: p.x, y2: p.y};
            render();
            return;
        }

        if (!drag) return;

        if (drag.mode === 'pan') {
            panX = drag.panX + (evt.clientX - drag.x);
            panY = drag.panY + (evt.clientY - drag.y);
            setTransform();
            render();
            return;
        }

        if (drag.mode === 'move' && drag.original) {
            const obj = findEntity(drag.type, drag.id);
            if (!obj) return;

            const dx = p.x - drag.start.x;
            const dy = p.y - drag.start.y;

            if (drag.type === 'wall') {
                obj.x1 = snapValue(drag.original.x1 + dx);
                obj.y1 = snapValue(drag.original.y1 + dy);
                obj.x2 = snapValue(drag.original.x2 + dx);
                obj.y2 = snapValue(drag.original.y2 + dy);
            } else if (drag.type === 'item' || drag.type === 'text') {
                obj.x = snapValue(drag.original.x + dx);
                obj.y = snapValue(drag.original.y + dy);
            }
            render();
        }
    });

    svg.addEventListener('pointerup', evt => {
        if (!drag) return;

        if (drag.mode === 'move') {
            pushHistory();
            markDirty();
        }

        try { svg.releasePointerCapture(evt.pointerId); } catch (_) {}
        drag = null;
    });

    svg.addEventListener('wheel', evt => {
        evt.preventDefault();

        const rect = svg.getBoundingClientRect();
        const sx = evt.clientX - rect.left;
        const sy = evt.clientY - rect.top;
        const oldZoom = zoom;
        const factor = evt.deltaY < 0 ? 1.12 : 0.89;
        zoom = Math.max(.15, Math.min(8, zoom * factor));

        const wx = (sx - panX) / oldZoom;
        const wy = (sy - panY) / oldZoom;

        panX = sx - wx * zoom;
        panY = sy - wy * zoom;
        setTransform();
        render();
    }, {passive: false});

    window.addEventListener('keydown', evt => {
        if (evt.target instanceof HTMLInputElement || evt.target instanceof HTMLSelectElement) {
            return;
        }

        if (evt.key === 'Delete' || evt.key === 'Backspace') {
            evt.preventDefault();
            deleteSelected();
        }

        if (evt.key === 'Escape') {
            wallStart = null;
            preview = null;
            selected = null;
            setTool('select');
        }

        if ((evt.ctrlKey || evt.metaKey) && evt.key.toLowerCase() === 'z') {
            evt.preventDefault();
            restoreHistory(historyIndex - 1);
        }

        if ((evt.ctrlKey || evt.metaKey) && evt.key.toLowerCase() === 'y') {
            evt.preventDefault();
            restoreHistory(historyIndex + 1);
        }
    });

    function renderVariableRows(filter = '') {
        const needle = String(filter || '').trim().toLowerCase();
        const rows = variableRows.filter(v => {
            if (!needle) return true;
            return String(v.id).includes(needle) ||
                String(v.name || '').toLowerCase().includes(needle) ||
                String(v.path || '').toLowerCase().includes(needle);
        });

        variableList.innerHTML = rows.map(v => `
            <div class="variable-row" data-variable-id="${v.id}">
                <div class="variable-id">#${v.id}</div>
                <div class="variable-path">${escapeHtml(v.path || v.name || '')}</div>
                <div class="variable-type">${escapeHtml(v.type || '')}</div>
            </div>
        `).join('') || '<div class="help">Keine passende Variable gefunden.</div>';

        variableList.querySelectorAll('[data-variable-id]').forEach(row => {
            row.addEventListener('click', () => assignVariable(Number(row.dataset.variableId)));
        });
    }

    function assignVariable(variableID) {
        if (!variablePickerTarget) return;
        const floor = state.floors.find(f => f.id === variablePickerTarget.floorId);
        const item = floor?.items.find(i => i.id === variablePickerTarget.itemId);
        if (!item) return;

        item.variableID = Number(variableID) || 0;
        const row = variableRows.find(v => Number(v.id) === item.variableID);
        item._variablePath = row?.path || '';
        item._valueText = row?.valueText || '';

        variableModal.classList.remove('open');
        pushHistory();
        markDirty();
        render();
    }

    variableSearch.addEventListener('input', () => renderVariableRows(variableSearch.value));
    document.getElementById('variableCloseBtn').addEventListener('click', () => variableModal.classList.remove('open'));
    document.getElementById('variableClearBtn').addEventListener('click', () => assignVariable(0));
    variableModal.addEventListener('click', evt => {
        if (evt.target === variableModal) variableModal.classList.remove('open');
    });

    window.handleMessage = message => {
        try {
            const data = typeof message === 'string' ? JSON.parse(message) : message;
            if (data?.type === 'project' && data.project) {
                state = normalizeProject(data.project);
                selected = null;
                wallStart = null;
                history = [];
                historyIndex = -1;
                pushHistory();
                updateModeUI();
                renderAll();
                fit();
            } else if (data?.type === 'variableTree' && Array.isArray(data.variables)) {
                variableRows = data.variables;
                variableSearch.value = '';
                renderVariableRows('');
                variableModal.classList.add('open');
                variableSearch.focus();
                statusEl.textContent = 'Variable auswählen';
            } else if (data?.type === 'runtimeValue') {
                const floor = state.floors.find(f => f.id === data.floorId);
                const item = floor?.items.find(i => i.id === data.itemId);
                if (item) {
                    item._valueText = data.valueText || '';
                    render();
                }
            }
        } catch (e) {
            console.error('handleMessage', e);
        }
    };

    const resizeObserver = new ResizeObserver(() => {
        // Keine Projektgröße ändern – lediglich den dynamischen sichtbaren
        // Bereich neu zeichnen.
        render();
    });
    resizeObserver.observe(svg);

    pushHistory();
    updateModeUI();
    renderAll();
    requestAnimationFrame(fit);
})();
</script>
</body>
</html>
HTML;

        return str_replace('__INITIAL_PROJECT__', $initial, $html);
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'save':
                if (!is_string($Value)) {
                    throw new InvalidArgumentException('Floorplan-Daten müssen als JSON-String übergeben werden.');
                }

                $project = $this->DecodeAndValidateProject($Value);
                $json = json_encode(
                    $project,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                );

                if ($json === false) {
                    throw new RuntimeException('Floorplan konnte nicht serialisiert werden.');
                }

                $this->WriteAttributeString(self::ATTRIBUTE_DATA, $json);
                $this->ReloadForm();
                break;

            case 'getVariableTree':
                $message = json_encode(
                    [
                        'type'      => 'variableTree',
                        'variables' => $this->BuildVariableList()
                    ],
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                );
                if ($message !== false) {
                    $this->UpdateVisualizationValue($message);
                }
                break;

            case 'operate':
                if (!is_string($Value)) {
                    throw new InvalidArgumentException('Ungültige Bedienanforderung.');
                }
                $request = json_decode($Value, true);
                if (!is_array($request)) {
                    throw new InvalidArgumentException('Ungültige Bedienanforderung.');
                }
                $this->OperateItem(
                    (string) ($request['floorId'] ?? ''),
                    (string) ($request['itemId'] ?? '')
                );
                break;

            default:
                throw new InvalidArgumentException('Unbekannte Aktion: ' . $Ident);
        }
    }

    public function GetFloorplanJSON(): string
    {
        $project = $this->GetProject();

        $json = json_encode(
            $project,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($json === false) {
            throw new RuntimeException('Floorplan konnte nicht serialisiert werden.');
        }

        return $json;
    }

    public function SetFloorplanJSON(string $JSON): void
    {
        $project = $this->DecodeAndValidateProject($JSON);

        $json = json_encode(
            $project,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($json === false) {
            throw new RuntimeException('Floorplan konnte nicht serialisiert werden.');
        }

        $this->WriteAttributeString(self::ATTRIBUTE_DATA, $json);
        $this->UpdateVisualizationValue(
            json_encode(
                ['type' => 'project', 'project' => $project],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            )
        );
        $this->ReloadForm();
    }

    public function ResetFloorplan(): void
    {
        $project = $this->CreateDefaultProject();

        $json = json_encode(
            $project,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($json === false) {
            throw new RuntimeException('Floorplan konnte nicht serialisiert werden.');
        }

        $this->WriteAttributeString(self::ATTRIBUTE_DATA, $json);

        $message = json_encode(
            ['type' => 'project', 'project' => $project],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($message !== false) {
            $this->UpdateVisualizationValue($message);
        }

        $this->ReloadForm();
    }

    public function SyncProjectSettings(): void
    {
        $project = $this->GetProject();

        $project['grid'] = max(5, $this->ReadPropertyInteger('GridSize'));
        $project['snap'] = max(0, $this->ReadPropertyInteger('SnapSize'));
        $project['background'] = $this->ReadPropertyString('BackgroundColor');
        $project['showGrid'] = $this->ReadPropertyBoolean('ShowGrid');

        $json = json_encode(
            $project,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($json === false) {
            throw new RuntimeException('Floorplan konnte nicht serialisiert werden.');
        }

        $this->WriteAttributeString(self::ATTRIBUTE_DATA, $json);

        $message = json_encode(
            ['type' => 'project', 'project' => $project],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($message !== false) {
            $this->UpdateVisualizationValue($message);
        }

        $this->ReloadForm();
    }

    private function GetProject(): array
    {
        $raw = $this->ReadAttributeString(self::ATTRIBUTE_DATA);

        if ($raw === '') {
            return $this->CreateDefaultProject();
        }

        try {
            return $this->DecodeAndValidateProject($raw);
        } catch (Throwable $e) {
            $this->SendDebug('Floorplan', 'Ungültige gespeicherte Daten: ' . $e->getMessage(), 0);
            return $this->CreateDefaultProject();
        }
    }

    private function DecodeAndValidateProject(string $JSON): array
    {
        try {
            $data = json_decode($JSON, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Ungültiges JSON: ' . $e->getMessage());
        }

        if (!is_array($data)) {
            throw new InvalidArgumentException('Floorplan muss ein JSON-Objekt sein.');
        }

        // Alte Projekte dürfen width/height enthalten. Diese Werte werden
        // ab dieser Version bewusst ignoriert, da die Zeichenfläche dynamisch ist.
        unset($data['width'], $data['height']);
        $data['grid'] = max(5, (int) ($data['grid'] ?? 20));
        $data['snap'] = max(0, (int) ($data['snap'] ?? $data['grid']));
        $data['background'] = (string) ($data['background'] ?? '#303030');
        $data['showGrid'] = (bool) ($data['showGrid'] ?? true);
        $data['mode'] = (($data['mode'] ?? 'edit') === 'view') ? 'view' : 'edit';

        if (!isset($data['floors']) || !is_array($data['floors']) || count($data['floors']) === 0) {
            $data['floors'] = [$this->CreateDefaultFloor()];
        }

        foreach ($data['floors'] as $index => $floor) {
            if (!is_array($floor)) {
                $floor = [];
            }

            $floor['id'] = (string) ($floor['id'] ?? ('floor_' . ($index + 1)));
            $floor['name'] = (string) ($floor['name'] ?? ('Etage ' . ($index + 1)));

            foreach (['walls', 'openings', 'items', 'texts', 'furniture', 'areas', 'trackers'] as $key) {
                if (!isset($floor[$key]) || !is_array($floor[$key])) {
                    $floor[$key] = [];
                }
            }

            foreach ($floor['items'] as $itemIndex => $item) {
                if (!is_array($item)) {
                    continue;
                }
                foreach (array_keys($item) as $itemKey) {
                    if (str_starts_with((string) $itemKey, '_')) {
                        unset($item[$itemKey]);
                    }
                }
                $floor['items'][$itemIndex] = $item;
            }

            $data['floors'][$index] = $floor;
        }

        $floorIDs = array_map(
            static fn(array $floor): string => (string) $floor['id'],
            $data['floors']
        );

        $data['defaultFloor'] = (string) ($data['defaultFloor'] ?? $floorIDs[0]);
        if (!in_array($data['defaultFloor'], $floorIDs, true)) {
            $data['defaultFloor'] = $floorIDs[0];
        }

        $data['activeFloor'] = (string) ($data['activeFloor'] ?? $data['defaultFloor']);
        if (!in_array($data['activeFloor'], $floorIDs, true)) {
            $data['activeFloor'] = $data['defaultFloor'];
        }

        return $data;
    }

    private function CreateDefaultProject(): array
    {
        return [
            'type'         => 'easy-floorplan-compatible',
            'version'      => 1,
            'grid'         => max(5, $this->ReadPropertyInteger('GridSize')),
            'snap'         => max(0, $this->ReadPropertyInteger('SnapSize')),
            'background'   => $this->ReadPropertyString('BackgroundColor'),
            'showGrid'     => $this->ReadPropertyBoolean('ShowGrid'),
            'mode'         => 'edit',
            'defaultFloor' => 'floor_1',
            'activeFloor'  => 'floor_1',
            'floors'       => [
                $this->CreateDefaultFloor()
            ]
        ];
    }

    private function CreateDefaultFloor(): array
    {
        return [
            'id'        => 'floor_1',
            'name'      => 'Erdgeschoss',
            'walls'     => [],
            'openings'  => [],
            'items'     => [],
            'texts'     => [],
            'furniture' => [],
            'areas'     => [],
            'trackers'  => []
        ];
    }

    private function AddRuntimeValues(array $Project): array
    {
        if (!isset($Project['floors']) || !is_array($Project['floors'])) {
            return $Project;
        }

        foreach ($Project['floors'] as $floorIndex => $floor) {
            if (!isset($floor['items']) || !is_array($floor['items'])) {
                continue;
            }

            foreach ($floor['items'] as $itemIndex => $item) {
                $variableID = (int) ($item['variableID'] ?? 0);
                if ($variableID <= 0 || !IPS_VariableExists($variableID)) {
                    continue;
                }

                try {
                    $variable = IPS_GetVariable($variableID);
                    $Project['floors'][$floorIndex]['items'][$itemIndex]['_variableType'] = (int) $variable['VariableType'];
                    $Project['floors'][$floorIndex]['items'][$itemIndex]['_variablePath'] = $this->GetObjectPath($variableID);
                    $Project['floors'][$floorIndex]['items'][$itemIndex]['_valueText'] = (string) GetValueFormatted($variableID);
                } catch (Throwable $e) {
                    $this->SendDebug('RuntimeValue', $e->getMessage(), 0);
                }
            }
        }

        return $Project;
    }

    private function BuildVariableList(): array
    {
        $result = [];
        $this->CollectVariables(0, '', $result);

        usort(
            $result,
            static fn(array $a, array $b): int => strnatcasecmp((string) $a['path'], (string) $b['path'])
        );

        return $result;
    }

    private function CollectVariables(int $ParentID, string $ParentPath, array &$Result): void
    {
        foreach (IPS_GetChildrenIDs($ParentID) as $objectID) {
            $object = IPS_GetObject($objectID);
            $name = IPS_GetName($objectID);
            $path = ($ParentPath === '') ? $name : ($ParentPath . ' / ' . $name);

            if ((int) $object['ObjectType'] === 2 && IPS_VariableExists($objectID)) {
                $variable = IPS_GetVariable($objectID);
                $typeNames = ['Boolean', 'Integer', 'Float', 'String'];
                $variableType = (int) $variable['VariableType'];

                $valueText = '';
                try {
                    $valueText = (string) GetValueFormatted($objectID);
                } catch (Throwable) {
                    $valueText = '';
                }

                $Result[] = [
                    'id'        => $objectID,
                    'name'      => $name,
                    'path'      => $path,
                    'type'      => $typeNames[$variableType] ?? ('Typ ' . $variableType),
                    'valueText' => $valueText
                ];
            }

            if (in_array((int) $object['ObjectType'], [0, 1], true)) {
                $this->CollectVariables($objectID, $path, $Result);
            }
        }
    }

    private function GetObjectPath(int $ObjectID): string
    {
        $parts = [];
        $current = $ObjectID;

        while ($current > 0 && IPS_ObjectExists($current)) {
            array_unshift($parts, IPS_GetName($current));
            $object = IPS_GetObject($current);
            $current = (int) ($object['ParentID'] ?? 0);
        }

        return implode(' / ', $parts);
    }

    private function OperateItem(string $FloorID, string $ItemID): void
    {
        $project = $this->GetProject();

        foreach ($project['floors'] as $floor) {
            if ((string) ($floor['id'] ?? '') !== $FloorID) {
                continue;
            }

            foreach ($floor['items'] as $item) {
                if ((string) ($item['id'] ?? '') !== $ItemID) {
                    continue;
                }

                $variableID = (int) ($item['variableID'] ?? 0);
                if ($variableID <= 0 || !IPS_VariableExists($variableID)) {
                    return;
                }

                $variable = IPS_GetVariable($variableID);
                $variableType = (int) $variable['VariableType'];

                // In der ersten Bedienversion werden Boolean-Variablen direkt
                // umgeschaltet. Numerische Werte werden zunächst nur angezeigt.
                if ($variableType === 0) {
                    $newValue = !GetValueBoolean($variableID);
                    RequestAction($variableID, $newValue);

                    $message = json_encode(
                        [
                            'type'      => 'runtimeValue',
                            'floorId'   => $FloorID,
                            'itemId'    => $ItemID,
                            'valueText' => (string) GetValueFormatted($variableID)
                        ],
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                    );
                    if ($message !== false) {
                        $this->UpdateVisualizationValue($message);
                    }
                }

                return;
            }
        }
    }

    private function CountElements(array $Project): array
    {
        $counts = [
            'floors'   => 0,
            'walls'    => 0,
            'openings' => 0,
            'items'    => 0,
            'texts'    => 0
        ];

        $floors = $Project['floors'] ?? [];
        if (!is_array($floors)) {
            return $counts;
        }

        $counts['floors'] = count($floors);

        foreach ($floors as $floor) {
            if (!is_array($floor)) {
                continue;
            }

            foreach (['walls', 'openings', 'items', 'texts'] as $key) {
                if (isset($floor[$key]) && is_array($floor[$key])) {
                    $counts[$key] += count($floor[$key]);
                }
            }
        }

        return $counts;
    }
}
