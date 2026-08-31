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
 * Easy Floorplan v1.5.5 wird als lokale Vendor-Datei im Modul mitgeführt
 * und bei ApplyChanges automatisch in den IP-Symcon-Webbereich publiziert.
 * Der vorhandene HTML-SDK-Editor bleibt während der schrittweisen
 * Home-Assistant-zu-IP-Symcon-Adapter-Portierung erhalten.
 */

class Floorplaner extends IPSModuleStrict
{
    // Portierungsbasis:
    // Easy Floorplan by Nicolas Sandller, MIT License
    // https://github.com/nicosandller/easy-floorplan
    private const EASY_FLOORPLAN_BASELINE = '1.5.5';
    private const EASY_FLOORPLAN_VENDOR_DIR = 'vendor/easy-floorplan';
    private const EASY_FLOORPLAN_JS_FILE = 'easy-floorplan-card.js';
    private const EASY_FLOORPLAN_LICENSE_FILE = 'LICENSE';
    private const EASY_FLOORPLAN_WEB_DIR = 'user/Floorplaner/vendor/easy-floorplan';
    private const EASY_FLOORPLAN_WEB_JS = '/user/Floorplaner/vendor/easy-floorplan/easy-floorplan-card.js';
    private const EASY_FLOORPLAN_WEB_LICENSE = '/user/Floorplaner/vendor/easy-floorplan/LICENSE';
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
        $this->SetBuffer('RegisteredVariables', '[]');

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

        $this->PublishEasyFloorplanAssets();
        $this->RegisterVariableMessages();

        $this->SetSummary('Floorplan Editor');

