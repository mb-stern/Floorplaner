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
        $this->RegisterRuntimeVariableMessages();

        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->ReloadHtml();
        }
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message !== VM_UPDATE || !IPS_VariableExists($SenderID)) {
            return;
        }

        try {
            $meta = $this->GetVariableRuntimeMeta($SenderID);
            $payload = json_encode(
                [
                    'type'       => 'variableUpdate',
                    'variableID' => $SenderID,
                    'meta'       => $meta
                ],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );

            if ($payload !== false) {
                // Nur den Zustand der betroffenen Variable übertragen.
                // KEIN komplettes Projekt neu laden -> Etage und Ansicht bleiben unverändert.
                $this->UpdateVisualizationValue($payload);
            }
        } catch (Throwable $e) {
            $this->SendDebug('VariableUpdate', $e->getMessage(), 0);
        }
    }

    public function ReloadHtml(): void
    {
        $this->UpdateVisualizationValue(
            json_encode(
                ['command' => 'reloadHtml'],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )
        );
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
                'caption' => 'Basis: Easy Floorplan (MIT).'
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
         * Raster- und Anzeigeeinstellungen gehören zum gespeicherten Floorplan.
         * Sie dürfen beim Laden der HTML-SDK-Kachel nicht mehr durch die alten
         * Modul-Properties überschrieben werden, sonst springen Rastergröße und
         * Raster-An/Aus nach jedem Reload wieder auf die Standardwerte zurück.
         */
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
            --fp-bg: transparent;
            --fp-panel: rgba(38,38,38,.96);
            --fp-panel-2: rgba(54,54,54,.96);
            --fp-border: rgba(255,255,255,.16);
            --fp-text: #f2f2f2;
            --fp-muted: #b8b8b8;
            --fp-grid: rgba(255,255,255,.14);
            --fp-accent: #4da3ff;
            --fp-danger: #e35d6a;
        }

        html[data-theme="light"] {
            --fp-bg: transparent;
            --fp-panel: rgba(232,232,232,.98);
            --fp-panel-2: rgba(218,218,218,.98);
            --fp-border: rgba(0,0,0,.34);
            --fp-text: #111111;
            --fp-muted: #444444;
            --fp-grid: rgba(0,0,0,.24);
            --fp-accent: #1769aa;
        }

        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            background: transparent !important;
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
            position: relative;
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
            background: color-mix(in srgb, var(--fp-accent) 30%, var(--fp-panel-2));
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
            background: transparent;
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
            background: var(--fp-panel-2);
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

        /* Größere Trefferfläche nur für die Öffnung selbst.
           Die sichtbaren Resize-Punkte bleiben exakt bei r=2.8. */
        .opening-hit {
            stroke: transparent;
            stroke-width: 22;
            fill: none;
            vector-effect: non-scaling-stroke;
            pointer-events: stroke;
            cursor: move;
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

        .opening-state-open {
            stroke: #74d680;
        }

        .opening-shutter {
            stroke: #b8c4d8;
            stroke-width: 5;
            vector-effect: non-scaling-stroke;
            stroke-linecap: butt;
        }

        .opening-shutter-slat {
            stroke: #8695aa;
            stroke-width: 1.4;
            vector-effect: non-scaling-stroke;
        }

        .furniture {
            cursor: move;
        }

        .furniture-shape {
            fill: rgba(150, 160, 175, .18);
            stroke: #9ca9ba;
            stroke-width: 2;
            vector-effect: non-scaling-stroke;
        }

        .furniture.selected .furniture-shape {
            stroke: #74b9ff;
            stroke-width: 3;
        }

        .furniture-label {
            fill: var(--fp-text);
            font-size: 11px;
            text-anchor: middle;
            pointer-events: none;
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

        .device.active-light circle {
            fill: #5b5422;
            stroke: #ffe66d;
            filter: drop-shadow(0 0 7px #ffe66d);
        }

        .device.inactive-light {
            opacity: .72;
        }

        .resize-handle {
            fill: #ffffff;
            stroke: #74b9ff;
            stroke-width: 0.4;
            vector-effect: non-scaling-stroke;
            cursor: nwse-resize;
            pointer-events: all;
        }

        .device .resize-handle {
            fill: #ffffff;
            stroke: #74b9ff;
            stroke-width: 0.4;
        }

        .rotate-handle-line {
            stroke: #74b9ff;
            stroke-width: 0.6;
            vector-effect: non-scaling-stroke;
            pointer-events: none;
        }

        .rotate-handle {
            fill: #ffffff;
            stroke: #74b9ff;
            stroke-width: 0.6;
            vector-effect: non-scaling-stroke;
            cursor: grab;
            pointer-events: all;
        }

        .rotate-handle:active {
            cursor: grabbing;
        }

        .check {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            width: auto;
            font-size: 12px;
            line-height: 1.2;
            cursor: pointer;
        }

        .check input[type="checkbox"] {
            width: 13px !important;
            height: 13px !important;
            min-width: 13px !important;
            max-width: 13px !important;
            margin: 0;
            padding: 0;
            flex: 0 0 13px;
        }

        .device-label {
            pointer-events: none;
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
            position: absolute;
            left: 50%;
            bottom: 10px;
            transform: translateX(-50%);
            z-index: 50;
            pointer-events: auto;
            gap: 6px;
            align-items: center;
        }

        #viewbar select {
            height: 36px;
            max-width: 150px;
            padding: 0 26px 0 9px;
            border: 1px solid var(--fp-border);
            border-radius: 6px;
            background: var(--fp-panel-2);
            color: var(--fp-text);
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(0,0,0,.35);
        }

        #viewbar button {
            width: 36px;
            height: 36px;
            min-width: 36px;
            min-height: 30px;
            padding: 0;
            border: 1px solid var(--fp-border);
            border-radius: 6px;
            background: var(--fp-panel-2);
            color: var(--fp-text);
            font-size: 20px;
            line-height: 34px;
            text-align: center;
            cursor: pointer;
            pointer-events: auto;
            touch-action: manipulation;
            box-shadow: 0 2px 8px rgba(0,0,0,.35);
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
            background: var(--fp-panel-2);
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

        .variable-row:hover { background: color-mix(in srgb, var(--fp-text) 8%, transparent); }
        .variable-id { color: #9fc7ff; font-family: monospace; }
        .variable-path { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .variable-type { color: var(--fp-muted); font-size: 11px; }

        .object-tree { padding: 4px 2px 10px; }
        .tree-node { user-select: none; }
        .tree-row {
            min-height: 31px;
            display: grid;
            grid-template-columns: 22px 24px minmax(120px, 1fr) auto auto;
            gap: 5px;
            align-items: center;
            padding: 3px 8px 3px calc(8px + (var(--depth, 0) * 18px));
            border-radius: 5px;
        }
        .tree-row:hover { background: color-mix(in srgb, var(--fp-text) 7%, transparent); }
        .tree-toggle { width: 22px; text-align: center; color: var(--fp-muted); cursor: pointer; }
        .tree-icon { text-align: center; }
        .tree-name { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .tree-id { color: #9fc7ff; font-family: monospace; font-size: 11px; }
        .tree-value { color: var(--fp-muted); font-size: 11px; max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .tree-row.variable { cursor: pointer; }
        .tree-row.variable.selected-variable { outline: 1px solid #74b9ff; background: rgba(116,185,255,.12); }
        .tree-children.collapsed { display: none; }
        .tree-empty { padding: 16px; color: var(--fp-muted); text-align: center; }
        .variable-select-field { cursor: pointer !important; caret-color: transparent; }
        .variable-select-field:hover { outline: 1px solid #74b9ff; }

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

        .device-value-box {
            fill: rgba(255,255,255,.08);
            stroke: currentColor;
            stroke-width: 0.8;
            vector-effect: non-scaling-stroke;
        }

        .runtime-value {
            fill: #d7e9ff !important;
        }

        .control-modal {
            width: max-content;
            min-width: 0;
            max-width: 92vw;
            max-height: min(620px, 86vh);
            display: grid;
            grid-template-rows: auto 1fr auto;
            overflow: hidden;
            border: 1px solid var(--fp-border);
            border-radius: 10px;
            background: var(--fp-panel);
            box-shadow: 0 16px 60px rgba(0,0,0,.45);
        }

        .control-modal h3 {
            margin: 0;
            padding: 12px 14px;
            border-bottom: 1px solid var(--fp-border);
        }

        .control-body {
            padding: 10px 12px;
            overflow: visible;
            width: max-content;
            max-width: calc(92vw - 24px);
        }

        .control-associations {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 4px;
            margin: 0;
            width: max-content;
            max-width: 100%;
        }

        .control-associations button,
        .control-actions button,
        #controlRangeApply {
            min-height: 36px;
            border: 1px solid var(--fp-border);
            border-radius: 6px;
            background: var(--fp-panel-2);
            color: var(--fp-text);
            padding: 6px 10px;
            cursor: pointer;
        }

        .control-associations button {
            width: auto;
            min-width: 0;
            max-width: 100%;
            min-height: 28px;
            padding: 4px 12px;
            white-space: nowrap;
            align-self: flex-start;
        }

        .control-associations button.current {
            border-color: #74b9ff;
            box-shadow: inset 0 0 0 1px #74b9ff;
        }

        .control-range {
            display: grid;
            gap: 8px;
        }

        .control-range input[type="range"] {
            width: 100%;
        }

        .control-range-value {
            text-align: center;
            font-size: 18px;
            font-weight: 600;
        }

        .control-actions {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            padding: 10px 14px;
            border-top: 1px solid var(--fp-border);
        }

        .profile-hint {
            color: var(--fp-muted);
            font-size: 11px;
            line-height: 1.35;
            margin-top: 8px;
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
            .icon-picker {
            position: absolute;
            z-index: 90;
            display: none;
            grid-template-columns: repeat(6, minmax(34px, auto));
            gap: 5px;
            padding: 8px;
            border: 1px solid var(--fp-border);
            border-radius: 8px;
            background: var(--fp-panel);
            box-shadow: 0 4px 14px rgba(0,0,0,.28);
            max-width: min(320px, calc(100vw - 24px));
            max-height: min(300px, calc(100vh - 24px));
            overflow: auto;
        }

        .icon-picker button {
            min-width: 34px;
            min-height: 32px;
            padding: 4px 6px;
            font-size: 18px;
            line-height: 1;
            cursor: pointer;
        }

        .icon-input-clickable {
            cursor: pointer;
            user-select: none;
        }

        .icon-input-clickable:hover {
            outline: 1px solid #74b9ff;
        }


        .device-glyph { color: currentColor; pointer-events: none; }
        .device-glyph * { vector-effect: non-scaling-stroke; }



        .view-mode .grid-editor-controls {
            display: none !important;
        }


        html,
        body,
        #app,
        .main,
        .canvas-wrap,
        #viewport,
        #scene {
            background: transparent !important;
            background-color: transparent !important;
        }

        /* Wie bei Energiefluss/Wärmepumpe:
           Die eigentliche Visualisierung malt KEINEN eigenen Hintergrund.
           Dadurch kommt die reale Kachelfarbe direkt von Symcon. */
        #viewport,
        #viewport * {
            --card-background-color: transparent;
        }


        html[data-theme="light"] .wall,
        html[data-theme="light"] .opening,
        html[data-theme="light"] .furniture,
        html[data-theme="light"] .device,
        html[data-theme="light"] .label,
        html[data-theme="light"] text,
        html[data-theme="light"] tspan {
            color: #111111;
        }

        html[data-theme="light"] .wall {
            stroke: #181818;
        }

        html[data-theme="light"] .furniture {
            color: #222222;
        }

        html[data-theme="light"] .device circle {
            fill: #f2f2f2;
            stroke: rgba(0,0,0,.62);
        }

        html[data-theme="light"] .device.active-light circle {
            fill: #fff2a8;
            stroke: #8a7200;
        }

        html[data-theme="light"] .device-glyph {
            color: #111111;
        }

        /* Möbel im hellen Theme:
           helle Flächen + dunkle Konturen, damit Details nicht in dunklen
           Eigenfüllungen verschwinden. */
        html[data-theme="light"] .furniture {
            color: #252525;
        }

        html[data-theme="light"] .furniture [fill="currentColor"] {
            fill: #eeeeee !important;
            stroke: #252525 !important;
        }

        html[data-theme="light"] .furniture [fill="none"] {
            stroke: #252525 !important;
        }

        html[data-theme="light"] .furniture.selected [fill="currentColor"],
        html[data-theme="light"] .furniture.selected [fill="none"] {
            stroke: #1769aa !important;
        }

        html[data-theme="light"] .opening {
            stroke: #202020;
        }

        html[data-theme="light"] .grid-line {
            stroke: rgba(0,0,0,.24);
        }

        html[data-theme="light"] button,
        html[data-theme="light"] input,
        html[data-theme="light"] select {
            color: #111111;
            border-color: rgba(0,0,0,.34);
        }

        html[data-theme="light"] button {
            background: rgba(224,224,224,.98);
        }

        html[data-theme="light"] button:hover {
            background: rgba(205,205,205,.98);
        }

        html[data-theme="light"] input,
        html[data-theme="light"] select {
            background: rgba(245,245,245,.98);
        }

        html[data-theme="light"] .properties,
        html[data-theme="light"] .toolbar,
        html[data-theme="light"] .bottom-bar,
        html[data-theme="light"] .modal,
        html[data-theme="light"] .picker {
            background: var(--fp-panel);
            color: var(--fp-text);
            border-color: var(--fp-border);
        }

        /* Helles Symcon-Theme:
           Dark bleibt unverändert. Im hellen Theme die Grundrisszeichnung
           bewusst weicher als reines Schwarz darstellen. */
        html[data-theme="light"] .wall {
            stroke: #4a4a4a;
        }

        html[data-theme="light"] .opening-gap {
            stroke: #f5f5f5;
        }

        html[data-theme="light"] .opening-line {
            stroke: #5f5f5f;
        }

        html[data-theme="light"] .opening-shutter {
            stroke: #707070;
        }

        html[data-theme="light"] .opening-shutter-slat {
            stroke: #8a8a8a;
        }

        html[data-theme="light"] .furniture {
            color: #555555;
        }

        html[data-theme="light"] .furniture [fill="currentColor"] {
            fill: rgba(90,90,90,.08) !important;
            stroke: #555555 !important;
        }

        html[data-theme="light"] .furniture [fill="none"] {
            stroke: #555555 !important;
        }

        html[data-theme="light"] .device circle {
            fill: rgba(255,255,255,.72);
            stroke: #777777;
        }

        html[data-theme="light"] .device-glyph {
            color: #555555;
        }

        html[data-theme="light"] .runtime-value {
            fill: #4a4a4a !important;
        }

        html[data-theme="light"] .label,
        html[data-theme="light"] text,
        html[data-theme="light"] tspan,
        html[data-theme="light"] .furniture-label {
            fill: #5a5a5a;
            color: #5a5a5a;
        }

        html[data-theme="light"] .status,
        html[data-theme="light"] .hint,
        html[data-theme="light"] small {
            color: var(--fp-muted);
        }


        /* Geräte-Bedienpopup: direkt beim angeklickten Gerät statt Bildmitte. */
        #controlModal {
            background: transparent;
            padding: 0;
            align-items: initial;
            justify-content: initial;
            pointer-events: none;
        }

        #controlModal.open {
            display: block;
        }

        #controlModal .control-modal {
            position: fixed;
            margin: 0;
            pointer-events: auto;
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
                Gerät/Möbel/Text: Werkzeug wählen und Position anklicken.<br>Geräte: MDI-Symbol in den Eigenschaften auswählen.<br>Möbel: 26 Easy-Floorplan-Symbole verfügbar.<br>
                Auswahl: Element anklicken und mit der Maus verschieben.<br>Geräte/Möbel: auswählen und am kleinen Resize-Punkt größer/kleiner ziehen.<br>
                Verschieben: Werkzeug wählen und den gesamten Grundriss mit gedrückter linker Maustaste verschieben.<br>
                Mittlere Maustaste: Grundriss jederzeit verschieben.<br>
                − / +: manuell heraus- oder hineinzoomen.<br>
                Start: jederzeit auf die ursprüngliche 1:1-Startansicht der aktuellen Etage zurück.<br>
                Entf: ausgewähltes Element löschen.<br>Einpassen: nur die aktuelle Etage proportional komplett in die Kachel einpassen.
            </div>
        </aside>
    </div>
    <div class="toolbar">
        <div class="group">
            <button data-tool="select" class="active">Auswahl</button>
            <button data-tool="pan" title="Grundriss mit der Maus verschieben">Verschieben</button>
            <button data-tool="wall">Wand</button>
            <button data-tool="door">Tür</button>
            <button data-tool="window">Fenster</button>
            <button data-tool="device">Gerät</button>
            <button data-tool="text">Text</button>
                <button data-tool="furniture">Möbel</button>
            <div class="grid-editor-controls" title="Raster">
                <label class="check"><input id="showGridVisu" type="checkbox" checked> Raster</label>
                <input id="gridSizeVisu" class="grid-size-input" type="number" min="2" max="200" step="1" value="20" title="Rastergröße">
            </div>
            
        </div>

        <div class="group">
            <button id="undoBtn" title="Rückgängig">↶</button>
            <button id="redoBtn" title="Wiederholen">↷</button>
            <button id="deleteBtn" class="danger">Löschen</button>
        </div>

        <div class="group">
            <button id="addFloorBtn">+ Etage</button>
            <select id="floorSelect"></select>
            <button id="deleteFloorBtn" class="danger" title="Aktuelles Geschoss komplett löschen">Etage löschen</button>
        </div>

        <div class="group">
            <button id="zoomOutBtn" type="button" title="Herauszoomen">−</button>
            <button id="zoomInBtn" type="button" title="Hineinzoomen">+</button>
            <button id="homeViewBtn" type="button" title="Zur Startansicht dieser Etage">Start</button>
            <button id="fitBtn">Einpassen</button>
            <button id="finishBtn">Fertig / Bedienen</button>
        </div>

        <div class="spacer"></div>
        <div id="status" class="status">Bereit</div>
    </div>

    <div id="viewbar">
        <select id="liveFloorSelect" title="Etage auswählen" aria-label="Etage auswählen"></select>
        <button id="editBtn" type="button" title="Floorplan bearbeiten" aria-label="Floorplan bearbeiten">✎</button>
    </div>
</div>

<div id="variableModal" class="modal-backdrop" aria-hidden="true">
    <div class="modal">
        <h3>IP-Symcon Objektbaum</h3>
        <div class="modal-search">
            <input id="variableSearch" placeholder="Objekt, Variable, Profil oder ID suchen …">
        </div>
        <div id="variableList" class="variable-list"></div>
        <div class="modal-actions">
            <button id="variableClearBtn" type="button">Zuordnung entfernen</button>
            <button id="variableCloseBtn" type="button">Abbrechen</button>
        </div>
    </div>
</div>

<div id="controlModal" class="modal-backdrop" aria-hidden="true">
    <div class="control-modal">
        <h3 id="controlTitle">Gerät bedienen</h3>
        <div id="controlBody" class="control-body"></div>
        <div class="control-actions">
            <button id="controlCloseBtn" type="button">Schließen</button>
        </div>
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

    let resizeFitTimer = null;
    let lastTileWidth = 0;
    let lastTileHeight = 0;
    const liveFloorSelect = document.getElementById('liveFloorSelect');
    const statusEl = document.getElementById('status');
    const app = document.getElementById('app');
    const variableModal = document.getElementById('variableModal');
    const variableList = document.getElementById('variableList');
    const variableSearch = document.getElementById('variableSearch');
    const controlModal = document.getElementById('controlModal');
    const controlTitle = document.getElementById('controlTitle');
    const controlBody = document.getElementById('controlBody');
    const controlCloseBtn = document.getElementById('controlCloseBtn');

    let state = normalizeProject(initial);
    let variablePickerTarget = null;
    let objectTree = [];
    const expandedObjectIDs = new Set([0]);
    let tool = 'select';
    let selected = null;
    let wallStart = null;
    let preview = null;
    let drag = null;
    let editorShowGrid = state.showGrid !== false;
    let editorGridSize = Math.max(2, Number(state.grid) || 20);

    let zoom = 1;
    let panX = 0;
    let panY = 0;

    // Jede Etage besitzt ihre eigene Ansicht. Dadurch verändert Einpassen,
    // Zoomen oder Verschieben im OG nicht mehr die Darstellung des UG.
    const floorViews = new Map();

    // Feste Startansicht je Etage. Sie wird beim ersten Anzeigen der Etage
    // einmal erzeugt und danach nicht mehr durch Einpassen/Zoomen/Verschieben verändert.
    const floorHomeViews = new Map();

    let history = [];
    let historyIndex = -1;
    let saveTimer = null;
    let dirty = false;
    let propertiesSelectOpen = false;
    let propertiesControlActive = false;

    function uid(prefix) {
        return prefix + '_' + Math.random().toString(36).slice(2, 9) + Date.now().toString(36);
    }

    // Bedienelemente in der Eigenschaftenleiste dürfen während der Eingabe
    // nicht durch ein Hintergrund-render() ersetzt werden. Sonst verschwinden
    // bei Zahlen-/Textfeldern Cursor und Markierung und native Select-Listen
    // klappen zu.
    properties.addEventListener('pointerdown', evt => {
        const control = evt.target.closest('input, select, textarea');
        if (control && properties.contains(control)) {
            propertiesControlActive = true;
            if (control instanceof HTMLSelectElement) {
                propertiesSelectOpen = true;
            }
        }
    }, true);

    properties.addEventListener('focusin', evt => {
        if (
            evt.target instanceof HTMLInputElement ||
            evt.target instanceof HTMLSelectElement ||
            evt.target instanceof HTMLTextAreaElement
        ) {
            propertiesControlActive = true;
            if (evt.target instanceof HTMLSelectElement) {
                propertiesSelectOpen = true;
            }
        }
    });

    properties.addEventListener('change', evt => {
        if (evt.target instanceof HTMLSelectElement) {
            propertiesSelectOpen = false;
        }
    }, true);

    properties.addEventListener('focusout', evt => {
        if (
            evt.target instanceof HTMLInputElement ||
            evt.target instanceof HTMLSelectElement ||
            evt.target instanceof HTMLTextAreaElement
        ) {
            // Erst nach dem jeweiligen change-Handler freigeben. Anschließend
            // die Eigenschaften einmal sauber aus dem aktuellen Objektzustand
            // aufbauen.
            setTimeout(() => {
                const active = document.activeElement;
                const stillInside =
                    active &&
                    properties.contains(active) &&
                    (
                        active instanceof HTMLInputElement ||
                        active instanceof HTMLSelectElement ||
                        active instanceof HTMLTextAreaElement
                    );

                if (!stillInside) {
                    propertiesControlActive = false;
                    propertiesSelectOpen = false;
                    renderProperties();
                }
            }, 0);
        }
    });

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
            floor.furniture = Array.isArray(floor.furniture) ? floor.furniture : [];
            for (const furniture of floor.furniture) {
                if (typeof furniture.showName !== 'boolean') furniture.showName = false;
            }
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
        detectTheme();
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

        const gridControls = document.querySelector('.grid-editor-controls');
        if (gridControls) {
            gridControls.style.display = isView ? 'none' : 'inline-flex';
        }
    }

    function setMode(mode) {
        state.mode = mode === 'view' ? 'view' : 'edit';

        const gridControls = document.querySelector('.grid-editor-controls');
        if (gridControls) {
            gridControls.style.display = state.mode === 'edit' ? 'inline-flex' : 'none';
        }
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

    function svgPointRaw(evt) {
        const pt = svg.createSVGPoint();
        pt.x = evt.clientX;
        pt.y = evt.clientY;
        const matrix = scene.getScreenCTM();
        if (!matrix) return {x: 0, y: 0};
        const p = pt.matrixTransform(matrix.inverse());
        return {x: p.x, y: p.y};
    }

    function svgPoint(evt) {
        const p = svgPointRaw(evt);
        return {
            x: snapValue(p.x),
            y: snapValue(p.y)
        };
    }

    function setTransform() {
        scene.setAttribute('transform', `translate(${panX} ${panY}) scale(${zoom})`);
    }

    function viewSafeArea() {
        const box = svg.getBoundingClientRect();
        const headerTop = state.mode === 'view' ? 42 : 0;
        const padding = 24;

        return {
            left: padding,
            right: Math.max(padding, box.width - padding),
            top: padding + headerTop,
            bottom: Math.max(padding + headerTop, box.height - padding)
        };
    }

    function rememberCurrentFloorView(autoFit = false) {
        if (!state?.activeFloor) return;
        floorViews.set(state.activeFloor, {
            zoom,
            panX,
            panY,
            autoFit: autoFit === true
        });
    }

    function makeDefaultFloorView() {
        const safe = viewSafeArea();

        // Die "Startgröße" ist bewusst 1:1. Im Live-Modus beginnt die
        // nutzbare Fläche unterhalb der HTML-SDK-Kopfzeile.
        const centerX = (safe.left + safe.right) / 2;
        const centerY = (safe.top + safe.bottom) / 2;

        return {
            zoom: 1,
            panX: centerX,
            panY: centerY
        };
    }

    function ensureCurrentFloorHomeView() {
        if (!state?.activeFloor || floorHomeViews.has(state.activeFloor)) return;
        floorHomeViews.set(state.activeFloor, makeDefaultFloorView());
    }

    function resetCurrentFloorView() {
        ensureCurrentFloorHomeView();
        const home = floorHomeViews.get(state.activeFloor);
        if (!home) return;

        zoom = home.zoom;
        panX = home.panX;
        panY = home.panY;

        rememberCurrentFloorView(false);
        setTransform();
        render();
    }

    function restoreCurrentFloorViewOrFit() {
        ensureCurrentFloorHomeView();
        const saved = floorViews.get(state.activeFloor);
        if (!saved) {
            resetCurrentFloorView();
            return;
        }

        zoom = Math.max(0.05, Math.min(20, Number(saved.zoom) || 1));
        panX = Number(saved.panX) || 0;
        panY = Number(saved.panY) || 0;
        setTransform();
        render();
    }

    function switchFloorView(nextFloorID) {
        if (!state.floors.some(f => f.id === nextFloorID)) return;

        rememberCurrentFloorView(false);
        state.activeFloor = nextFloorID;
        selected = null;
        wallStart = null;
        preview = null;
        render();
        requestAnimationFrame(restoreCurrentFloorViewOrFit);
    }

    function zoomManual(factor) {
        const box = svg.getBoundingClientRect();
        if (!box.width || !box.height) return;

        const safe = viewSafeArea();
        const centerX = (safe.left + safe.right) / 2;
        const centerY = (safe.top + safe.bottom) / 2;

        const worldX = (centerX - panX) / Math.max(0.0001, zoom);
        const worldY = (centerY - panY) / Math.max(0.0001, zoom);

        const nextZoom = Math.max(0.05, Math.min(20, zoom * factor));
        panX = centerX - worldX * nextZoom;
        panY = centerY - worldY * nextZoom;
        zoom = nextZoom;

        rememberCurrentFloorView(false);
        setTransform();
        render();
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

        for (const f of floor.furniture || []) {
            const x = Number(f.x) || 0;
            const y = Number(f.y) || 0;
            const w = Math.max(8, Number(f.width) || 100) / 2;
            const h = Math.max(8, Number(f.height) || 60) / 2;
            // Drehung bewusst konservativ über Radius berücksichtigen.
            const r = Math.sqrt(w * w + h * h);
            points.push([x - r, y - r], [x + r, y + r]);
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
            panX = box.width / 2;
            panY = box.height / 2;
            rememberCurrentFloorView(true);
            setTransform();
            render();
            return;
        }

        const contentWidth = Math.max(1, bounds.maxX - bounds.minX);
        const contentHeight = Math.max(1, bounds.maxY - bounds.minY);

        /*
         * Einpassen immer proportional:
         * - links/rechts UND oben/unten vollständig sichtbar
         * - EIN gemeinsamer Zoomfaktor für X und Y
         * - dadurch keinerlei Verzerrung/Streckung
         *
         * Im Live-Modus bleibt oben bewusst Platz für die HTML-SDK-Kopfzeile.
         * Zentriert wird nur in der darunter tatsächlich nutzbaren Planfläche.
         */
        const safe = viewSafeArea();
        const left = safe.left;
        const right = safe.right;
        const top = safe.top;
        const bottom = safe.bottom;

        const availableWidth = Math.max(1, right - left);
        const availableHeight = Math.max(1, bottom - top);

        const scaleX = availableWidth / contentWidth;
        const scaleY = availableHeight / contentHeight;

        // WICHTIG: nur EIN Zoomfaktor -> Seitenverhältnis bleibt exakt erhalten.
        zoom = Math.max(0.05, Math.min(20, Math.min(scaleX, scaleY)));

        const contentCenterX = (bounds.minX + bounds.maxX) / 2;
        const contentCenterY = (bounds.minY + bounds.maxY) / 2;

        // In der tatsächlich verfügbaren Fläche horizontal UND vertikal zentrieren.
        const targetCenterX = (left + right) / 2;
        const targetCenterY = (top + bottom) / 2;

        panX = targetCenterX - contentCenterX * zoom;
        panY = targetCenterY - contentCenterY * zoom;

        rememberCurrentFloorView(true);
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
        const options = state.floors.map(f =>
            `<option value="${escapeHtml(f.id)}"${f.id === state.activeFloor ? ' selected' : ''}>${escapeHtml(f.name)}</option>`
        ).join('');

        floorSelect.innerHTML = options;

        if (liveFloorSelect) {
            liveFloorSelect.innerHTML = options;
            liveFloorSelect.style.display = state.floors.length > 1 ? '' : 'none';
            liveFloorSelect.disabled = state.floors.length <= 1;
        }
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
            light: 'mdi:lightbulb',
            switch: 'mdi:toggle-switch',
            socket: 'mdi:power-socket-eu',
            shutter: 'mdi:blinds',
            temperature: 'mdi:thermometer',
            humidity: 'mdi:water-percent',
            motion: 'mdi:motion-sensor',
            window: 'mdi:window-closed',
            door: 'mdi:door',
            climate: 'mdi:thermostat',
            generic: 'mdi:circle'
        };
        return icons[kind] || icons.generic;
    }

    const mdiIconChoices = [
        ['mdi:lightbulb','Licht'],['mdi:toggle-switch','Schalter'],['mdi:power-socket-eu','Steckdose'],
        ['mdi:thermometer','Temperatur'],['mdi:water-percent','Feuchte'],['mdi:motion-sensor','Bewegung'],
        ['mdi:door','Tür'],['mdi:window-closed','Fenster'],['mdi:blinds','Rollladen'],['mdi:thermostat','Thermostat'],
        ['mdi:fan','Ventilator'],['mdi:radiator','Heizung'],['mdi:television','TV'],['mdi:camera','Kamera'],
        ['mdi:washing-machine','Waschmaschine'],['mdi:dishwasher','Geschirrspüler'],['mdi:water-boiler','Boiler'],
        ['mdi:car-electric','Elektroauto'],['mdi:robot-vacuum','Saugroboter'],['mdi:lock','Schloss'],
        ['mdi:home','Haus'],['mdi:cog','Allgemein']
    ];

    // Easy Floorplan verwendet Material Design Icons (MDI). Wir laden deshalb
    // die originalen Pfaddaten aus dem offiziellen @mdi/js Paket. Sollte der
    // Browser keinen externen Modulimport zulassen, bleiben die bisherigen
    // lokalen Vektorformen als Fallback aktiv.
    const originalMdiPaths = Object.create(null);

    const mdiExportNames = {
        'mdi:lightbulb': 'mdiLightbulb',
        'mdi:toggle-switch': 'mdiToggleSwitch',
        'mdi:power-socket-eu': 'mdiPowerSocketEu',
        'mdi:thermometer': 'mdiThermometer',
        'mdi:water-percent': 'mdiWaterPercent',
        'mdi:motion-sensor': 'mdiMotionSensor',
        'mdi:door': 'mdiDoor',
        'mdi:window-closed': 'mdiWindowClosed',
        'mdi:blinds': 'mdiBlinds',
        'mdi:thermostat': 'mdiThermostat',
        'mdi:fan': 'mdiFan',
        'mdi:radiator': 'mdiRadiator',
        'mdi:television': 'mdiTelevision',
        'mdi:camera': 'mdiCamera',
        'mdi:washing-machine': 'mdiWashingMachine',
        'mdi:dishwasher': 'mdiDishwasher',
        'mdi:water-boiler': 'mdiWaterBoiler',
        'mdi:car-electric': 'mdiCarElectric',
        'mdi:robot-vacuum': 'mdiRobotVacuum',
        'mdi:lock': 'mdiLock',
        'mdi:home': 'mdiHome',
        'mdi:cog': 'mdiCog',
        'mdi:circle': 'mdiCircle'
    };

    async function loadOriginalMdiIcons() {
        try {
            const mdi = await import('https://cdn.jsdelivr.net/npm/@mdi/js@7.4.47/+esm');
            for (const [iconName, exportName] of Object.entries(mdiExportNames)) {
                const path = mdi[exportName];
                if (typeof path === 'string' && path) {
                    originalMdiPaths[iconName] = path;
                }
            }
            render();
            renderDeviceIconPicker();
        } catch (error) {
            console.warn('Floorplaner: Original-MDI konnten nicht geladen werden; lokaler Fallback bleibt aktiv.', error);
        }
    }

    loadOriginalMdiIcons();

    function renderMdiGlyph(iconName, radius) {
        const r = Math.max(8, Number(radius) || 18);

        const originalPath = originalMdiPaths[iconName];
        if (originalPath) {
            // Original-MDI verwenden ein 24x24-Koordinatensystem.
            const targetSize = r * 1.55;
            const scale = targetSize / 24;
            const offset = -12 * scale;
            return `<g transform="translate(${offset} ${offset}) scale(${scale})"><path d="${originalPath}" fill="currentColor"/></g>`;
        }

        const s = r * 1.05;
        const sw = Math.max(.9, r * .085);
        const common = `fill="none" stroke="currentColor" stroke-width="${sw}" stroke-linecap="round" stroke-linejoin="round"`;

        switch (iconName) {
            case 'mdi:lightbulb': return `<g ${common}><circle cx="0" cy="-2" r="${s*.42}"/><path d="M${-s*.22} ${s*.33} L${-s*.14} ${s*.58} L${s*.14} ${s*.58} L${s*.22} ${s*.33}"/><line x1="${-s*.13}" y1="${s*.72}" x2="${s*.13}" y2="${s*.72}"/></g>`;
            case 'mdi:toggle-switch': return `<g ${common}><rect x="${-s*.62}" y="${-s*.28}" width="${s*1.24}" height="${s*.56}" rx="${s*.28}"/><circle cx="${s*.26}" cy="0" r="${s*.20}" fill="currentColor" stroke="none"/></g>`;
            case 'mdi:power-socket-eu': return `<g ${common}><circle r="${s*.56}"/><circle cx="${-s*.20}" cy="${-s*.08}" r="${s*.06}" fill="currentColor" stroke="none"/><circle cx="${s*.20}" cy="${-s*.08}" r="${s*.06}" fill="currentColor" stroke="none"/><path d="M${-s*.22} ${s*.23} Q0 ${s*.40} ${s*.22} ${s*.23}"/></g>`;
            case 'mdi:thermometer': return `<g ${common}><rect x="${-s*.14}" y="${-s*.70}" width="${s*.28}" height="${s*.98}" rx="${s*.14}"/><line y1="${-s*.48}" y2="${s*.26}"/><circle cy="${s*.43}" r="${s*.24}"/></g>`;
            case 'mdi:water-percent': return `<g ${common}><path d="M0 ${-s*.68} C${-s*.38} ${-s*.25},${-s*.48} ${s*.05},0 ${s*.62} C${s*.48} ${s*.05},${s*.38} ${-s*.25},0 ${-s*.68} Z"/><line x1="${-s*.24}" y1="${s*.25}" x2="${s*.24}" y2="${-s*.25}"/><circle cx="${-s*.22}" cy="${-s*.20}" r="${s*.07}"/><circle cx="${s*.22}" cy="${s*.20}" r="${s*.07}"/></g>`;
            case 'mdi:motion-sensor': return `<g ${common}><circle cx="${-s*.12}" cy="${-s*.35}" r="${s*.10}"/><path d="M${-s*.12} ${-s*.23} L${-s*.30} ${s*.08} L${-s*.06} ${s*.20} L${-s*.24} ${s*.58} M${-s*.08} ${s*.02} L${s*.20} ${s*.20}"/><path d="M${s*.24} ${-s*.34} Q${s*.58} 0 ${s*.24} ${s*.34} M${s*.43} ${-s*.52} Q${s*.86} 0 ${s*.43} ${s*.52}"/></g>`;
            case 'mdi:door': return `<g ${common}><rect x="${-s*.48}" y="${-s*.68}" width="${s*.96}" height="${s*1.36}"/><line x1="${-s*.30}" y1="${-s*.58}" x2="${-s*.30}" y2="${s*.58}"/><circle cx="${s*.20}" cy="${s*.02}" r="${s*.045}" fill="currentColor" stroke="none"/></g>`;
            case 'mdi:window-closed': return `<g ${common}><rect x="${-s*.60}" y="${-s*.55}" width="${s*1.20}" height="${s*1.10}"/><line y1="${-s*.55}" y2="${s*.55}"/><line x1="${-s*.60}" x2="${s*.60}"/></g>`;
            case 'mdi:blinds': return `<g ${common}><rect x="${-s*.58}" y="${-s*.60}" width="${s*1.16}" height="${s*1.20}"/><line x1="${-s*.48}" y1="${-s*.32}" x2="${s*.48}" y2="${-s*.32}"/><line x1="${-s*.48}" y1="${-s*.08}" x2="${s*.48}" y2="${-s*.08}"/><line x1="${-s*.48}" y1="${s*.16}" x2="${s*.48}" y2="${s*.16}"/><line x1="${-s*.48}" y1="${s*.40}" x2="${s*.48}" y2="${s*.40}"/></g>`;
            case 'mdi:thermostat': return `<g ${common}><circle r="${s*.58}"/><line y1="${-s*.34}" y2="${s*.12}"/><circle cy="${s*.28}" r="${s*.15}"/></g>`;
            case 'mdi:fan': return `<g fill="currentColor"><circle r="${s*.09}"/><ellipse cy="${-s*.34}" ry="${s*.35}" rx="${s*.15}"/><ellipse transform="rotate(120)" cy="${-s*.34}" ry="${s*.35}" rx="${s*.15}"/><ellipse transform="rotate(240)" cy="${-s*.34}" ry="${s*.35}" rx="${s*.15}"/></g>`;
            case 'mdi:radiator': return `<g ${common}><rect x="${-s*.60}" y="${-s*.50}" width="${s*1.20}" height="${s}"/><line x1="${-s*.36}" y1="${-s*.42}" x2="${-s*.36}" y2="${s*.42}"/><line x1="${-s*.12}" y1="${-s*.42}" x2="${-s*.12}" y2="${s*.42}"/><line x1="${s*.12}" y1="${-s*.42}" x2="${s*.12}" y2="${s*.42}"/><line x1="${s*.36}" y1="${-s*.42}" x2="${s*.36}" y2="${s*.42}"/></g>`;
            case 'mdi:television': return `<g ${common}><rect x="${-s*.64}" y="${-s*.48}" width="${s*1.28}" height="${s*.88}" rx="${s*.07}"/><path d="M${-s*.24} ${s*.60} H${s*.24} M0 ${s*.40} V${s*.60}"/></g>`;
            case 'mdi:camera': return `<g ${common}><rect x="${-s*.62}" y="${-s*.40}" width="${s*1.24}" height="${s*.88}" rx="${s*.08}"/><path d="M${-s*.28} ${-s*.40} L${-s*.14} ${-s*.58} H${s*.16} L${s*.30} ${-s*.40}"/><circle cy="${s*.03}" r="${s*.24}"/></g>`;
            case 'mdi:washing-machine':
            case 'mdi:dishwasher': return `<g ${common}><rect x="${-s*.52}" y="${-s*.64}" width="${s*1.04}" height="${s*1.28}" rx="${s*.06}"/><line x1="${-s*.42}" y1="${-s*.38}" x2="${s*.42}" y2="${-s*.38}"/><circle cy="${s*.12}" r="${s*.30}"/></g>`;
            case 'mdi:water-boiler': return `<g ${common}><rect x="${-s*.44}" y="${-s*.62}" width="${s*.88}" height="${s*1.24}" rx="${s*.22}"/><path d="M${-s*.16} ${s*.10} Q0 ${-s*.14} ${s*.16} ${s*.10} Q0 ${s*.34} ${-s*.16} ${s*.10} Z"/></g>`;
            case 'mdi:car-electric': return `<g ${common}><path d="M${-s*.62} ${s*.18} L${-s*.44} ${-s*.26} Q${-s*.36} ${-s*.48} ${-s*.10} ${-s*.48} H${s*.30} Q${s*.46} ${-s*.48} ${s*.54} ${-s*.24} L${s*.66} ${s*.18} V${s*.42} H${-s*.66} Z"/><circle cx="${-s*.36}" cy="${s*.42}" r="${s*.14}"/><circle cx="${s*.36}" cy="${s*.42}" r="${s*.14}"/></g>`;
            case 'mdi:robot-vacuum': return `<g ${common}><circle r="${s*.58}"/><circle cx="${-s*.20}" cy="${-s*.12}" r="${s*.05}" fill="currentColor" stroke="none"/><circle cx="${s*.20}" cy="${-s*.12}" r="${s*.05}" fill="currentColor" stroke="none"/><path d="M${-s*.24} ${s*.18} Q0 ${s*.34} ${s*.24} ${s*.18}"/></g>`;
            case 'mdi:lock': return `<g ${common}><rect x="${-s*.46}" y="${-s*.12}" width="${s*.92}" height="${s*.72}" rx="${s*.08}"/><path d="M${-s*.28} ${-s*.12} V${-s*.34} A${s*.28} ${s*.28} 0 0 1 ${s*.28} ${-s*.34} V${-s*.12}"/></g>`;
            case 'mdi:home': return `<g ${common}><path d="M${-s*.66} ${-s*.04} L0 ${-s*.62} L${s*.66} ${-s*.04} M${-s*.48} ${-s*.12} V${s*.58} H${s*.48} V${-s*.12}"/><rect x="${-s*.13}" y="${s*.16}" width="${s*.26}" height="${s*.42}"/></g>`;
            case 'mdi:cog': return `<g ${common}><circle r="${s*.22}"/><circle r="${s*.52}" stroke-dasharray="${s*.20} ${s*.13}"/></g>`;
            default: return `<g ${common}><circle r="${s*.48}"/></g>`;
        }
    }

    const furnitureTemplates = {"airHandler":{"id":"airHandler","name":"Lüftungsgerät","category":"utility","size":{"w":60,"h":56},"parts":[{"rect":[0,0,100,100],"rx":7.142857},{"line":[8,8,92,92],"role":"detail","opacity":0.8},{"line":[8,92,92,8],"role":"detail","opacity":0.8}]},"bathtub":{"id":"bathtub","name":"Badewanne","category":"bath","size":{"w":150,"h":76},"parts":[{"rect":[0,0,100,100],"rx":5.263158},{"rect":[6,12,88,76],"rx":12,"role":"line"},{"circle":[14,50,5.5],"role":"thin"}]},"bed":{"id":"bed","name":"Bett","category":"bedroom","size":{"w":150,"h":200},"parts":[{"rect":[0,0,100,100],"rx":2.666667},{"line":[0,26,100,26],"role":"line"},{"rect":[10,6,34,14],"rx":2,"role":"thin"},{"rect":[56,6,34,14],"rx":2,"role":"thin"}]},"chair":{"id":"chair","name":"Stuhl","category":"living","size":{"w":44,"h":44},"parts":[{"rect":[0,0,100,100],"rx":9.090909},{"line":[0,22,100,22],"role":"line"}]},"desk":{"id":"desk","name":"Schreibtisch","category":"living","size":{"w":120,"h":60},"parts":[{"rect":[0,0,100,100],"rx":6.666667},{"line":[0,55,100,55],"role":"detail"}]},"dishwasher":{"id":"dishwasher","name":"Geschirrspüler","category":"kitchen","size":{"w":60,"h":60},"parts":[{"rect":[0,0,100,100],"rx":6.666667},{"rect":[10,24,80,62],"rx":5,"role":"detail","opacity":0.8},{"line":[6,88,94,88],"role":"line"}]},"dryer":{"id":"dryer","name":"Trockner","category":"utility","size":{"w":60,"h":62},"parts":[{"rect":[0,0,100,100],"rx":6.666667},{"line":[6,18,94,18],"role":"detail"},{"circle":[50,56,30],"role":"line"},{"circle":[50,56,13.5],"role":"detail"}]},"fishTank":{"id":"fishTank","name":"Aquarium","category":"living","size":{"w":100,"h":40},"parts":[{"rect":[0,0,100,100],"rx":10},{"rect":[5,12,90,76],"role":"hint"},{"ellipse":[32,40,7,9],"role":"thin"},{"path":[["M",39,40],["L",44,32],["L",44,48],["Z"]],"role":"solid"},{"ellipse":[68,60,7,9],"role":"thin"},{"path":[["M",61,60],["L",56,52],["L",56,68],["Z"]],"role":"solid"},{"circle":[82,32,4],"role":"hint"}]},"fridge":{"id":"fridge","name":"Kühlschrank","category":"kitchen","size":{"w":60,"h":64},"parts":[{"rect":[0,0,100,100],"rx":6.666667},{"line":[0,40,100,40],"role":"line"},{"line":[84,12,84,30],"role":"line"},{"line":[84,50,84,84],"role":"line"}]},"hotTub":{"id":"hotTub","name":"Whirlpool","category":"bath","size":{"w":120,"h":120},"parts":[{"rect":[0,0,100,100],"rx":3.333333},{"circle":[50,50,36],"role":"line"},{"circle":[27.68,27.68,5],"role":"hint","space":"square"},{"circle":[72.32,27.68,5],"role":"hint","space":"square"},{"circle":[27.68,72.32,5],"role":"hint","space":"square"},{"circle":[72.32,72.32,5],"role":"hint","space":"square"}]},"piano":{"id":"piano","name":"Klavier","category":"living","size":{"w":140,"h":60},"parts":[{"rect":[0,0,100,100],"rx":6.666667},{"line":[4,70,96,70],"role":"thin"},{"repeat":7,"step":[12.5,0],"part":{"line":[12.5,70,12.5,94],"role":"hint"}},{"line":[4,22,96,22],"role":"hint","opacity":0.5}]},"plant":{"id":"plant","name":"Pflanze","category":"living","size":{"w":44,"h":44},"footprint":"ellipse","parts":[{"ellipse":[50,50,50,50],"role":"body"},{"circle":[50,38,18],"role":"thin"},{"circle":[34,58,18],"role":"thin"},{"circle":[66,58,18],"role":"thin"}]},"roundTable":{"id":"roundTable","name":"Runder Tisch","category":"living","size":{"w":100,"h":100},"footprint":"ellipse","parts":[{"ellipse":[50,50,50,50],"role":"body"}]},"rug":{"id":"rug","name":"Teppich","category":"living","size":{"w":180,"h":120},"parts":[{"rect":[0,0,100,100],"rx":12,"role":"body","fillOpacity":0.08,"dash":[8,5]},{"rect":[10,10,80,80],"rx":8,"role":"detail","opacity":0.6}]},"sectional":{"id":"sectional","name":"Ecksofa","category":"living","size":{"w":230,"h":180},"parts":[{"polygon":[[0,0],[100,0],[100,100],[58,100],[58,55],[0,55]],"role":"body"},{"line":[0,16,100,16],"role":"line"},{"line":[9,16,9,55],"role":"line"},{"line":[58,16,58,100],"role":"line"}]},"sink":{"id":"sink","name":"Spüle","category":"kitchen","size":{"w":64,"h":48},"parts":[{"rect":[0,0,100,100],"rx":8.333333},{"rect":[12,18,76,50],"rx":8.333333,"role":"line"},{"circle":[50,10,5],"role":"line"}]},"sofa":{"id":"sofa","name":"Sofa","category":"living","size":{"w":170,"h":72},"parts":[{"rect":[0,0,100,100],"rx":5.555556},{"line":[0,30,100,30],"role":"line"},{"line":[12,30,12,100],"role":"line"},{"line":[88,30,88,100],"role":"line"}]},"stairs":{"id":"stairs","name":"Treppe","category":"utility","size":{"w":90,"h":170},"parts":[{"rect":[0,0,100,100],"rx":4.444444},{"repeat":6,"step":[0,14.285714],"part":{"line":[0,14.285714,100,14.285714],"role":"thin"}},{"line":[50,96.470588,50,3.529412],"role":"thin"},{"path":[["M",38,16],["L",50,2.352941],["L",62,16]],"role":"thin"}]},"stove":{"id":"stove","name":"Herd","category":"kitchen","size":{"w":64,"h":64},"parts":[{"rect":[0,0,100,100],"rx":6.25},{"circle":[28,28,16],"role":"line"},{"circle":[72,28,16],"role":"line"},{"circle":[28,72,16],"role":"line"},{"circle":[72,72,16],"role":"line"}]},"table":{"id":"table","name":"Tisch","category":"living","size":{"w":120,"h":80},"parts":[{"rect":[0,0,100,100],"rx":5}]},"toilet":{"id":"toilet","name":"WC","category":"bath","size":{"w":48,"h":68},"parts":[{"rect":[0,0,100,100],"rx":8.333333},{"rect":[10,0,80,22],"rx":6.25,"role":"line"},{"ellipse":[50,68,34,30],"role":"line"}]},"tv":{"id":"tv","name":"TV","category":"living","size":{"w":110,"h":18},"parts":[{"rect":[0,0,100,100],"rx":22.222222},{"line":[32,100,68,200],"role":"line"}]},"vanity":{"id":"vanity","name":"Waschtisch","category":"bath","size":{"w":110,"h":55},"parts":[{"rect":[0,0,100,100],"rx":7.272727},{"ellipse":[50,56,20,26],"role":"line"},{"circle":[50,14,5],"role":"thin"}]},"wardrobe":{"id":"wardrobe","name":"Schrank","category":"bedroom","size":{"w":120,"h":55},"parts":[{"rect":[0,0,100,100],"rx":7.272727},{"line":[50,0,50,100],"role":"line"},{"line":[44,40,44,60],"role":"line"},{"line":[56,40,56,60],"role":"line"}]},"washer":{"id":"washer","name":"Waschmaschine","category":"utility","size":{"w":60,"h":62},"parts":[{"rect":[0,0,100,100],"rx":6.666667},{"line":[6,18,94,18],"role":"detail"},{"circle":[50,56,30],"role":"line"},{"circle":[16,9,4.5],"role":"thin"}]},"waterHeater":{"id":"waterHeater","name":"Boiler","category":"utility","size":{"w":52,"h":52},"footprint":"ellipse","parts":[{"ellipse":[50,50,50,50],"role":"body"},{"circle":[50,50,17],"role":"thin"}]}};

    function furnitureRoleStyle(part) {
        const role = part.role || ((part.rect || part.ellipse || part.polygon) ? 'body' : 'line');
        const map = {
            body: {fill:.12,width:2,opacity:1}, line: {fill:0,width:2,opacity:1},
            thin: {fill:0,width:1.5,opacity:1}, detail: {fill:0,width:1.5,opacity:.7},
            hint: {fill:0,width:1,opacity:.6}, solid: {fill:.7,width:0,opacity:.7}
        };
        return map[role] || map.line;
    }

    function furnitureShape(f) {
        const tpl = furnitureTemplates[f.type] || furnitureTemplates.sofa;
        const w = Math.max(8, Number(f.width) || tpl.size.w);
        const h = Math.max(8, Number(f.height) || tpl.size.h);
        const view = tpl.viewBox || [0,0,100,100];
        const [vx,vy,vw,vh] = view;
        const fullX = x => -w/2 + ((x-vx)/vw)*w;
        const fullY = y => -h/2 + ((y-vy)/vh)*h;
        const square = Math.min(w,h);
        const sqX = x => -square/2 + ((x-vx)/vw)*square;
        const sqY = y => -square/2 + ((y-vy)/vh)*square;
        const lenX = v => (v/vw)*w;
        const lenY = v => (v/vh)*h;
        const lenMin = v => (v/Math.min(vw,vh))*Math.min(w,h);

        const drawPart = (part, ox=0, oy=0) => {
            if (part.repeat) {
                const step = part.step || [0,0];
                let out = '';
                for (let i=0;i<part.repeat;i++) out += drawPart(part.part, ox+step[0]*i, oy+step[1]*i);
                return out;
            }
            const squareSpace = part.space === 'square';
            const X = x => squareSpace ? sqX(x+ox) : fullX(x+ox);
            const Y = y => squareSpace ? sqY(y+oy) : fullY(y+oy);
            const st = furnitureRoleStyle(part);
            const strokeWidth = part.width ?? st.width;
            const opacity = part.opacity ?? st.opacity;
            const fillOpacity = part.fillOpacity ?? st.fill;
            const dash = part.dash ? ` stroke-dasharray="${part.dash.map(lenMin).join(' ')}"` : '';
            const style = `stroke="currentColor" stroke-width="${strokeWidth}" vector-effect="non-scaling-stroke" opacity="${opacity}"` +
                (fillOpacity > 0 ? ` fill="currentColor" fill-opacity="${fillOpacity}"` : ' fill="none"') + dash;

            if (part.rect) {
                const [x,y,pw,ph]=part.rect;
                return `<rect ${style} x="${X(x)}" y="${Y(y)}" width="${squareSpace?lenMin(pw):lenX(pw)}" height="${squareSpace?lenMin(ph):lenY(ph)}" rx="${lenMin(part.rx||0)}"/>`;
            }
            if (part.line) { const [x1,y1,x2,y2]=part.line; return `<line ${style} x1="${X(x1)}" y1="${Y(y1)}" x2="${X(x2)}" y2="${Y(y2)}"/>`; }
            if (part.circle) { const [cx,cy,r]=part.circle; return `<circle ${style} cx="${X(cx)}" cy="${Y(cy)}" r="${lenMin(r)}"/>`; }
            if (part.ellipse) { const [cx,cy,rx,ry]=part.ellipse; return `<ellipse ${style} cx="${X(cx)}" cy="${Y(cy)}" rx="${squareSpace?lenMin(rx):lenX(rx)}" ry="${squareSpace?lenMin(ry):lenY(ry)}"/>`; }
            if (part.polygon || part.polyline) {
                const pts=(part.polygon||part.polyline).map(p=>`${X(p[0])},${Y(p[1])}`).join(' ');
                return `<${part.polygon?'polygon':'polyline'} ${style} points="${pts}"/>`;
            }
            if (part.path) {
                let d='';
                for (const cmd of part.path) {
                    const op=cmd[0];
                    if(op==='Z') d+=' Z';
                    else if(op==='M'||op==='L') d+=` ${op} ${X(cmd[1])} ${Y(cmd[2])}`;
                    else if(op==='Q') d+=` Q ${X(cmd[1])} ${Y(cmd[2])} ${X(cmd[3])} ${Y(cmd[4])}`;
                    else if(op==='C') d+=` C ${X(cmd[1])} ${Y(cmd[2])} ${X(cmd[3])} ${Y(cmd[4])} ${X(cmd[5])} ${Y(cmd[6])}`;
                }
                return `<path ${style} d="${d.trim()}"/>`;
            }
            return '';
        };
        return (tpl.parts||[]).map(p=>drawPart(p)).join('');
    }

    // Exakt das bewährte Verfahren aus dem Energiefluss-Modul:
    // Symcon liefert --content-color. Daraus wird nur Hell/Dunkel bestimmt.
    // Der Hintergrund selbst bleibt transparent und kommt direkt von Symcon.
    function detectTheme() {
        let probe = getComputedStyle(document.documentElement).getPropertyValue('--content-color').trim();
        if (!probe) probe = getComputedStyle(document.body).color;

        let dark = null;
        const m = probe && probe.match(/rgba?\((\d+)[,\s]+(\d+)[,\s]+(\d+)/);
        if (m) {
            const lum = (0.299 * m[1] + 0.587 * m[2] + 0.114 * m[3]) / 255;
            dark = lum > 0.5;
        } else if (probe && probe[0] === '#' && probe.length >= 7) {
            const r = parseInt(probe.substr(1, 2), 16);
            const g = parseInt(probe.substr(3, 2), 16);
            const b = parseInt(probe.substr(5, 2), 16);
            dark = (0.299 * r + 0.587 * g + 0.114 * b) / 255 > 0.5;
        }

        if (dark === null) {
            dark = window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches;
        }

        document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
    }


    function renderEditorGrid(parts) {
        if (state.mode !== 'edit' || !editorShowGrid) return;

        const rect = svg.getBoundingClientRect();
        const left = (-panX) / zoom;
        const top = (-panY) / zoom;
        const right = left + rect.width / zoom;
        const bottom = top + rect.height / zoom;
        const step = Math.max(2, Number(editorGridSize) || 20);

        const startX = Math.floor(left / step) * step;
        const startY = Math.floor(top / step) * step;

        for (let x = startX; x <= right; x += step) {
            parts.push(`<line class="grid-line" x1="${x}" y1="${top}" x2="${x}" y2="${bottom}"/>`);
        }
        for (let y = startY; y <= bottom; y += step) {
            parts.push(`<line class="grid-line" x1="${left}" y1="${y}" x2="${right}" y2="${y}"/>`);
        }
    }

    function render() {
        const floor = currentFloor();
        const parts = [];
        renderEditorGrid(parts);
        const bounds = visibleWorldBounds(120);

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
            const amount = openingState(o);
            const isOpen = amount > 0.02;
            const stateClass = isOpen ? ' opening-state-open' : '';

            parts.push(`<g class="opening${sel}" data-type="opening" data-id="${o.id}">`);
            parts.push(`<line class="opening-hit" x1="${geom.x1}" y1="${geom.y1}" x2="${geom.x2}" y2="${geom.y2}"/>`);
            parts.push(`<line class="opening-gap" x1="${geom.x1}" y1="${geom.y1}" x2="${geom.x2}" y2="${geom.y2}"/>`);

            if (o.type === 'door') {
                const leafLength = Math.hypot(geom.x2 - geom.x1, geom.y2 - geom.y1);
                const angle = amount * Math.PI / 2;
                const ex = geom.x1 + geom.ux * leafLength * Math.cos(angle) + geom.nx * leafLength * Math.sin(angle);
                const ey = geom.y1 + geom.uy * leafLength * Math.cos(angle) + geom.ny * leafLength * Math.sin(angle);

                parts.push(`<line class="opening-line${stateClass}" x1="${geom.x1}" y1="${geom.y1}" x2="${ex}" y2="${ey}"/>`);

                if (isOpen) {
                    const qx = geom.x1 + geom.ux * leafLength * .72 + geom.nx * leafLength * .28 * amount;
                    const qy = geom.y1 + geom.uy * leafLength * .72 + geom.ny * leafLength * .28 * amount;
                    parts.push(`<path class="opening-line${stateClass}" d="M ${geom.x2} ${geom.y2} Q ${qx} ${qy} ${ex} ${ey}"/>`);
                }
            } else {
                if (!isOpen) {
                    parts.push(`<line class="opening-line" x1="${geom.x1}" y1="${geom.y1}" x2="${geom.x2}" y2="${geom.y2}"/>`);
                    parts.push(`<line class="opening-line" x1="${geom.wx1}" y1="${geom.wy1}" x2="${geom.wx2}" y2="${geom.wy2}"/>`);
                } else {
                    // Zwei Fensterflügel öffnen sich sichtbar in den Raum.
                    const halfLen = Math.hypot(geom.x2 - geom.x1, geom.y2 - geom.y1) / 2;
                    const swing = halfLen * .72 * amount;
                    const leftInnerX = geom.cx + geom.nx * swing;
                    const leftInnerY = geom.cy + geom.ny * swing;
                    const rightInnerX = geom.cx + geom.nx * swing;
                    const rightInnerY = geom.cy + geom.ny * swing;

                    parts.push(`<line class="opening-line opening-state-open" x1="${geom.x1}" y1="${geom.y1}" x2="${leftInnerX}" y2="${leftInnerY}"/>`);
                    parts.push(`<line class="opening-line opening-state-open" x1="${geom.x2}" y1="${geom.y2}" x2="${rightInnerX}" y2="${rightInnerY}"/>`);
                }
            }

            if (o.shutterVariableID) {
                const shutterOpen = shutterState(o);
                const closed = 1 - shutterOpen;
                const shutterOffset = 8;
                const sx1 = geom.x1 - geom.nx * shutterOffset;
                const sy1 = geom.y1 - geom.ny * shutterOffset;
                const sx2 = geom.x2 - geom.nx * shutterOffset;
                const sy2 = geom.y2 - geom.ny * shutterOffset;

                if ((o.shutterStyle || 'roll') === 'roll') {
                    if (closed > 0.01) {
                        parts.push(
                            `<line class="opening-shutter" opacity="${Math.max(.18, closed)}" ` +
                            `x1="${sx1}" y1="${sy1}" x2="${sx2}" y2="${sy2}"/>`
                        );

                        const slats = Math.max(1, Math.round(7 * closed));
                        for (let i = 0; i < slats; i++) {
                            const t = slats === 1 ? .5 : i / (slats - 1);
                            const px = sx1 + (sx2 - sx1) * t;
                            const py = sy1 + (sy2 - sy1) * t;
                            parts.push(
                                `<line class="opening-shutter-slat" x1="${px - geom.nx * 4}" y1="${py - geom.ny * 4}" ` +
                                `x2="${px + geom.nx * 4}" y2="${py + geom.ny * 4}"/>`
                            );
                        }
                    }
                } else {
                    const panel = Math.hypot(geom.x2 - geom.x1, geom.y2 - geom.y1) * .24 * closed;
                    if (panel > .5) {
                        parts.push(`<line class="opening-shutter" x1="${geom.x1}" y1="${geom.y1}" x2="${geom.x1 - geom.nx * panel}" y2="${geom.y1 - geom.ny * panel}"/>`);
                        parts.push(`<line class="opening-shutter" x1="${geom.x2}" y1="${geom.y2}" x2="${geom.x2 - geom.nx * panel}" y2="${geom.y2 - geom.ny * panel}"/>`);
                    }
                }
            }

            if (selected?.type === 'opening' && selected.id === o.id) {
                // Griff am rechten Ende der Öffnung: damit Tür/Fenster direkt
                // entlang der zugehörigen Wand breiter oder schmaler gezogen werden kann.
                parts.push(
                    `<circle class="resize-handle" data-resize-type="opening" data-id="${o.id}" ` +
                    `cx="${geom.x2}" cy="${geom.y2}" r="2.8"/>`
                );
            }

            parts.push(`</g>`);
        }

        for (const f of floor.furniture || []) {
            const sel = selected?.type === 'furniture' && selected.id === f.id ? ' selected' : '';
            const rot = Number(f.rotation) || 0;

            parts.push(
                `<g class="furniture${sel}" data-type="furniture" data-id="${f.id}" ` +
                `transform="translate(${Number(f.x) || 0} ${Number(f.y) || 0}) rotate(${rot})">`
            );
            parts.push(furnitureShape(f));
            if (f.showName === true) {
                parts.push(
                    `<text class="furniture-label" x="0" y="${(Number(f.height) || 60) / 2 + 16}">` +
                    `${escapeHtml(f.name || furnitureTemplates[f.type]?.name || 'Möbel')}</text>`
                );
            }

            if (selected?.type === 'furniture' && selected.id === f.id) {
                const fw = Math.max(8, Number(f.width) || 100);
                const fh = Math.max(8, Number(f.height) || 60);
                const rotateY = -fh / 2 - 16;

                parts.push(
                    `<circle class="resize-handle" data-resize-type="furniture" data-id="${f.id}" ` +
                    `cx="${fw / 2}" cy="${fh / 2}" r="2.8"/>`
                );
                parts.push(
                    `<line class="rotate-handle-line" x1="0" y1="${-fh / 2}" x2="0" y2="${rotateY}"/>` +
                    `<circle class="rotate-handle" data-rotate-type="furniture" data-id="${f.id}" ` +
                    `cx="0" cy="${rotateY}" r="3.2"/>`
                );
            }

            parts.push(`</g>`);
        }

        for (const item of floor.items) {
            const sel = selected?.type === 'item' && selected.id === item.id ? ' selected' : '';
            const raw = item._rawValue;
            const boolActive = item._variableType === 0 && (raw === true || raw === 1 || raw === '1' || raw === 'true');
            const lightClass = item.kind === 'light' ? (boolActive ? ' active-light' : ' inactive-light') : '';
            const icon = item.icon || iconForKind(item.kind);

            const showName = item.showName === true;
            const legacyShowState = item.showState === true || (item.showState == null && ['temperature','humidity'].includes(item.kind));

            // Bestehende Projekte bleiben kompatibel. Temperatur und Feuchte
            // werden ohne explizite Einstellung automatisch als reiner Wert dargestellt.
            let displayMode = item.displayMode;
            if (!['icon','value','iconValue'].includes(displayMode)) {
                displayMode = ['temperature','humidity'].includes(item.kind)
                    ? 'value'
                    : (legacyShowState ? 'iconValue' : 'icon');
            }

            const showIcon = displayMode === 'icon' || displayMode === 'iconValue';
            const showValue = displayMode === 'value' || displayMode === 'iconValue';
            const valueText = item._valueText !== undefined && item._valueText !== ''
                ? String(item._valueText)
                : '—';

            const labelParts = [];
            if (showName && item.name) labelParts.push(String(item.name));
            if (showValue && displayMode !== 'value') labelParts.push(valueText);
            const labelText = labelParts.join(' · ');

            const labelSize = Math.max(8, Math.min(40, Number(item.labelSize) || 12));
            const pos = ['left','right','below'].includes(item.labelPosition) ? item.labelPosition : 'below';
            const radius = Number(item.size) || 18;

            let lx = 0, ly = radius + labelSize + 5, anchor = 'middle';
            if (pos === 'left') {
                lx = -(radius + 7); ly = 4; anchor = 'end';
            } else if (pos === 'right') {
                lx = radius + 7; ly = 4; anchor = 'start';
            }

            const valueOnlyWidth = Math.max(radius * 2, valueText.length * labelSize * .62 + 16);
            const valueOnlyHeight = Math.max(radius * 1.45, labelSize + 14);

            parts.push(
                `<g class="device${sel}${lightClass}" data-type="item" data-id="${item.id}" transform="translate(${item.x} ${item.y})">` +
                (displayMode === 'value'
                    ? `<rect class="device-value-box" x="${-valueOnlyWidth/2}" y="${-valueOnlyHeight/2}" width="${valueOnlyWidth}" height="${valueOnlyHeight}" rx="6"/>` +
                      `<text class="runtime-value" x="0" y="${labelSize * .34}" text-anchor="middle" font-size="${labelSize}">${escapeHtml(valueText)}</text>`
                    : `<circle r="${radius}"/>` +
                      (showIcon ? `<g class="device-glyph" transform="rotate(${Number(item.angle) || 0})">${renderMdiGlyph(icon, radius * .78)}</g>` : '')
                ) +
                (labelText ? `<text class="device-label" x="${lx}" y="${ly}" text-anchor="${anchor}" font-size="${labelSize}">${escapeHtml(labelText)}</text>` : '') +
                (selected?.type === 'item' && selected.id === item.id
                    ? `<circle class="resize-handle" data-resize-type="item" data-id="${item.id}" cx="${radius * 0.707}" cy="${radius * 0.707}" r="2.8"/>`
                    : '') +
                `</g>`
            );
        }

        for (const t of floor.texts) {
            const sel = selected?.type === 'text' && selected.id === t.id;
            const textSize = Math.max(6, Number(t.size) || 18);
            const textValue = String(t.text || 'Text');
            const estimatedWidth = Math.max(20, textValue.length * textSize * 0.65);

            parts.push(
                `<g data-type="text" data-id="${t.id}">` +
                `<text class="plan-text" x="${t.x}" y="${t.y}" font-size="${textSize}"` +
                `${sel ? ' style="fill:#74b9ff"' : ''}>${escapeHtml(textValue)}</text>` +
                (sel
                    ? `<circle class="resize-handle" data-resize-type="text" data-id="${t.id}" ` +
                      `cx="${Number(t.x) + estimatedWidth}" cy="${Number(t.y)}" r="2.8"/>`
                    : '') +
                `</g>`
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

    function truthyVariableValue(value) {
        return value === true || value === 1 || value === '1' || value === 'true' || value === 'on' || value === 'open';
    }

    function normalizedOpeningAmount(rawValue, variableType, profile, invert = false) {
        let amount = 0;

        if (Number(variableType) === 0) {
            amount = truthyVariableValue(rawValue) ? 1 : 0;

            // Bei Fenster-/Türkontakten ist TRUE nicht immer automatisch "offen".
            // Falls das Bool-Profil sprechende Assoziationen besitzt, verwenden wir
            // deren Bezeichnung (z.B. Offen/Geschlossen) für die Darstellung.
            const associations = Array.isArray(profile?.associations) ? profile.associations : [];
            const currentAssociation = associations.find(a =>
                Boolean(Number(a.value)) === Boolean(truthyVariableValue(rawValue))
            );

            if (currentAssociation?.name) {
                const name = String(currentAssociation.name).toLowerCase();
                if (/(geschlossen|closed|zu\b)/.test(name)) {
                    amount = 0;
                } else if (/(geöffnet|offen|opened|open\b)/.test(name)) {
                    amount = 1;
                }
            }
        } else if (Number(variableType) === 1 || Number(variableType) === 2) {
            const raw = Number(rawValue);
            const min = Number(profile?.min);
            const max = Number(profile?.max);

            if (Number.isFinite(raw) && Number.isFinite(min) && Number.isFinite(max) && max > min) {
                amount = (raw - min) / (max - min);
            } else if (Number.isFinite(raw)) {
                amount = raw > 1 ? raw / 100 : raw;
            }
        } else {
            amount = truthyVariableValue(String(rawValue).toLowerCase()) ? 1 : 0;
        }

        amount = Math.max(0, Math.min(1, amount));
        return invert ? 1 - amount : amount;
    }

    function openingState(o) {
        return normalizedOpeningAmount(
            o._rawValue,
            o._variableType,
            o._profile,
            o.invert === true
        );
    }

    function shutterState(o) {
        return normalizedOpeningAmount(
            o._shutterVariableRawValue,
            o._shutterVariableType,
            o._shutterVariableProfile,
            o.shutterInvert === true
        );
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
            x1, y1, x2, y2, cx, cy, dx, dy, ux, uy, nx, ny,
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
        if (type === 'furniture') return (floor.furniture || []).find(v => v.id === id);
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
        } else if (selected.type === 'furniture') {
            floor.furniture = (floor.furniture || []).filter(v => v.id !== selected.id);
        } else if (selected.type === 'text') {
            floor.texts = floor.texts.filter(v => v.id !== selected.id);
        }

        selected = null;
        pushHistory();
        markDirty();
        render();
    }

    const deviceIconChoices = mdiIconChoices;

    function getDeviceIconPicker() {
        return document.getElementById('deviceIconPicker');
    }

    function closeDeviceIconPicker() {
        const picker = getDeviceIconPicker();
        if (picker) picker.style.display = 'none';
    }

    function openDeviceIconPicker(input) {
        const picker = getDeviceIconPicker();
        if (!picker || !input) return;

        picker.innerHTML =
            `<button type="button" data-device-icon="" title="Standardsymbol des Gerätetyps">↩</button>` +
            deviceIconChoices
                .map(([icon,label]) =>
                    `<button type="button" data-device-icon="${escapeHtml(icon)}" title="${escapeHtml(label + ' · ' + icon)}">` +
                    `<svg viewBox="-16 -16 32 32" width="22" height="22">${renderMdiGlyph(icon, 12)}</svg>` +
                    `</button>`)
                .join('');

        const rect = input.getBoundingClientRect();
        picker.style.left = `${Math.min(rect.left, Math.max(8, window.innerWidth - 330))}px`;
        picker.style.top = `${Math.min(rect.bottom + 4, Math.max(8, window.innerHeight - 310))}px`;
        picker.style.display = 'grid';

        picker.querySelectorAll('[data-device-icon]').forEach(button => {
            button.addEventListener('click', evt => {
                evt.preventDefault();
                evt.stopPropagation();

                if (!selected || selected.type !== 'item') {
                    closeDeviceIconPicker();
                    return;
                }

                const obj = findEntity('item', selected.id);
                if (!obj) {
                    closeDeviceIconPicker();
                    return;
                }

                obj.icon = button.dataset.deviceIcon || '';
                pushHistory();
                markDirty();
                closeDeviceIconPicker();

                // Direkt neu zeichnen. selected bleibt erhalten, daher bleibt auch
                // die Gerätekonfiguration offen und zeigt das gewählte Symbol.
                render();
            });
        });
    }

    function defaultDisplayModeForKind(kind) {
        return ['temperature','humidity'].includes(kind) ? 'value' : 'icon';
    }

    function renderProperties() {
        // Offene native <select>-Listen dürfen bei Hintergrund-render() nicht
        // ersetzt werden. Das gilt für ALLE Auswahllisten in den Eigenschaften
        // (Möbeltyp, Gerätetyp, Symbol, Profil-nahe Auswahlfelder usw.).
        //
        // document.activeElement allein reicht nicht in allen Browsern/HTML-SDK-
        // Situationen zuverlässig aus. Daher merken wir zusätzlich, ob gerade
        // irgendein Select in der Eigenschaftenleiste aktiv benutzt wird.
        const activeElement = document.activeElement;
        const activePropertyControl =
            activeElement &&
            properties.contains(activeElement) &&
            (
                activeElement instanceof HTMLInputElement ||
                activeElement instanceof HTMLSelectElement ||
                activeElement instanceof HTMLTextAreaElement
            );

        if (propertiesControlActive || propertiesSelectOpen || activePropertyControl) {
            return;
        }
        const floor = currentFloor();

        if (!selected) {
            propTitle.textContent = 'Projekteigenschaften';
            properties.innerHTML = `
                <div class="field">
                    <label>Etagenname</label>
                    <input data-project="floorName" value="${escapeHtml(floor.name)}">
                </div>
                <div class="field">
                    <label>Snap</label>
                    <input value="${state.snap}" disabled>
                </div>
                <div class="field">
                    <label>Elemente</label>
                    <input value="${floor.walls.length} Wände, ${floor.openings.length} Öffnungen, ${floor.items.length} Geräte, ${(floor.furniture || []).length} Möbel" disabled>
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

                <div class="field">
                    <label>${obj.type === 'door' ? 'Türkontakt / Türposition' : 'Fensterkontakt / Fensterposition'}</label>
                    <input class="variable-select-field" data-variable-field="variableID" readonly
                        value="${obj.variableID ? '#' + obj.variableID + (obj._variablePath ? ' – ' + escapeHtml(obj._variablePath) : '') : 'nicht zugeordnet'}">
                </div>

                <div class="field">
                    <label>Zweiter Flügel (optional)</label>
                    <input class="variable-select-field" data-variable-field="secondaryVariableID" readonly
                        value="${obj.secondaryVariableID ? '#' + obj.secondaryVariableID + (obj._secondaryVariablePath ? ' – ' + escapeHtml(obj._secondaryVariablePath) : '') : 'nicht zugeordnet'}">
                </div>

                <div class="field">
                    <label>Rollo / Rollladen (optional)</label>
                    <input class="variable-select-field" data-variable-field="shutterVariableID" readonly
                        value="${obj.shutterVariableID ? '#' + obj.shutterVariableID + (obj._shutterVariablePath ? ' – ' + escapeHtml(obj._shutterVariablePath) : '') : 'nicht zugeordnet'}">
                </div>

                ${obj.shutterVariableID ? `
                    <div class="field">
                        <label>Rollo-Typ</label>
                        <select data-field="shutterStyle">
                            <option value="roll"${(obj.shutterStyle || 'roll') === 'roll' ? ' selected' : ''}>Roll-up / Rollladen</option>
                            <option value="swing"${obj.shutterStyle === 'swing' ? ' selected' : ''}>Klappladen</option>
                        </select>
                    </div>
                    <div class="field">
                        <label><input data-field="shutterInvert" type="checkbox"${obj.shutterInvert === true ? ' checked' : ''}> Rollo-Animation invertieren</label>
                    </div>
                    <div class="field">
                        <label>Zweiter Rollo-Flügel (optional)</label>
                        <input class="variable-select-field" data-variable-field="shutterSecondaryVariableID" readonly
                            value="${obj.shutterSecondaryVariableID ? '#' + obj.shutterSecondaryVariableID + (obj._shutterSecondaryVariablePath ? ' – ' + escapeHtml(obj._shutterSecondaryVariablePath) : '') : 'nicht zugeordnet'}">
                    </div>
                ` : ''}

                <div class="field">
                    <label><input data-field="invert" type="checkbox"${obj.invert === true ? ' checked' : ''}> ${obj.type === 'door' ? 'Tür' : 'Fenster'}-Animation invertieren</label>
                </div>
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
                    <input id="variableField" class="variable-select-field" data-variable-field="variableID" readonly title="Variable auswählen"
                        value="${obj.variableID ? '#' + obj.variableID + (obj._variablePath ? ' – ' + escapeHtml(obj._variablePath) : '') : 'nicht zugeordnet'}">
                    ${obj._profileName ? `<div class="profile-hint">Profil: ${escapeHtml(obj._profileName)}${obj._profileSummary ? ' · ' + escapeHtml(obj._profileSummary) : ''}</div>` : ''}
                </div>
                <div class="row2">
                    <div class="field"><label class="check"><input data-field="showName" type="checkbox"${obj.showName === true ? ' checked' : ''}> Name anzeigen</label></div>
                    <div class="field">
                        <label>Darstellung</label>
                        <select data-field="displayMode">
                            ${(() => {
                                const effectiveMode = ['icon','value','iconValue'].includes(obj.displayMode)
                                    ? obj.displayMode
                                    : (['temperature','humidity'].includes(kind) ? 'value' : 'icon');
                                return `
                                    <option value="icon"${effectiveMode === 'icon' ? ' selected' : ''}>Symbol</option>
                                    <option value="value"${effectiveMode === 'value' ? ' selected' : ''}>Wert</option>
                                    <option value="iconValue"${effectiveMode === 'iconValue' ? ' selected' : ''}>Symbol + Wert</option>
                                `;
                            })()}
                        </select>
                    </div>
                </div>
                <div class="row2">
                    <div class="field"><label>Schriftgröße</label><input data-field="labelSize" type="number" min="8" max="40" value="${obj.labelSize || 12}"></div>
                    <div class="field">
                        <label>Beschriftung</label>
                        <select data-field="labelPosition">
                            <option value="below"${(obj.labelPosition || 'below') === 'below' ? ' selected' : ''}>unten</option>
                            <option value="left"${obj.labelPosition === 'left' ? ' selected' : ''}>links</option>
                            <option value="right"${obj.labelPosition === 'right' ? ' selected' : ''}>rechts</option>
                        </select>
                    </div>
                </div>
                <div class="field"><label>Symbol (für Symbol-Anzeige)</label><input class="icon-input-clickable" data-icon-picker="device" readonly data-field="icon" title="Klicken, um Original-MDI auszuwählen" value="${escapeHtml(obj.icon || '')}" placeholder="${escapeHtml(iconForKind(kind))}"></div>
                <div class="row2">
                    <div class="field"><label>X</label><input data-field="x" type="number" value="${obj.x}"></div>
                    <div class="field"><label>Y</label><input data-field="y" type="number" value="${obj.y}"></div>
                </div>
                <div class="row2">
                    <div class="field"><label>Symbolgröße</label><input data-field="size" type="number" min="8" max="80" value="${obj.size || 18}"></div>
                    <div class="field"><label>Drehung</label><input data-field="angle" type="number" min="-360" max="360" step="5" value="${Number(obj.angle) || 0}"></div>
                </div>
            `;
        } else if (selected.type === 'furniture') {
            propTitle.textContent = 'Möbel';
            const ftype = obj.type || 'sofa';
            properties.innerHTML = `
                <div class="field">
                    <label>Möbeltyp</label>
                    <select data-field="type">
                        ${Object.entries(furnitureTemplates)
                            .map(([key,tpl]) => `<option value="${key}"${key === ftype ? ' selected' : ''}>${escapeHtml(tpl.name)}</option>`)
                            .join('')}
                    </select>
                </div>
                <div class="field">
                    <label>Name</label>
                    <input data-field="name" value="${escapeHtml(obj.name || furnitureTemplates[ftype]?.name || 'Möbel')}">
                </div>
                <label class="check"><input data-field="showName" type="checkbox"${obj.showName === true ? ' checked' : ''}> Name anzeigen</label>
                <div class="row2">
                    <div class="field">
                        <label>X</label>
                        <input data-field="x" type="number" value="${Number(obj.x) || 0}">
                    </div>
                    <div class="field">
                        <label>Y</label>
                        <input data-field="y" type="number" value="${Number(obj.y) || 0}">
                    </div>
                </div>
                <div class="row2">
                    <div class="field">
                        <label>Breite</label>
                        <input data-field="width" type="number" min="8" step="1" value="${Number(obj.width) || 100}">
                    </div>
                    <div class="field">
                        <label>Tiefe</label>
                        <input data-field="height" type="number" min="8" step="1" value="${Number(obj.height) || 60}">
                    </div>
                </div>
                <div class="field">
                    <label>Drehung</label>
                    <input data-field="rotation" type="number" min="-360" max="360" step="5" value="${Number(obj.rotation) || 0}">
                </div>
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

                let value = input.type === 'checkbox' ? input.checked : input.value;
                if (input.type === 'number') value = Number(value);
                const fieldName = input.dataset.field;
                const oldFurnitureType = selected.type === 'furniture' ? (obj.type || 'sofa') : null;
                obj[fieldName] = value;

                if (selected.type === 'furniture' && fieldName === 'type') {
                    const oldTpl = furnitureTemplates[oldFurnitureType];
                    const newTpl = furnitureTemplates[value];
                    if (newTpl) {
                        if (!obj.name || (oldTpl && obj.name === oldTpl.name)) obj.name = newTpl.name;

                        const oldWidth = Number(obj.width);
                        const oldHeight = Number(obj.height);
                        const wasHalfDefault =
                            oldTpl &&
                            Math.abs(oldWidth - Number(oldTpl.size?.w) * 0.5) < 0.001 &&
                            Math.abs(oldHeight - Number(oldTpl.size?.h) * 0.5) < 0.001;
                        const wasFullDefault =
                            oldTpl &&
                            Math.abs(oldWidth - Number(oldTpl.size?.w)) < 0.001 &&
                            Math.abs(oldHeight - Number(oldTpl.size?.h)) < 0.001;

                        if (!obj.width || !obj.height || wasHalfDefault) {
                            obj.width = Number(newTpl.size.w) * 0.5;
                            obj.height = Number(newTpl.size.h) * 0.5;
                        } else if (wasFullDefault) {
                            obj.width = Number(newTpl.size.w);
                            obj.height = Number(newTpl.size.h);
                        }
                    }

                    // Auswahl ist abgeschlossen: erst jetzt darf die Eigenschaftsansicht
                    // neu aufgebaut werden.
                    input.blur();
                }

                pushHistory();
                markDirty();
                render();
            });
        });

        properties.querySelectorAll('.variable-select-field[data-variable-field]').forEach(field => {
            field.addEventListener('click', () => {
                if (!selected) return;
                variablePickerTarget = {
                    floorId: state.activeFloor,
                    entityType: selected.type,
                    entityId: selected.id,
                    field: field.dataset.variableField || 'variableID'
                };
                statusEl.textContent = 'Objektbaum wird geladen …';
                requestAction('getObjectTree', '');
            });
        });

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
    document.getElementById('zoomOutBtn').addEventListener('click', () => zoomManual(1 / 1.2));
    document.getElementById('zoomInBtn').addEventListener('click', () => zoomManual(1.2));
    document.getElementById('homeViewBtn').addEventListener('click', resetCurrentFloorView);

    function scheduleResponsiveFit() {
        if (resizeFitTimer) {
            clearTimeout(resizeFitTimer);
        }

        resizeFitTimer = setTimeout(() => {
            const rect = svg.getBoundingClientRect();
            if (!rect.width || !rect.height) return;

            const widthChanged = Math.abs(rect.width - lastTileWidth) > 1;
            const heightChanged = Math.abs(rect.height - lastTileHeight) > 1;

            lastTileWidth = rect.width;
            lastTileHeight = rect.height;

            if (widthChanged || heightChanged) {
                const saved = floorViews.get(state.activeFloor);
                if (!saved || saved.autoFit !== false) {
                    fit();
                } else {
                    setTransform();
                    render();
                }
            }
        }, 80);
    }

    const tileResizeObserver = new ResizeObserver(() => {
        scheduleResponsiveFit();
    });

    tileResizeObserver.observe(svg);

    window.addEventListener('resize', scheduleResponsiveFit);


    document.addEventListener('click', evt => {
        const iconInput = evt.target.closest('[data-icon-picker="device"]');
        if (iconInput) {
            evt.preventDefault();
            evt.stopPropagation();
            openDeviceIconPicker(iconInput);
            return;
        }

        if (!evt.target.closest('#deviceIconPicker')) {
            closeDeviceIconPicker();
        }
    });

    const showGridVisu = document.getElementById('showGridVisu');
    const gridSizeVisu = document.getElementById('gridSizeVisu');

    if (showGridVisu) {
        showGridVisu.checked = editorShowGrid;
        showGridVisu.addEventListener('change', () => {
            editorShowGrid = showGridVisu.checked;
            state.showGrid = editorShowGrid;
            render();
            markDirty();
        });
    }

    if (gridSizeVisu) {
        gridSizeVisu.value = editorGridSize;
        gridSizeVisu.addEventListener('input', () => {
            editorGridSize = Math.max(2, Number(gridSizeVisu.value) || 20);
            state.grid = editorGridSize;
            render();
            markDirty();
        });
    }

    document.getElementById('finishBtn').addEventListener('click', () => setMode('view'));
    document.getElementById('editBtn').addEventListener('click', () => setMode('edit'));

    liveFloorSelect?.addEventListener('change', () => {
        switchFloorView(liveFloorSelect.value);
    });

    document.getElementById('deleteFloorBtn')?.addEventListener('click', () => {
        const floor = currentFloor();
        if (!floor) return;

        if (!confirm(`Etage "${floor.name}" wirklich komplett löschen?`)) {
            return;
        }

        const index = state.floors.findIndex(f => f.id === floor.id);
        if (index < 0) return;

        state.floors.splice(index, 1);
        floorViews.delete(floor.id);
        floorHomeViews.delete(floor.id);

        // Der Editor benötigt immer mindestens eine Etage.
        // Wird die letzte gelöscht, entsteht eine wirklich leere neue Etage.
        if (state.floors.length === 0) {
            const replacement = {
                id: uid('floor'),
                name: 'Erdgeschoss',
                walls: [],
                openings: [],
                items: [],
                texts: [],
                furniture: [],
                areas: [],
                trackers: []
            };
            state.floors.push(replacement);
            state.activeFloor = replacement.id;
        } else {
            state.activeFloor = state.floors[Math.min(index, state.floors.length - 1)].id;
        }

        selected = null;
        wallStart = null;
        pushHistory();
        markDirty();
        renderAll();
        requestAnimationFrame(restoreCurrentFloorViewOrFit);
    });

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
        rememberCurrentFloorView(false);
        state.floors.push(floor);
        state.activeFloor = floor.id;
        selected = null;
        pushHistory();
        markDirty();
        render();
        requestAnimationFrame(restoreCurrentFloorViewOrFit);
    });

    floorSelect.addEventListener('change', () => {
        switchFloorView(floorSelect.value);
    });

    svg.addEventListener('pointerdown', evt => {
        if (evt.button === 1) {
            drag = {mode: 'pan', x: evt.clientX, y: evt.clientY, panX, panY};
            svg.setPointerCapture(evt.pointerId);
            return;
        }

        if (evt.button !== 0) return;

        const rotateHandle = evt.target.closest('[data-rotate-type]');
        const resizeHandle = evt.target.closest('[data-resize-type]');
        const target = evt.target.closest('[data-type]');
        const p = svgPoint(evt);
        const floor = currentFloor();

        if (state.mode !== 'view' && tool === 'pan') {
            drag = {mode: 'pan', x: evt.clientX, y: evt.clientY, panX, panY};
            svg.setPointerCapture(evt.pointerId);
            evt.preventDefault();
            return;
        }

        if (state.mode !== 'view' && rotateHandle) {
            const rotateType = rotateHandle.dataset.rotateType;
            const id = rotateHandle.dataset.id;
            const obj = findEntity(rotateType, id);

            if (obj && rotateType === 'furniture') {
                const raw = svgPointRaw(evt);
                const cx = Number(obj.x) || 0;
                const cy = Number(obj.y) || 0;
                const pointerAngle = Math.atan2(raw.y - cy, raw.x - cx) * 180 / Math.PI;

                selected = {type: rotateType, id};
                drag = {
                    mode: 'rotate',
                    type: rotateType,
                    id,
                    original: structuredClone(obj),
                    angleOffset: (Number(obj.rotation) || 0) - pointerAngle
                };
                svg.setPointerCapture(evt.pointerId);
                evt.preventDefault();
                evt.stopPropagation();
                render();
                return;
            }
        }

        if (state.mode !== 'view' && resizeHandle) {
            const resizeType = resizeHandle.dataset.resizeType;
            const id = resizeHandle.dataset.id;
            const obj = findEntity(resizeType, id);

            if (obj) {
                selected = {type: resizeType, id};
                drag = {
                    mode: 'resize',
                    type: resizeType,
                    id,
                    start: p,
                    original: structuredClone(obj)
                };
                svg.setPointerCapture(evt.pointerId);
                evt.preventDefault();
                evt.stopPropagation();
                render();
                return;
            }
        }

        if (state.mode === 'view') {
            if (target && target.dataset.type === 'item') {
                const item = floor.items.find(i => i.id === target.dataset.id);

                if (item && Number(item._variableType) === 1) {
                    // Integer-Variablen behalten den kompakten Bedien-Dialog.
                    openItemControl(item, evt.clientX, evt.clientY);
                } else {
                    // Boolean-Geräte wieder über den bewährten direkten Action-Pfad
                    // bedienen. Nicht von clientseitigen Runtime-Metadaten abhängig
                    // machen: PHP prüft die echte Variable und deren Typ selbst.
                    requestAction('operate', JSON.stringify({
                        floorId: state.activeFloor,
                        itemId: target.dataset.id
                    }));
                }
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
                variableID: 0,
                secondaryVariableID: 0,
                shutterVariableID: 0,
                shutterSecondaryVariableID: 0,
                shutterStyle: 'roll',
                shutterInvert: false,
                invert: false
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
                angle: 0,
                kind: 'generic',
                showName: false,
                showState: false,
                displayMode: 'icon',
                labelSize: 12,
                labelPosition: 'below'
            };
            floor.items.push(item);
            selected = {type: 'item', id: item.id};
            pushHistory();
            markDirty();
            setTool('select');
            render();
            return;
        }

        if (tool === 'furniture') {
            const tpl = furnitureTemplates.sofa;
            const furniture = {
                id: uid('furniture'),
                type: 'sofa',
                name: tpl.name,
                x: p.x,
                y: p.y,
                width: Number(tpl.size.w) * 0.5,
                height: Number(tpl.size.h) * 0.5,
                rotation: 0,
                showName: false
            };
            floor.furniture = Array.isArray(floor.furniture) ? floor.furniture : [];
            floor.furniture.push(furniture);
            selected = {type: 'furniture', id: furniture.id};
            pushHistory();
            markDirty();
            setTool('select');
            renderAll();
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
            rememberCurrentFloorView(false);
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
            } else if (drag.type === 'opening') {
                const activeFloor = currentFloor();
                const wall = activeFloor.walls.find(w => w.id === drag.original.wallId);
                if (!wall) return;

                const vx = Number(wall.x2) - Number(wall.x1);
                const vy = Number(wall.y2) - Number(wall.y1);
                const length2 = vx * vx + vy * vy;
                if (length2 <= 0) return;

                // Maus auf die zugehörige Wand projizieren. Es bewegt sich nur
                // Tür/Fenster in der Wand; die Wand selbst bleibt unverändert.
                let position =
                    ((p.x - Number(wall.x1)) * vx + (p.y - Number(wall.y1)) * vy) /
                    length2;

                const wallLength = Math.sqrt(length2);
                const halfOpening = Math.min(
                    Math.max(10, Number(obj.length || 80) / 2),
                    wallLength / 2
                );
                const edge = wallLength > 0 ? halfOpening / wallLength : 0;

                position = Math.max(edge, Math.min(1 - edge, position));
                obj.position = Math.round(position * 10000) / 10000;
            } else if (drag.type === 'item' || drag.type === 'text' || drag.type === 'furniture') {
                obj.x = snapValue(drag.original.x + dx);
                obj.y = snapValue(drag.original.y + dy);
            }
            render();
            return;
        }

        if (drag.mode === 'rotate' && drag.original) {
            const obj = findEntity(drag.type, drag.id);
            if (!obj || drag.type !== 'furniture') return;

            const raw = svgPointRaw(evt);
            const cx = Number(drag.original.x) || 0;
            const cy = Number(drag.original.y) || 0;
            const pointerAngle = Math.atan2(raw.y - cy, raw.x - cx) * 180 / Math.PI;
            let rotation = pointerAngle + Number(drag.angleOffset || 0);

            // Auf -180..180 normalisieren, damit der gespeicherte Wert handlich bleibt.
            rotation = ((rotation + 180) % 360 + 360) % 360 - 180;
            obj.rotation = Math.round(rotation * 10) / 10;
            render();
            return;
        }

        if (drag.mode === 'resize' && drag.original) {
            const obj = findEntity(drag.type, drag.id);
            if (!obj) return;

            if (drag.type === 'furniture') {
                const cx = Number(drag.original.x) || 0;
                const cy = Number(drag.original.y) || 0;
                const angle = -(Number(drag.original.rotation) || 0) * Math.PI / 180;
                const dx = p.x - cx;
                const dy = p.y - cy;

                // Mausposition in das lokale, gedrehte Möbelsystem zurückrechnen.
                const localX = dx * Math.cos(angle) - dy * Math.sin(angle);
                const localY = dx * Math.sin(angle) + dy * Math.cos(angle);

                obj.width = Math.max(8, Math.round(Math.abs(localX) * 2));
                obj.height = Math.max(8, Math.round(Math.abs(localY) * 2));
            } else if (drag.type === 'item') {
                const cx = Number(drag.original.x) || 0;
                const cy = Number(drag.original.y) || 0;
                const radius = Math.hypot(p.x - cx, p.y - cy);
                obj.size = Math.max(8, Math.min(80, Math.round(radius)));
            } else if (drag.type === 'text') {
                const originX = Number(drag.original.x) || 0;
                const textValue = String(drag.original.text || 'Text');
                const chars = Math.max(1, textValue.length);
                const width = Math.max(10, p.x - originX);

                // Breite zurück in eine passende Schriftgröße umrechnen.
                obj.size = Math.max(6, Math.min(120, Math.round(width / (chars * 0.65))));
            } else if (drag.type === 'opening') {
                const floor = currentFloor();
                const wall = floor.walls.find(w => w.id === drag.original.wallId);
                if (!wall) return;

                const vx = Number(wall.x2) - Number(wall.x1);
                const vy = Number(wall.y2) - Number(wall.y1);
                const wallLength = Math.hypot(vx, vy);
                if (wallLength <= 0) return;

                const ux = vx / wallLength;
                const uy = vy / wallLength;
                const centerPos = Math.max(0, Math.min(1, Number(drag.original.position ?? .5)));
                const cx = Number(wall.x1) + vx * centerPos;
                const cy = Number(wall.y1) + vy * centerPos;
                const raw = svgPointRaw(evt);

                // Wie beim Fenster: Griff entlang der Wand ziehen. Für die
                // Länge bewusst OHNE Raster-Snap rechnen, damit auch Türen
                // bei kleinen Mausbewegungen sofort reagieren.
                const projectedHalf = Math.abs((raw.x - cx) * ux + (raw.y - cy) * uy);
                const maxHalf = Math.min(centerPos * wallLength, (1 - centerPos) * wallLength);
                const maxLength = Math.max(20, maxHalf * 2);

                obj.length = Math.max(
                    20,
                    Math.min(maxLength, Math.round(projectedHalf * 2))
                );
            }

            render();
            return;
        }
    });

    svg.addEventListener('pointerup', evt => {
        if (!drag) return;

        if (drag.mode === 'move' || drag.mode === 'resize' || drag.mode === 'rotate') {
            pushHistory();
            markDirty();
        }

        try { svg.releasePointerCapture(evt.pointerId); } catch (_) {}
        drag = null;
    });

    // Absichtlich kein Mausrad-Zoom: Die Visualisierungskachel soll das
    // normale Scrollen der Oberfläche nicht abfangen. Manuelles Zoomen
    // erfolgt über die −/+ Schaltflächen; Einpassen bleibt zusätzlich erhalten.

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

    function treeIcon(node) {
        switch (Number(node.objectType)) {
            case 0: return '▾';
            case 1: return '◇';
            case 2: return '●';
            case 3: return '⌁';
            case 4: return '▣';
            case 5: return '▤';
            case 6: return '↗';
            default: return '•';
        }
    }

    function nodeMatches(node, needle) {
        if (!needle) return true;
        const hay = [
            node.id,
            node.name,
            node.path,
            node.valueText,
            node.variableTypeName,
            node.profileName
        ].join(' ').toLowerCase();
        if (hay.includes(needle)) return true;
        return Array.isArray(node.children) && node.children.some(child => nodeMatches(child, needle));
    }

    function currentPickerVariableID() {
        if (!variablePickerTarget) return 0;
        const floor = state.floors.find(f => f.id === variablePickerTarget.floorId);
        if (!floor) return 0;

        const entityType = variablePickerTarget.entityType || 'item';
        const entityId = variablePickerTarget.entityId || variablePickerTarget.itemId || '';
        const field = variablePickerTarget.field || 'variableID';

        const entity = entityType === 'opening'
            ? floor.openings?.find(o => o.id === entityId)
            : floor.items?.find(i => i.id === entityId);

        return Number(entity?.[field] || 0);
    }

    function renderTreeNode(node, depth, needle, currentVariableID) {
        if (!nodeMatches(node, needle)) return '';

        const children = Array.isArray(node.children) ? node.children : [];
        const hasChildren = children.length > 0;
        const isVariable = Number(node.objectType) === 2;
        const forceOpen = !!needle;
        const isOpen = forceOpen || expandedObjectIDs.has(Number(node.id));
        const selectedClass = isVariable && Number(node.id) === Number(currentVariableID) ? ' selected-variable' : '';
        const rowClass = isVariable ? ' variable' : '';
        const toggle = hasChildren ? (isOpen ? '▾' : '▸') : '';
        const value = isVariable ? escapeHtml(node.valueText || '') : '';
        const typeTitle = isVariable
            ? escapeHtml([node.variableTypeName || '', node.profileName || ''].filter(Boolean).join(' · '))
            : escapeHtml(node.objectTypeName || '');

        let html = `
            <div class="tree-node">
                <div class="tree-row${rowClass}${selectedClass}" style="--depth:${depth}" data-object-id="${node.id}" data-object-type="${node.objectType}" title="${escapeHtml(node.path || node.name || '')}">
                    <div class="tree-toggle" data-tree-toggle="${node.id}">${toggle}</div>
                    <div class="tree-icon">${treeIcon(node)}</div>
                    <div class="tree-name">${escapeHtml(node.name || ('Objekt ' + node.id))}</div>
                    <div class="tree-id">#${node.id}</div>
                    <div class="tree-value" title="${typeTitle}">${value || typeTitle}</div>
                </div>`;

        if (hasChildren) {
            const childHtml = children.map(child => renderTreeNode(child, depth + 1, needle, currentVariableID)).join('');
            html += `<div class="tree-children${isOpen ? '' : ' collapsed'}">${childHtml}</div>`;
        }

        html += '</div>';
        return html;
    }

    function renderObjectTree(filter = '') {
        const needle = String(filter || '').trim().toLowerCase();
        const currentVariableID = currentPickerVariableID();

        const html = objectTree
            .map(node => renderTreeNode(node, 0, needle, currentVariableID))
            .join('');

        variableList.innerHTML = `<div class="object-tree">${html || '<div class="tree-empty">Keine passenden Objekte gefunden.</div>'}</div>`;

        variableList.querySelectorAll('[data-tree-toggle]').forEach(toggle => {
            toggle.addEventListener('click', evt => {
                evt.stopPropagation();
                const id = Number(toggle.dataset.treeToggle);
                if (expandedObjectIDs.has(id)) expandedObjectIDs.delete(id);
                else expandedObjectIDs.add(id);
                renderObjectTree(variableSearch.value);
            });
        });

        variableList.querySelectorAll('.tree-row.variable').forEach(row => {
            row.addEventListener('click', () => assignVariable(Number(row.dataset.objectId)));
        });

        variableList.querySelectorAll('.tree-row:not(.variable)').forEach(row => {
            row.addEventListener('dblclick', () => {
                const id = Number(row.dataset.objectId);
                if (expandedObjectIDs.has(id)) expandedObjectIDs.delete(id);
                else expandedObjectIDs.add(id);
                renderObjectTree(variableSearch.value);
            });
        });
    }

    function findTreeNode(nodes, id) {
        for (const node of nodes) {
            if (Number(node.id) === Number(id)) return node;
            if (Array.isArray(node.children)) {
                const found = findTreeNode(node.children, id);
                if (found) return found;
            }
        }
        return null;
    }

    function assignVariable(variableID) {
        if (!variablePickerTarget) return;

        const floor = state.floors.find(f => f.id === variablePickerTarget.floorId);
        if (!floor) return;

        const entityType = variablePickerTarget.entityType || 'item';
        const entityId = variablePickerTarget.entityId || variablePickerTarget.itemId || '';
        const field = variablePickerTarget.field || 'variableID';

        let entity = null;
        if (entityType === 'opening') {
            entity = floor.openings?.find(o => o.id === entityId) || null;
        } else {
            entity = floor.items?.find(i => i.id === entityId) || null;
        }
        if (!entity) return;

        entity[field] = Number(variableID) || 0;
        const node = entity[field] ? findTreeNode(objectTree, entity[field]) : null;

        const map = {
            variableID: '',
            secondaryVariableID: 'secondaryVariable',
            shutterVariableID: 'shutterVariable',
            shutterSecondaryVariableID: 'shutterSecondaryVariable'
        };
        const prefix = map[field] ?? field.replace(/ID$/, '');

        const key = suffix => prefix ? `_${prefix}${suffix}` : `_${suffix.charAt(0).toLowerCase()}${suffix.slice(1)}`;
        const pathKey = prefix ? `_${prefix}Path` : '_variablePath';
        const valueKey = prefix ? `_${prefix}ValueText` : '_valueText';
        const rawKey = prefix ? `_${prefix}RawValue` : '_rawValue';
        const typeKey = prefix ? `_${prefix}Type` : '_variableType';
        const profileNameKey = prefix ? `_${prefix}ProfileName` : '_profileName';
        const profileSummaryKey = prefix ? `_${prefix}ProfileSummary` : '_profileSummary';
        const profileKey = prefix ? `_${prefix}Profile` : '_profile';

        entity[pathKey] = node?.path || '';
        entity[valueKey] = node?.valueText || '';
        entity[rawKey] = node?.rawValue ?? '';
        entity[typeKey] = Number.isFinite(Number(node?.variableType)) ? Number(node.variableType) : -1;
        entity[profileNameKey] = node?.profileName || '';
        entity[profileSummaryKey] = node?.profileSummary || '';
        entity[profileKey] = node?.profile || null;

        variableModal.classList.remove('open');
        variableModal.setAttribute('aria-hidden', 'true');
        pushHistory();
        markDirty();
        render();
    }

    if (!variableModal || !variableList || !variableSearch) {
        throw new Error('Floorplaner: Variablen-Auswahldialog fehlt im HTML.');
    }

    variableSearch.addEventListener('input', () => renderObjectTree(variableSearch.value));
    document.getElementById('variableCloseBtn').addEventListener('click', () => {
        variableModal.classList.remove('open');
        variableModal.setAttribute('aria-hidden', 'true');
    });
    document.getElementById('variableClearBtn').addEventListener('click', () => assignVariable(0));
    variableModal.addEventListener('click', evt => {
        if (evt.target === variableModal) {
            variableModal.classList.remove('open');
            variableModal.setAttribute('aria-hidden', 'true');
        }
    });

    function sendItemValue(item, value) {
        requestAction('operateValue', JSON.stringify({
            floorId: state.activeFloor,
            itemId: item.id,
            value
        }));
    }

    function openItemControl(item, clientX = null, clientY = null) {
        if (!controlModal || !controlBody) return;

        const profile = item._profile || {};
        const associations = Array.isArray(profile.associations) ? profile.associations : [];
        const raw = Number(item._rawValue);
        controlTitle.textContent = item.name || 'Gerät bedienen';

        let html = '';

        if (associations.length) {
            html += '<div class="control-associations">';
            for (const association of associations) {
                const value = Number(association.value);
                const current = Number.isFinite(raw) && raw === value ? ' current' : '';
                html += `<button type="button" class="${current.trim()}" data-control-value="${value}">${escapeHtml(association.name || String(value))}</button>`;
            }
            html += '</div>';
        }

        if (!html) {
            html = '<div class="profile-hint">Für diese Integer-Variable sind im Profil keine bedienbaren Werte hinterlegt.</div>';
        }

        controlBody.innerHTML = html;

        controlBody.querySelectorAll('[data-control-value]').forEach(btn => {
            btn.addEventListener('click', () => {
                sendItemValue(item, Number(btn.dataset.controlValue));
                controlModal.classList.remove('open');
                controlModal.setAttribute('aria-hidden', 'true');
            });
        });

        controlModal.classList.add('open');
        controlModal.setAttribute('aria-hidden', 'false');

        const dialog = controlModal.querySelector('.control-modal');
        if (dialog) {
            dialog.style.left = '';
            dialog.style.top = '';
            dialog.style.right = '';
            dialog.style.bottom = '';

            requestAnimationFrame(() => {
                const x = Number(clientX);
                const y = Number(clientY);
                if (!Number.isFinite(x) || !Number.isFinite(y)) return;

                const margin = 8;
                const offset = 10;
                const rect = dialog.getBoundingClientRect();

                let left = x + offset;
                let top = y + offset;

                if (left + rect.width > window.innerWidth - margin) {
                    left = x - rect.width - offset;
                }
                if (top + rect.height > window.innerHeight - margin) {
                    top = y - rect.height - offset;
                }

                left = Math.max(margin, Math.min(left, window.innerWidth - rect.width - margin));
                top = Math.max(margin, Math.min(top, window.innerHeight - rect.height - margin));

                dialog.style.left = `${left}px`;
                dialog.style.top = `${top}px`;
            });
        }
    }

    controlCloseBtn?.addEventListener('click', () => {
        controlModal.classList.remove('open');
        controlModal.setAttribute('aria-hidden', 'true');
    });
    controlModal?.addEventListener('click', evt => {
        if (evt.target === controlModal) {
            controlModal.classList.remove('open');
            controlModal.setAttribute('aria-hidden', 'true');
        }
    });

    window.handleMessage = message => {
        try {
            const data = typeof message === 'string' ? JSON.parse(message) : message;
            if (data?.command === 'reloadHtml') {
                window.location.reload();
                return;
            }
            if (data?.type === 'variableUpdate' && data.variableID && data.meta) {
                const variableID = Number(data.variableID);
                const meta = data.meta || {};

                for (const floor of state.floors || []) {
                            for (const item of floor.items || []) {
                        if (Number(item.variableID || 0) === variableID) {
                            Object.assign(item, meta);
                        }
                    }

                    for (const opening of floor.openings || []) {
                        const mappings = [
                            ['variableID', ''],
                            ['secondaryVariableID', 'secondaryVariable'],
                            ['shutterVariableID', 'shutterVariable'],
                            ['shutterSecondaryVariableID', 'shutterSecondaryVariable']
                        ];

                        for (const [field, prefix] of mappings) {
                            if (Number(opening[field] || 0) !== variableID) continue;

                            for (const [key, value] of Object.entries(meta)) {
                                if (!key.startsWith('_')) continue;
                                const suffix = key.slice(1);
                                const targetKey = prefix
                                    ? `_${prefix}${suffix.charAt(0).toUpperCase()}${suffix.slice(1)}`
                                    : key;
                                opening[targetKey] = value;
                            }
                        }
                    }
                }

                // Nur neu zeichnen, kein normalizeProject(), kein fit(), kein Etagenwechsel.
                render();
                return;
            }

            if (data?.type === 'project' && data.project) {
                // Runtime-Updates dürfen die im Live-Modus gewählte Etage
                // nicht auf die im gespeicherten Projekt hinterlegte Etage zurücksetzen.
                const currentFloorID = state?.activeFloor || '';
                const currentMode = state?.mode || 'view';

                state = normalizeProject(data.project);

                if (currentFloorID && state.floors.some(f => f.id === currentFloorID)) {
                    state.activeFloor = currentFloorID;
                }

                // Auch ein reines Variablen-Update darf den aktuellen Live/Edit-Modus
                // des geöffneten Clients nicht verändern.
                state.mode = currentMode;

                selected = null;
                wallStart = null;
                history = [];
                historyIndex = -1;
                pushHistory();
                updateModeUI();
                renderAll();
                fit();
            } else if (data?.type === 'objectTree' && Array.isArray(data.objects)) {
                objectTree = data.objects;
                variableSearch.value = '';
                renderObjectTree('');
                variableModal.classList.add('open');
                variableModal.setAttribute('aria-hidden', 'false');
                variableSearch.focus();
                statusEl.textContent = 'Objektbaum – Variable auswählen';
            } else if (data?.type === 'runtimeValue') {
                const floor = state.floors.find(f => f.id === data.floorId);
                const item = floor?.items.find(i => i.id === data.itemId);
                if (item) {
                    item._valueText = data.valueText || '';
                    if ('rawValue' in data) item._rawValue = data.rawValue;
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
    detectTheme();
    renderAll();
    requestAnimationFrame(fit);
    detectTheme();
    window.addEventListener('load', detectTheme);

    const mediaTheme = window.matchMedia
        ? window.matchMedia('(prefers-color-scheme: dark)')
        : null;

    if (mediaTheme) {
        if (typeof mediaTheme.addEventListener === 'function') {
            mediaTheme.addEventListener('change', () => {
                detectTheme();
                render();
            });
        } else if (typeof mediaTheme.addListener === 'function') {
            mediaTheme.addListener(() => {
                detectTheme();
                render();
            });
        }
    }

    // Symcon kann die injizierte --content-color während eines Themewechsels ändern.
    setInterval(() => {
        const before = document.documentElement.getAttribute('data-theme');
        detectTheme();
        if (before !== document.documentElement.getAttribute('data-theme')) {
            render();
        }
    }, 1000);

})();
</script>

    <div id="deviceIconPicker" class="icon-picker" aria-label="Symbol auswählen"></div>

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

            case 'getObjectTree':
                $message = json_encode(
                    [
                        'type'    => 'objectTree',
                        'objects' => $this->BuildObjectTree()
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

            case 'operateValue':
                if (!is_string($Value)) {
                    throw new InvalidArgumentException('Ungültiger Bedienwert.');
                }
                $request = json_decode($Value, true);
                if (!is_array($request)) {
                    throw new InvalidArgumentException('Ungültiger Bedienwert.');
                }
                $this->OperateItemValue(
                    (string) ($request['floorId'] ?? ''),
                    (string) ($request['itemId'] ?? ''),
                    $request['value'] ?? null
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

    private function RegisterRuntimeVariableMessages(): void
    {
        $project = $this->GetProject();
        $ids = [];

        foreach (($project['floors'] ?? []) as $floor) {
            foreach (($floor['items'] ?? []) as $item) {
                $id = (int) ($item['variableID'] ?? 0);
                if ($id > 0 && IPS_VariableExists($id)) {
                    $ids[$id] = true;
                }
            }

            foreach (($floor['openings'] ?? []) as $opening) {
                foreach ([
                    'variableID',
                    'secondaryVariableID',
                    'shutterVariableID',
                    'shutterSecondaryVariableID'
                ] as $field) {
                    $id = (int) ($opening[$field] ?? 0);
                    if ($id > 0 && IPS_VariableExists($id)) {
                        $ids[$id] = true;
                    }
                }
            }
        }

        foreach (array_keys($ids) as $id) {
            $this->RegisterMessage((int) $id, VM_UPDATE);
        }
    }

    private function AddRuntimeValues(array $Project): array
    {
        if (!isset($Project['floors']) || !is_array($Project['floors'])) {
            return $Project;
        }

        foreach ($Project['floors'] as $floorIndex => $floor) {
            if (isset($floor['items']) && is_array($floor['items'])) {
                foreach ($floor['items'] as $itemIndex => $item) {
                    $variableID = (int) ($item['variableID'] ?? 0);
                    if ($variableID <= 0 || !IPS_VariableExists($variableID)) {
                        continue;
                    }

                    try {
                        $meta = $this->GetVariableRuntimeMeta($variableID);
                        foreach ($meta as $key => $value) {
                            $Project['floors'][$floorIndex]['items'][$itemIndex][$key] = $value;
                        }
                    } catch (Throwable $e) {
                        $this->SendDebug('RuntimeValue', $e->getMessage(), 0);
                    }
                }
            }

            if (isset($floor['openings']) && is_array($floor['openings'])) {
                foreach ($floor['openings'] as $openingIndex => $opening) {
                    $fieldMap = [
                        'variableID'                 => '',
                        'secondaryVariableID'        => 'secondaryVariable',
                        'shutterVariableID'          => 'shutterVariable',
                        'shutterSecondaryVariableID' => 'shutterSecondaryVariable'
                    ];

                    foreach ($fieldMap as $field => $prefix) {
                        $variableID = (int) ($opening[$field] ?? 0);
                        if ($variableID <= 0 || !IPS_VariableExists($variableID)) {
                            continue;
                        }

                        try {
                            $meta = $this->GetVariableRuntimeMeta($variableID);
                            foreach ($meta as $key => $value) {
                                $suffix = ltrim($key, '_');

                                if ($prefix === '') {
                                    $targetKey = $key;
                                } else {
                                    $mapSuffix = [
                                        'variableType'   => 'Type',
                                        'variablePath'   => 'Path',
                                        'rawValue'       => 'RawValue',
                                        'valueText'      => 'ValueText',
                                        'profileName'    => 'ProfileName',
                                        'profileSummary' => 'ProfileSummary',
                                        'profile'        => 'Profile'
                                    ];
                                    $targetKey = '_' . $prefix . ($mapSuffix[$suffix] ?? ucfirst($suffix));
                                }
                                $Project['floors'][$floorIndex]['openings'][$openingIndex][$targetKey] = $value;
                            }
                        } catch (Throwable $e) {
                            $this->SendDebug('RuntimeOpeningValue', $e->getMessage(), 0);
                        }
                    }
                }
            }
        }

        return $Project;
    }

    private function BuildObjectTree(): array
    {
        return $this->BuildObjectChildren(0, '');
    }

    private function BuildObjectChildren(int $ParentID, string $ParentPath): array
    {
        $result = [];

        foreach (IPS_GetChildrenIDs($ParentID) as $objectID) {
            if (!IPS_ObjectExists($objectID)) {
                continue;
            }

            $object = IPS_GetObject($objectID);
            $objectType = (int) ($object['ObjectType'] ?? -1);
            $name = IPS_GetName($objectID);
            $path = ($ParentPath === '') ? $name : ($ParentPath . ' / ' . $name);

            $typeNames = [
                0 => 'Kategorie',
                1 => 'Instanz',
                2 => 'Variable',
                3 => 'Script',
                4 => 'Ereignis',
                5 => 'Medienobjekt',
                6 => 'Link'
            ];

            $node = [
                'id'             => $objectID,
                'name'           => $name,
                'path'           => $path,
                'objectType'     => $objectType,
                'objectTypeName' => $typeNames[$objectType] ?? ('Objekttyp ' . $objectType),
                'children'       => []
            ];

            if ($objectType === 2 && IPS_VariableExists($objectID)) {
                try {
                    $meta = $this->GetVariableRuntimeMeta($objectID);
                    $variableType = (int) ($meta['_variableType'] ?? -1);
                    $variableTypeNames = [
                        0 => 'Boolean',
                        1 => 'Integer',
                        2 => 'Float',
                        3 => 'String'
                    ];

                    $node['variableType'] = $variableType;
                    $node['variableTypeName'] = $variableTypeNames[$variableType] ?? ('Typ ' . $variableType);
                    $node['valueText'] = $meta['_valueText'] ?? '';
                    $node['rawValue'] = $meta['_rawValue'] ?? '';
                    $node['profileName'] = $meta['_profileName'] ?? '';
                    $node['profileSummary'] = $meta['_profileSummary'] ?? '';
                    $node['profile'] = $meta['_profile'] ?? null;
                } catch (Throwable $e) {
                    $node['valueText'] = '';
                    $this->SendDebug('ObjectTree.Variable', $e->getMessage(), 0);
                }
            }

            $children = IPS_GetChildrenIDs($objectID);
            if (count($children) > 0) {
                $node['children'] = $this->BuildObjectChildren($objectID, $path);
            }

            $result[] = $node;
        }

        usort(
            $result,
            static function (array $a, array $b): int {
                $typeOrder = [0 => 0, 1 => 1, 2 => 2, 6 => 3, 3 => 4, 5 => 5, 4 => 6];
                $ao = $typeOrder[(int) $a['objectType']] ?? 99;
                $bo = $typeOrder[(int) $b['objectType']] ?? 99;
                if ($ao !== $bo) {
                    return $ao <=> $bo;
                }
                return strnatcasecmp((string) $a['name'], (string) $b['name']);
            }
        );

        return $result;
    }

    private function GetVariableRuntimeMeta(int $VariableID): array
    {
        $variable = IPS_GetVariable($VariableID);
        $variableType = (int) ($variable['VariableType'] ?? -1);
        $rawValue = GetValue($VariableID);

        $profileName = (string) (($variable['VariableCustomProfile'] ?? '') ?: ($variable['VariableProfile'] ?? ''));
        $profile = null;
        $profileSummary = '';
        $valueText = $this->FormatRawValue($rawValue);

        if ($profileName !== '' && IPS_VariableProfileExists($profileName)) {
            try {
                $p = IPS_GetVariableProfile($profileName);
                $associations = [];

                foreach (($p['Associations'] ?? []) as $association) {
                    $associations[] = [
                        'value' => $association['Value'] ?? 0,
                        'name'  => (string) ($association['Name'] ?? ''),
                        'icon'  => (string) ($association['Icon'] ?? ''),
                        'color' => (int) ($association['Color'] ?? -1)
                    ];
                }

                $profile = [
                    'name'         => $profileName,
                    'min'          => $p['MinValue'] ?? null,
                    'max'          => $p['MaxValue'] ?? null,
                    'step'         => $p['StepSize'] ?? null,
                    'prefix'       => (string) ($p['Prefix'] ?? ''),
                    'suffix'       => (string) ($p['Suffix'] ?? ''),
                    'associations' => $associations
                ];

                $parts = [];
                if ($associations !== []) {
                    $parts[] = count($associations) . ' Stellungen';
                }
                if (isset($p['MinValue'], $p['MaxValue']) && (float) $p['MaxValue'] > (float) $p['MinValue']) {
                    $parts[] = $p['MinValue'] . '…' . $p['MaxValue'] . (string) ($p['Suffix'] ?? '');
                }
                $profileSummary = implode(' · ', $parts);

                foreach ($associations as $association) {
                    if ((float) $association['value'] === (float) $rawValue && $association['name'] !== '') {
                        $valueText = $association['name'];
                        break;
                    }
                }

                if ($valueText === $this->FormatRawValue($rawValue)) {
                    $valueText = (string) ($p['Prefix'] ?? '') . $valueText . (string) ($p['Suffix'] ?? '');
                }
            } catch (Throwable $e) {
                $this->SendDebug('VariableProfile', $profileName . ': ' . $e->getMessage(), 0);
            }
        }

        return [
            '_variableType'   => $variableType,
            '_variablePath'   => $this->GetObjectPath($VariableID),
            '_rawValue'       => $rawValue,
            '_valueText'      => $valueText,
            '_profileName'    => $profileName,
            '_profileSummary' => $profileSummary,
            '_profile'        => $profile
        ];
    }

    private function FormatRawValue(mixed $Value): string
    {
        if (is_bool($Value)) {
            return $Value ? 'Ein' : 'Aus';
        }
        if (is_float($Value)) {
            $formatted = rtrim(rtrim(number_format($Value, 3, '.', ''), '0'), '.');
            return $formatted === '-0' ? '0' : $formatted;
        }
        if (is_int($Value) || is_string($Value)) {
            return (string) $Value;
        }
        return '';
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
        $this->OperateItemValueInternal($FloorID, $ItemID, null, true);
    }

    private function OperateItemValue(string $FloorID, string $ItemID, mixed $Value): void
    {
        $this->OperateItemValueInternal($FloorID, $ItemID, $Value, false);
    }

    private function OperateItemValueInternal(string $FloorID, string $ItemID, mixed $Value, bool $ToggleBoolean): void
    {
        $project = $this->GetProject();

        foreach (($project['floors'] ?? []) as $floor) {
            if ((string) ($floor['id'] ?? '') !== $FloorID) {
                continue;
            }

            foreach (($floor['items'] ?? []) as $item) {
                if ((string) ($item['id'] ?? '') !== $ItemID) {
                    continue;
                }

                $variableID = (int) ($item['variableID'] ?? 0);
                if ($variableID <= 0 || !IPS_VariableExists($variableID)) {
                    return;
                }

                $variable = IPS_GetVariable($variableID);
                $variableType = (int) ($variable['VariableType'] ?? -1);

                if ($ToggleBoolean) {
                    if ($variableType !== 0) {
                        return;
                    }
                    $targetValue = !GetValueBoolean($variableID);
                } else {
                    if ($variableType === 1) {
                        $targetValue = (int) round((float) $Value);
                    } elseif ($variableType === 2) {
                        $targetValue = (float) $Value;
                    } elseif ($variableType === 0) {
                        $targetValue = (bool) $Value;
                    } else {
                        return;
                    }
                }

                if (!$this->DispatchVariableAction($variableID, $targetValue)) {
                    return;
                }

                // Die Bedienung wirkt nur auf das reale IP-Symcon-Gerät.
                // Die HTML-SDK-Kachel wird dabei absichtlich nicht neu gerendert.
                return;
            }
        }
    }

    private function DispatchVariableAction(int $VariableID, mixed $Value): bool
    {
        $variable = IPS_GetVariable($VariableID);
        $actionID = (int) (($variable['VariableCustomAction'] ?? 0) ?: ($variable['VariableAction'] ?? 0));

        try {
            if ($actionID > 0) {
                \RequestAction($VariableID, $Value);
                return true;
            }

            $object = IPS_GetObject($VariableID);
            $parentID = (int) ($object['ParentID'] ?? 0);
            $ident = (string) ($object['ObjectIdent'] ?? '');

            if ($parentID > 0 && $ident !== '' && IPS_InstanceExists($parentID)) {
                \IPS_RequestAction($parentID, $ident, $Value);
                return true;
            }

            if ($parentID <= 0 || !IPS_InstanceExists($parentID)) {
                SetValue($VariableID, $Value);
                return true;
            }

            $this->SendDebug('OperateItem', 'Variable #' . $VariableID . ' besitzt keine ausführbare Aktion.', 0);
        } catch (Throwable $e) {
            $this->SendDebug('OperateItem', 'Variable #' . $VariableID . ': ' . $e->getMessage(), 0);
        }

        return false;
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