        // Nach einem Modul-/Konfigurationsupdate muss die bestehende
        // HTML-SDK-Instanz die neue GetVisualizationTile()-Version laden.
        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->ReloadHtml();
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

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message !== VM_UPDATE || IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $this->PushRuntimeValueUpdates($SenderID);
    }

    private function RegisterVariableMessages(): void
    {
        $old = json_decode($this->GetBuffer('RegisteredVariables'), true);
        if (!is_array($old)) {
            $old = [];
        }

        foreach ($old as $variableID) {
            $variableID = (int) $variableID;
            if ($variableID > 0 && IPS_VariableExists($variableID)) {
                $this->UnregisterMessage($variableID, VM_UPDATE);
            }
        }

        $ids = [];
        $project = $this->GetProject();
        foreach (($project['floors'] ?? []) as $floor) {
            foreach (($floor['items'] ?? []) as $item) {
                $id = (int) ($item['variableID'] ?? 0);
                if ($id > 0 && IPS_VariableExists($id)) {
                    $ids[$id] = true;
                }
            }
            foreach (($floor['openings'] ?? []) as $opening) {
                foreach (['variableID', 'secondaryVariableID'] as $field) {
                    $id = (int) ($opening[$field] ?? 0);
                    if ($id > 0 && IPS_VariableExists($id)) {
                        $ids[$id] = true;
                    }
                }
            }
        }

        $registered = array_map('intval', array_keys($ids));
        foreach ($registered as $variableID) {
            $this->RegisterMessage($variableID, VM_UPDATE);
        }

        $this->SetBuffer('RegisteredVariables', json_encode($registered));
    }

    private function PushRuntimeValueUpdates(int $VariableID): void
    {
        if ($VariableID <= 0 || !IPS_VariableExists($VariableID)) {
            return;
        }

        $valueText = $this->GetSafeValueText($VariableID);
        $project = $this->GetProject();

        foreach (($project['floors'] ?? []) as $floor) {
            $floorID = (string) ($floor['id'] ?? '');

            foreach (($floor['items'] ?? []) as $item) {
                if ((int) ($item['variableID'] ?? 0) === $VariableID) {
                    $this->SendRuntimeValueMessage(
                        $floorID,
                        'item',
                        (string) ($item['id'] ?? ''),
                        'variableID',
                        $valueText
                    );
                }
            }

            foreach (($floor['openings'] ?? []) as $opening) {
                foreach (['variableID', 'secondaryVariableID'] as $field) {
                    if ((int) ($opening[$field] ?? 0) === $VariableID) {
                        $this->SendRuntimeValueMessage(
                            $floorID,
                            'opening',
                            (string) ($opening['id'] ?? ''),
                            $field,
                            $valueText
                        );
                    }
                }
            }
        }
    }

    private function SendRuntimeValueMessage(
        string $FloorID,
        string $EntityType,
        string $EntityID,
        string $Field,
        string $ValueText
    ): void {
        $message = json_encode(
            [
                'type'       => 'runtimeValue',
                'floorId'    => $FloorID,
                'entityType' => $EntityType,
                'entityId'   => $EntityID,
                'field'      => $Field,
                'valueText'  => $ValueText
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($message !== false) {
            $this->UpdateVisualizationValue($message);
        }
    }

    private function PublishEasyFloorplanAssets(): void
    {
        $sourceDir = __DIR__ . DIRECTORY_SEPARATOR . self::EASY_FLOORPLAN_VENDOR_DIR;
        $targetDir = rtrim(IPS_GetKernelDir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'webfront'
            . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::EASY_FLOORPLAN_WEB_DIR);

        if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            $this->SendDebug('Easy Floorplan', 'Zielverzeichnis konnte nicht erstellt werden: ' . $targetDir, 0);
            return;
        }

        $assets = [
            self::EASY_FLOORPLAN_JS_FILE,
            self::EASY_FLOORPLAN_LICENSE_FILE
        ];

        foreach ($assets as $fileName) {
            $source = $sourceDir . DIRECTORY_SEPARATOR . $fileName;
            $target = $targetDir . DIRECTORY_SEPARATOR . $fileName;

            if (!is_file($source)) {
                $this->SendDebug('Easy Floorplan', 'Quelldatei fehlt: ' . $source, 0);
                continue;
            }

            $needsCopy = !is_file($target);
            if (!$needsCopy) {
                $sourceHash = @hash_file('sha256', $source);
                $targetHash = @hash_file('sha256', $target);
                $needsCopy = $sourceHash === false || $targetHash === false || $sourceHash !== $targetHash;
            }

            if ($needsCopy) {
                if (!@copy($source, $target)) {
                    $this->SendDebug('Easy Floorplan', 'Datei konnte nicht veröffentlicht werden: ' . $fileName, 0);
                    continue;
                }
                $this->SendDebug('Easy Floorplan', 'Veröffentlicht: ' . $fileName, 0);
            }
        }
    }

    private function GetEasyFloorplanAssetStatus(): array
    {
        $sourceDir = __DIR__ . DIRECTORY_SEPARATOR . self::EASY_FLOORPLAN_VENDOR_DIR;
        $targetDir = rtrim(IPS_GetKernelDir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'webfront'
            . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::EASY_FLOORPLAN_WEB_DIR);

        $jsSource = $sourceDir . DIRECTORY_SEPARATOR . self::EASY_FLOORPLAN_JS_FILE;
        $licenseSource = $sourceDir . DIRECTORY_SEPARATOR . self::EASY_FLOORPLAN_LICENSE_FILE;
        $jsTarget = $targetDir . DIRECTORY_SEPARATOR . self::EASY_FLOORPLAN_JS_FILE;

        return [
            'jsSource'      => is_file($jsSource),
            'licenseSource' => is_file($licenseSource),
            'jsPublished'   => is_file($jsTarget)
        ];
    }

    public function GetConfigurationForm(): string
    {
        $project = $this->GetProject();
        $counts = $this->CountElements($project);
        $assetStatus = $this->GetEasyFloorplanAssetStatus();

        $elements = [
            [
                'type'    => 'Label',
                'caption' => 'Floorplaner – Floorplan Editor für IP-Symcon'
            ],
            [
                'type'    => 'Label',
                'caption' => 'Basis: Easy Floorplan v' . self::EASY_FLOORPLAN_BASELINE . ' (MIT). Die Zeichenfläche hat keine feste Projektgröße und passt sich dynamisch an die verfügbare HTML-SDK-Fläche an.'
            ],
            [
                'type'    => 'Label',
                'caption' => 'Modulquelle JS: FLOOR/' . self::EASY_FLOORPLAN_VENDOR_DIR . '/' . self::EASY_FLOORPLAN_JS_FILE
                    . ($assetStatus['jsSource'] ? ' – vorhanden' : ' – FEHLT')
            ],
            [
                'type'    => 'Label',
                'caption' => 'Modulquelle Lizenz: FLOOR/' . self::EASY_FLOORPLAN_VENDOR_DIR . '/' . self::EASY_FLOORPLAN_LICENSE_FILE
                    . ($assetStatus['licenseSource'] ? ' – vorhanden' : ' – FEHLT')
            ],
            [
                'type'    => 'Label',
                'caption' => 'HTML-SDK Runtime: ' . self::EASY_FLOORPLAN_WEB_JS
                    . ($assetStatus['jsPublished'] ? ' – veröffentlicht' : ' – noch nicht veröffentlicht')
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
    <script type="module" src="__EASY_FLOORPLAN_JS__"></script>
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
            position: absolute;
            left: 50%;
            bottom: 10px;
            transform: translateX(-50%);
            z-index: 50;
            pointer-events: auto;
        }

        #viewbar button {
            width: 36px;
            height: 36px;
            min-width: 36px;
            min-height: 36px;
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
        .tree-row:hover { background: rgba(255,255,255,.07); }
        .tree-toggle {
            width: 22px;
            text-align: center;
            color: var(--fp-muted);
            cursor: pointer;
        }
        .tree-icon { text-align: center; }
        .tree-name {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .tree-id { color: #9fc7ff; font-family: monospace; font-size: 11px; }
        .tree-value {
            color: var(--fp-muted);
            font-size: 11px;
            max-width: 150px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .tree-row.variable { cursor: pointer; }
        .tree-row.variable.selected-variable {
            outline: 1px solid #74b9ff;
            background: rgba(116,185,255,.12);
        }
        .tree-children.collapsed { display: none; }
        .tree-empty { padding: 16px; color: var(--fp-muted); text-align: center; }

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

        .variable-select-field {
            cursor: pointer !important;
            caret-color: transparent;
        }

        .variable-select-field:hover {
            outline: 1px solid #74b9ff;
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
        <div id="easyFloorplanRuntimeState" class="status">Easy Floorplan wird geprüft …</div>
        <div id="status" class="status">Bereit</div>
    </div>

    <div id="viewbar">
        <button id="editBtn" type="button" title="Floorplan bearbeiten" aria-label="Floorplan bearbeiten">✎</button>
    </div>
</div>

<div id="variableModal" class="modal-backdrop" aria-hidden="true">
    <div class="modal">
        <h3>IP-Symcon Objektbaum</h3>
        <div class="modal-search">
            <input id="variableSearch" placeholder="Objekt, Variable oder ID suchen …">
        </div>
        <div id="variableList" class="variable-list"></div>
        <div class="modal-actions">
            <button id="variableClearBtn" type="button">Zuordnung entfernen</button>
            <button id="variableCloseBtn" type="button">Abbrechen</button>
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
    const statusEl = document.getElementById('status');
    const easyFloorplanRuntimeState = document.getElementById('easyFloorplanRuntimeState');
    const app = document.getElementById('app');
    const variableModal = document.getElementById('variableModal');
    const variableList = document.getElementById('variableList');
    const variableSearch = document.getElementById('variableSearch');

    let state = normalizeProject(initial);
    let variablePickerTarget = null;
    let objectTree = [];
    const expandedObjectIDs = new Set([0]);
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

    function variableFieldText(obj, field) {
        const id = Number(obj?.[field] || 0);
        if (!id) return 'Klicken zum Auswählen …';

        const pathKey = field === 'variableID'
            ? '_variablePath'
            : '_' + field.replace(/ID$/, '') + 'Path';
        const path = obj?.[pathKey] || '';
        return '#' + id + (path ? ' – ' + escapeHtml(path) : '');
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
                <div class="field">
                    <label>IP-Symcon Variable – Flügel / Kontakt 1</label>
                    <input class="variable-select-field"
                           data-variable-field="variableID"
                           value="${variableFieldText(obj, 'variableID')}"
                           readonly
                           title="Klicken, um den IP-Symcon Objektbaum zu öffnen">
                </div>
                <div class="field">
                    <label>IP-Symcon Variable – Flügel / Kontakt 2</label>
                    <input class="variable-select-field"
                           data-variable-field="secondaryVariableID"
                           value="${variableFieldText(obj, 'secondaryVariableID')}"
                           readonly
                           title="Optional: zweiter Flügel / zweiter Kontakt">
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
                    <input class="variable-select-field"
                           data-variable-field="variableID"
                           value="${variableFieldText(obj, 'variableID')}"
                           readonly
                           title="Klicken, um den IP-Symcon Objektbaum zu öffnen">
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

        properties.querySelectorAll('.variable-select-field[data-variable-field]').forEach(field => {
            field.addEventListener('click', () => {
                if (!selected) return;
                openVariableTree({
                    floorId: state.activeFloor,
                    entityType: selected.type,
                    entityId: selected.id,
                    field: field.dataset.variableField || 'variableID'
                });
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
                variableID: 0,
                secondaryVariableID: 0
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

    function treeIcon(node) {
        switch (Number(node.objectType)) {
            case 0: return '▾'; // Kategorie
            case 1: return '◇'; // Instanz
            case 2: return '●'; // Variable
            case 3: return '⌁'; // Script
            case 4: return '▣'; // Ereignis
            case 5: return '▤'; // Media
            case 6: return '↗'; // Link
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
            node.variableTypeName
        ].join(' ').toLowerCase();
        if (hay.includes(needle)) return true;
        return Array.isArray(node.children) && node.children.some(child => nodeMatches(child, needle));
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
        const typeTitle = isVariable ? escapeHtml(node.variableTypeName || '') : escapeHtml(node.objectTypeName || '');

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
            html += `<div class="tree-children${isOpen ? '' : ' collapsed'}" data-tree-children="${node.id}">${childHtml}</div>`;
        }
        html += '</div>';
        return html;
    }

    function renderObjectTree(filter = '') {
        const needle = String(filter || '').trim().toLowerCase();
        let currentVariableID = 0;

        if (variablePickerTarget) {
            const floor = state.floors.find(f => f.id === variablePickerTarget.floorId);
            const collection = variablePickerTarget.entityType === 'opening' ? floor?.openings : floor?.items;
            const entity = collection?.find(i => i.id === variablePickerTarget.entityId);
            currentVariableID = Number(entity?.[variablePickerTarget.field || 'variableID'] || 0);
        }

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

    function openVariableTree(target) {
        variablePickerTarget = target;
        statusEl.textContent = 'Objektbaum wird geladen …';
        requestAction('getObjectTree', '');
    }

    function assignVariable(variableID) {
        if (!variablePickerTarget) return;

        const floor = state.floors.find(f => f.id === variablePickerTarget.floorId);
        if (!floor) return;

        const collection = variablePickerTarget.entityType === 'opening'
            ? floor.openings
            : floor.items;
        const entity = collection?.find(i => i.id === variablePickerTarget.entityId);
        if (!entity) return;

        const field = variablePickerTarget.field || 'variableID';
        entity[field] = Number(variableID) || 0;

        const node = entity[field] ? findTreeNode(objectTree, entity[field]) : null;
        const pathKey = field === 'variableID'
            ? '_variablePath'
            : '_' + field.replace(/ID$/, '') + 'Path';
        const valueKey = field === 'variableID'
            ? '_valueText'
            : '_' + field.replace(/ID$/, '') + 'ValueText';

        entity[pathKey] = node?.path || '';
        entity[valueKey] = node?.valueText || '';

        variableModal.classList.remove('open');
        variableModal.setAttribute('aria-hidden', 'true');
        pushHistory();
        markDirty();
        render();
    }

    if (!variableModal || !variableList || !variableSearch) {
        throw new Error('Floorplaner: Objektbaum-Dialog fehlt im HTML.');
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

    window.handleMessage = message => {
        try {
            const data = typeof message === 'string' ? JSON.parse(message) : message;

            if (data?.command === 'reloadHtml') {
                window.location.reload();
                return;
            }

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
                if (!floor) {
                    return;
                }

                const entityType = data.entityType || 'item';
                const entityId = data.entityId || data.itemId || '';
                const field = data.field || 'variableID';

                let entity = null;
                if (entityType === 'opening') {
                    entity = floor.openings?.find(o => o.id === entityId) || null;
                } else {
                    entity = floor.items?.find(i => i.id === entityId) || null;
                }

                if (entity) {
                    const valueKey = field === 'variableID'
                        ? '_valueText'
                        : '_' + field.replace(/ID$/, '') + 'ValueText';
                    entity[valueKey] = data.valueText || '';
                    render();
                    if (selected?.id === entityId) {
                        renderProperties();
                    }
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

    function checkEasyFloorplanRuntime(attempt = 0) {
        const cardReady = !!customElements.get('easy-floorplan-card');
        const editorReady = !!customElements.get('easy-floorplan-card-editor');

        if (cardReady || editorReady) {
            easyFloorplanRuntimeState.textContent = 'Easy Floorplan v__EASY_FLOORPLAN_VERSION__ lokal geladen';
            return;
        }

        if (attempt < 30) {
            setTimeout(() => checkEasyFloorplanRuntime(attempt + 1), 200);
            return;
        }

        easyFloorplanRuntimeState.textContent = 'Easy Floorplan lokal nicht gefunden';
    }

    pushHistory();
    updateModeUI();
    renderAll();
    requestAnimationFrame(fit);
    checkEasyFloorplanRuntime();
})();
</script>
</body>
</html>
HTML;

        $assetVersion = '0';
        $sourceJS = __DIR__ . DIRECTORY_SEPARATOR . self::EASY_FLOORPLAN_VENDOR_DIR
            . DIRECTORY_SEPARATOR . self::EASY_FLOORPLAN_JS_FILE;
        if (is_file($sourceJS)) {
            $hash = @hash_file('sha256', $sourceJS);
            if (is_string($hash) && $hash !== '') {
                $assetVersion = substr($hash, 0, 12);
            }
        }
        $easyFloorplanUrl = self::EASY_FLOORPLAN_WEB_JS . '?v=' . rawurlencode($assetVersion);

        return str_replace(
            ['__INITIAL_PROJECT__', '__EASY_FLOORPLAN_JS__', '__EASY_FLOORPLAN_VERSION__'],
            [$initial, $easyFloorplanUrl, self::EASY_FLOORPLAN_BASELINE],
            $html
        );
    }

    public function GetEasyFloorplanWebPath(): string
    {
        return self::EASY_FLOORPLAN_WEB_JS;
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
                $this->RegisterVariableMessages();
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
        $this->RegisterVariableMessages();
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

            foreach (['items', 'openings'] as $entityCollection) {
                foreach ($floor[$entityCollection] as $entityIndex => $entity) {
                    if (!is_array($entity)) {
                        continue;
                    }
                    foreach (array_keys($entity) as $entityKey) {
                        if (str_starts_with((string) $entityKey, '_')) {
                            unset($entity[$entityKey]);
                        }
                    }
                    $floor[$entityCollection][$entityIndex] = $entity;
                }
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
            // Geräte
            if (isset($floor['items']) && is_array($floor['items'])) {
                foreach ($floor['items'] as $itemIndex => $item) {
                    $this->AddRuntimeVariableMetadata(
                        $Project['floors'][$floorIndex]['items'][$itemIndex],
                        'variableID',
                        '_variablePath',
                        '_valueText'
                    );
                }
            }

            // Türen / Fenster – Easy Floorplan unterstützt zwei Sensoren
            // für zwei Flügel. Deshalb werden beide Symcon-Variablen geführt.
            if (isset($floor['openings']) && is_array($floor['openings'])) {
                foreach ($floor['openings'] as $openingIndex => $opening) {
                    $this->AddRuntimeVariableMetadata(
                        $Project['floors'][$floorIndex]['openings'][$openingIndex],
                        'variableID',
                        '_variablePath',
                        '_valueText'
                    );
                    $this->AddRuntimeVariableMetadata(
                        $Project['floors'][$floorIndex]['openings'][$openingIndex],
                        'secondaryVariableID',
                        '_secondaryVariablePath',
                        '_secondaryVariableValueText'
                    );
                }
            }
        }

        return $Project;
    }

    private function AddRuntimeVariableMetadata(
        array &$Entity,
        string $Field,
        string $PathField,
        string $ValueField
    ): void {
        $variableID = (int) ($Entity[$Field] ?? 0);
        if ($variableID <= 0 || !IPS_VariableExists($variableID)) {
            return;
        }

        try {
            $variable = IPS_GetVariable($variableID);
            $Entity[$PathField] = $this->GetObjectPath($variableID);
            $Entity[$ValueField] = $this->GetSafeValueText($variableID);

            if ($Field === 'variableID') {
                $Entity['_variableType'] = (int) ($variable['VariableType'] ?? -1);
            }
        } catch (Throwable $e) {
            $this->SendDebug('RuntimeVariableMetadata', $e->getMessage(), 0);
        }
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
                    $variable = IPS_GetVariable($objectID);
                    $variableType = (int) ($variable['VariableType'] ?? -1);
                    $variableTypeNames = [
                        0 => 'Boolean',
                        1 => 'Integer',
                        2 => 'Float',
                        3 => 'String'
                    ];

                    $node['variableType'] = $variableType;
                    $node['variableTypeName'] = $variableTypeNames[$variableType] ?? ('Typ ' . $variableType);
                    $node['valueText'] = $this->GetSafeValueText($objectID);
                } catch (Throwable $e) {
                    $node['valueText'] = '';
                    $this->SendDebug('ObjectTree.Variable', $e->getMessage(), 0);
                }
            }

            // Kategorien und Instanzen sind die normalen sichtbaren Äste
            // des Symcon-Objektbaums. Andere Objekte werden ebenfalls
            // mitgeliefert, falls sie eigene Kinder besitzen.
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

    private function GetSafeValueText(int $VariableID): string
    {
        if ($VariableID <= 0 || !IPS_VariableExists($VariableID)) {
            return '';
        }

        try {
            // Bewusst KEINE Profile verwenden.
            // Der Floorplaner liest nur den Rohwert der Variable.
            $value = GetValue($VariableID);

            if (is_bool($value)) {
                return $value ? 'True' : 'False';
            }

            if (is_float($value)) {
                $formatted = rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
                return $formatted === '-0' ? '0' : $formatted;
            }

            if (is_int($value) || is_string($value)) {
                return (string) $value;
            }

            return '';
        } catch (Throwable $e) {
            $this->SendDebug('SafeValueText', $e->getMessage(), 0);
            return '';
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
                if ((int) ($variable['VariableType'] ?? -1) !== 0) {
                    return;
                }

                $newValue = !GetValueBoolean($variableID);

                try {
                    /*
                     * Bewusst wieder exakt wie in der zuvor funktionierenden
                     * Bedienversion: RequestAction direkt auf die Variable.
                     * Kein Ausweichen auf SetValueBoolean(), weil damit bei
                     * Aktions-/Instanzvariablen nur der Variablenwert geändert
                     * werden kann, ohne das eigentliche Gerät zu schalten.
                     */
                    \RequestAction($variableID, $newValue);
                } catch (Throwable $e) {
                    $this->SendDebug(
                        'OperateItem',
                        'RequestAction für Variable ' . $variableID . ' fehlgeschlagen: ' . $e->getMessage(),
                        0
                    );
                    return;
                }

                // Anzeige sofort nach der Aktion aktualisieren.
                $this->PushRuntimeValueUpdates($variableID);
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
