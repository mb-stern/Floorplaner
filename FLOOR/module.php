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
                'type'     => 'Button',
                'caption'  => 'Sichern',
                'download' => 'floorplan-backup.json',
                'onClick'  => 'echo "data:application/json;charset=utf-8," . rawurlencode(FLOOR_GetFloorplanJSON($id));'
            ],
            [
                'type'    => 'PopupButton',
                'caption' => 'Wiederherstellen',
                'popup'   => [
                    'caption'      => 'Floorplan wiederherstellen',
                    'closeCaption' => 'Abbrechen',
                    'items'        => [
                        [
                            'type'       => 'SelectFile',
                            'name'       => 'FloorplanBackup',
                            'caption'    => 'Sicherungsdatei auswählen',
                            'extensions' => '.json'
                        ],
                        [
                            'type'    => 'Label',
                            'caption' => 'Beim Wiederherstellen wird der aktuell gespeicherte Floorplan durch die ausgewählte Sicherung ersetzt.'
                        ]
                    ],
                    'buttons'      => [
                        [
                            'caption' => 'Wiederherstellen',
                            'onClick' => 'if (empty($FloorplanBackup)) { echo "Bitte zuerst eine Sicherungsdatei auswählen."; } else { FLOOR_RestoreFloorplanBackup($id, $FloorplanBackup); echo "MESSAGE:Floorplan wurde wiederhergestellt."; }'
                        ]
                    ]
                ]
            ],
            [
                    'type'  => 'RowLayout',
                    'items' => [
                        [
                                'type'   => 'Image',
                                'onClick'=> "echo 'https://paypal.me/mbstern';",
                                'image'=> "data:image/jpeg;base64,/9j/4QAYRXhpZgAASUkqAAgAAAAAAAAAAAAAAP/sABFEdWNreQABAAQAAAA8AAD/7gAOQWRvYmUAZMAAAAAB/9sAhAAGBAQEBQQGBQUGCQYFBgkLCAYGCAsMCgoLCgoMEAwMDAwMDBAMDg8QDw4MExMUFBMTHBsbGxwfHx8fHx8fHx8fAQcHBw0MDRgQEBgaFREVGh8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx//wAARCABLAGQDAREAAhEBAxEB/8QAqwABAAICAwEBAAAAAAAAAAAAAAUGAgcDBAgJAQEBAAIDAQAAAAAAAAAAAAAAAAMEAgUGARAAAQMCAwMEDwMICwAAAAAAAgEDBAAFERIGIRMHMdEUFkFRcSKyk6PDJFSEFTZGZmEyCIGxQlKSIzODkaFigmOz00QlVRgRAAICAQIDBQYFBQAAAAAAAAABAgMREgQhMQVBUWEiE/BxgaGxBpHRQhQVwfEyUiP/2gAMAwEAAhEDEQA/AN+WWywr/CS63VDfkPmeUc5CICJKKCKCqbNlAd/qNpr1YvGHz0A6jaa9WLxh89AOo2mvVi8YfPQDqNpr1YvGHz0A6jaa9WLxh89AOo2mvVi8YfPQDqNpr1YvGHz0A6jaa9WLxh89AOo2mvVi8YfPQDqNpr1YvGHz0A6jaa9WLxh89ARnuVr3/wC4t+97o3PSui51+9jly5vvZezhQEnob4ajd1zw1oCeoBQCgFAeZtWfik1ZbtT3W3W22284MKU7GYceR4nCFk1DMSi4KbVHHYldDT0eEoJtvLRrrN7JSaSIr/1nr3/q7Z+y/wD6tS/wtXfL5GH76Xci4aC/FPFul1j2zVFtC3dKMWmrhGMiZEyXAd6B98Iqv6WZcOzVTc9HcYuUHnHYTVb1N4Zv6tIXhQCgFAV/569g85QGWhvhqN3XPDWgJ6gFAKA4LhLbhwJMxxcG4zRvGq9psVJfzVlGOWkeN4WT53SZJyZD0lxcTfMnTVe2aqS/nru0sLBz74s6XSj7SVD6rJfTR+g+6ZIAjiRKgiiY44rsSitZ44JcT6E6Nv8ADvunok2Kpd6KNPgf3wdbREISw/prkd3t5U2OMjZbHeQ3FanHkTdVi2KAUBX/AJ69g85QGWhvhqN3XPDWgJ6gFAKAp/F+6LbOGOpZaLlLoLrIL/afTcp/W5VrYw1XRXiRXvEGeElElHKAqRLsERTFVVewiJXZS5GjTXNmAWi7GSCEJ9SXYibo+aq2h9xk9zUuco/ii26T0VKalt3C6AjaMrmYjLgpKachHhyYdqrNVLzlmj6l1aMouuvjnm/yPWPBCG8zpJ19xFQZUozax7IiIhin94VrnOuTTuS7om5+2q3Hbtv9UvyRsKtMdEKAUBX/AJ69g85QGWhvhqN3XPDWgJ6gFAKA1F+KK59E4XnGQsCuE2Oxh2xFVeX/ACq2nSIZuz3JlTeSxA8waGY3l9RzDYy0Z4/auAp4VdZHmct1aeKH4tI2xpzTl11Fcfd9uESfQCdJXCyigjgiqq7eyqVjudzCmOqXI5/Z7Ke4nohz5l8snAu6HIA7zMaZjIuJtRlI3CTtZiQRHu7a1F/XYJeRNvxOg232xNyzbJKPhzNwwYMWBDZhxG0ajRwRtpseRBHYlc3ZNzk5Pi2djVXGuKjFYijnrAzFAKAr/wA9ewecoDLQ3w1G7rnhrQE9QCgFAUzidwvtnEC3QoNwmyITcJ5XwWPkXMRAod8hiXIi7Kt7TduhtpJ5IbqVNYZp7UfBCFodyO7ZnZ10dnIYPKbYkLYtqKphuhTaSr2e1XRdO6h6revTHByv3BtmowjBOXF9hduB1knx7hc50qM6wKNAw0roEGZSJSLDMicmVKq9cvjKMYpp8cnv2ztpxnOUk1wxx9vA29XOHXigFAKAUBX/AJ69g85QGWhvhqN3XPDWgNAyeKvFSdB1ZqS36lhQbTY5xsQ7e+wwrj4K4qADSqKqSoOXl5a6JbOhOEHFuUlz4mud02m0+CNl2HjvpKPpawytX3Fm3Xy5xQffiNg4eVCVUF0hBD3YuCmdM3YWtfZ06bnJVrMUyxHcR0rVzJ5njHw3eisTG7yBRJMz3czI3TyNlJyiWTMoYJ3pouK7KgexuTxp44z8CRXw7yQvOvdM2y7rYXZo+/SiuS24IiZkjbYEeYyEVEEwBfvKlY1bWc0pY8ucGN16hFvtSbNadfNfsabjaiO7xXAefVkbcTTe8JBVcSwFEXL3tdB+w27tdWh8Fzyzj/5TdxpVznHjLGnCybGd4kaSiOtxbhPCPOyCUhlEM0aNRRVAiEVRFTkwrSrpt0lmMcx+p0b6xt4NRnLEscefDwIy6a2emah0tGsEpCgXQ3XJJ7vabTRYKnfpmH7h7anq2SjXY7F5o4x737IrX9Sc7qY0vyTznh2L3+5lh1pqVrTGlLpf3W98NuYJ4WVLLnNNgBmwXDMSonJWv29XqTUe83Vk9MWzWjf4jrYPDTrZJgC3dHJbkGNZhexzutoJqSuKCKgI2aES5fs7NbB9Kl62hPy4zkr/ALtaNXaWuBxb04xpOy3vVD7Vll3ljpLFuQjkO5FxUVEQDeEmXBVXLhVaWym5yjDzKPaSq9KKcuGS02DUNk1Da2rrZZjc63vYo2+3jhiK4EioqIqKi8qKlVrKpQlpksMkjJSWUdD569g85UZkcGmSlDolSiBvZQtSFjtoqIpOIpZBxXBExKsoYys8jx8jWHCf8PVhTTrczXdl3uoCkOuE068RCLeKICELR7tccFL8tbje9TlrxVLy4KdO1WPMuJxM6R4h6Y1/q2XbNJRb/Evyf8ZOdeZaajMoK5WVA9uVBwBQRExypguFeu+qyqCc3Fx5rvGicZPCzkgLzojqx+G9+FqdBtt8W5dOhMKQkayVcRsGx3akmJMivIuxO5U1e49Td5hxjpx8P7kcq9NWHweS5aI4d6kj6KvmpLuBzteapj/vd4oi40w5gIspjlQVyd8SdwexUM93X68IrhVBkW5oslt54WbJL6lt0hwv0/CtsCVcbeJXoAE3ycMjQXeX7mZW1y9yot51SyUpKMvJ/T6kHT+iUwhGU4/9O33/AEKzE01re3WO+WIbA1MdnOOGt2J1vExPBO9QlzKX6Q4qmC1fnuaJ2Qs1uOn9OGauGz3VdVlXpqTlnzZXt7iW01o++QdR2WTIiKMS0Wnd5s4LjKczEYIiLjji6u3kqtut5XKqaT805/L2Rc2XT7YX1uS8sK/D/J5z9SF11B4q604XJa5tjbg3i43NtqVEYdBRagNkh70yJxUVVIU2Cv5Kh28qKrtSlmKj8zdWKc4YxxyQnEfgA63EusvS7DlxuF7ksNNxl3bbUCNsKQYKRJmU1aBFXlw2VNtepZaU+CivxfYYW7b/AF7Tk1fw51fbeIQXq2QblcbMlsj26CdlnNQpUbo4CCtkryLi2WVS2duvKN1XKrS3FS1NvUspns6ZKWVnGOw2bwp0m3pjR0eAkJ23OvOuypEJ+QMtxs3S5CeAQElyiOOCcta7eXepZnOfhgsUw0xwd/569g85VUlMtDfDUb7Ccx/bWgJ6gFAdO42a0XJWVuMJiYsY95H6Q0Du7P8AWDOi5V+1KzjZKPJ4PHFPmdysD0UAoBQCgFAKAUBX8U69YY7egcn8ygIeLj0iZuen/wAc83unDo2P879L9bLsoDs+k/UHkKAek/UHkKAek/UHkKAek/UHkKAek/UHkKAek/UHkKAek/UHkKAek/UHkKAek/UHkKAek/UHkKAek/UHkKAiv3fvf/db/P8A4nvT+H4nd0B//9k="
                        ],
                        [
                            'type'    => 'Label',
                            'caption' => "Sag danke und unterstütze den Modulentwickler: paypal.me/mbstern"
                        ],
                    ],
                ],
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
    <script src="/icons.js"></script>
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
            cursor: default;
        }

        #app:not(.view-mode) .wall {
            cursor: pointer;
        }

        .wall.selected {
            stroke: #74b9ff;
        }

        .opening {
            cursor: default;
        }

        #app:not(.view-mode) .opening {
            cursor: pointer;
        }

        /* Größere Trefferfläche nur für die Öffnung selbst.
           Die sichtbaren Resize-Punkte bleiben exakt bei r=2.8. */
        .shutter-control {
            pointer-events: all;
            isolation: isolate;
        }

        .shutter-control circle:not(.shutter-hit) {
            fill: #ffffff;
            stroke: #303030;
            stroke-width: 2.4;
            vector-effect: non-scaling-stroke;
        }

        .shutter-control .shutter-hit {
            fill: transparent;
            stroke: transparent;
            pointer-events: all;
        }

        .shutter-control text {
            fill: #202020;
            stroke: none;
            font-size: 12px;
            font-weight: 700;
            text-anchor: middle;
            dominant-baseline: central;
            pointer-events: none;
        }

        html[data-theme="light"] .shutter-control circle:not(.shutter-hit) {
            fill: #ffffff;
            stroke: #303030;
            stroke-width: 2.4;
        }

        html[data-theme="light"] .shutter-control text {
            fill: #202020;
            stroke: none;
        }

        .opening-hit {
            stroke: transparent;
            stroke-width: 22;
            fill: none;
            vector-effect: non-scaling-stroke;
            pointer-events: stroke;
            cursor: default;
        }

        #app:not(.view-mode) .opening-hit {
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
            stroke: #4da3ff;
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
            cursor: default;
        }

        #app:not(.view-mode) .furniture {
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

        /* Klima / Heizung: bewusst als kleines Wand-Bedienteil statt
           als rundes Thermostat-/Messwertsymbol darstellen. */
        .device .climate-panel {
            fill: #404040;
            stroke: #dedede;
            stroke-width: 2;
            vector-effect: non-scaling-stroke;
        }

        .device .climate-panel-display {
            fill: rgba(255,255,255,.08);
            stroke: #9aa6b2;
            stroke-width: 1;
            vector-effect: non-scaling-stroke;
            pointer-events: none;
        }

        .device .climate-panel-dot {
            fill: #bfc8d2;
            stroke: none;
            pointer-events: none;
        }

        .device.selected .climate-panel {
            stroke: #74b9ff;
            stroke-width: 3;
        }

        /* Boolean-Statusring für alle Geräte mit Bool-Variable.
           Die Farbe kommt je Gerät aus --device-status-color. */
        /* Numerischer Status: Die normale Geräte-Kontur bleibt immer erhalten.
           Nur dieser zusätzliche Farbring wird mit dem Zahlenwert ein-/ausgeblendet. */
        .device.numeric-status .device-status-ring {
            fill: none;
            stroke: var(--device-status-color, #ffe66d);
            stroke-width: 2;
            stroke-opacity: var(--device-status-opacity, 1);
            filter: drop-shadow(0 0 var(--device-status-glow, 0px) var(--device-status-color, #ffe66d));
            vector-effect: non-scaling-stroke;
            pointer-events: none;
        }

        .device.boolean-active circle {
            stroke: var(--device-status-color, #ffe66d);
            filter: drop-shadow(0 0 7px var(--device-status-color, #ffe66d));
        }

        /* Die Lampe behält zusätzlich ihre bisherige leicht leuchtende Füllung. */
        .device.active-light.boolean-active circle {
            fill: #5b5422;
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

        /* Optionaler Direkt-Slider für echte Integer-/Float-Zahlenbereiche.
           Kompakt direkt unter dem Gerät, nur in der Bedienansicht aktiv. */
        .device-direct-slider {
            cursor: pointer;
        }

        .device-direct-slider-hit {
            stroke: transparent;
            stroke-width: 30;
            vector-effect: non-scaling-stroke;
            pointer-events: stroke;
        }

        .device-direct-slider-track {
            stroke: rgba(160,170,185,.65);
            stroke-width: 4;
            stroke-linecap: round;
            vector-effect: non-scaling-stroke;
            pointer-events: none;
        }

        .device-direct-slider-fill {
            stroke: #d7e9ff;
            stroke-width: 5;
            stroke-linecap: round;
            vector-effect: non-scaling-stroke;
            pointer-events: none;
        }

        .device-direct-slider-thumb {
            fill: #ffffff;
            stroke: #66788a;
            stroke-width: 1.4;
            vector-effect: non-scaling-stroke;
            pointer-events: none;
        }

        #app:not(.view-mode) .device-direct-slider {
            pointer-events: none;
            opacity: .65;
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
            max-width: none;
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

        /* Bedienansicht:
           Nur echte Geräte sollen mit dem Hand-Cursor als bedienbar erscheinen.
           Wände, Türen/Fenster, Möbel und Texte sind hier reine Darstellung. */
        #app.view-mode .wall,
        #app.view-mode .opening,
        #app.view-mode .opening-hit,
        #app.view-mode .furniture,
        #app.view-mode .plan-text {
            cursor: default !important;
        }

        #app.view-mode .device {
            cursor: pointer !important;
        }

        /* Zusätzliche, direkt am SVG-Szenen-Container gesetzte Laufzeitregel.
           Damit werden auch Cursor von Unterelementen (SVG-Pfade, Linien usw.)
           sicher überschrieben. */
        #scene.runtime-view,
        #scene.runtime-view * {
            cursor: default !important;
        }

        #scene.runtime-view .device,
        #scene.runtime-view .device * {
            cursor: pointer !important;
        }

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

        .runtime-value-frame {
            fill: rgba(255,255,255,.06);
            stroke: currentColor;
            stroke-width: 1.2;
            vector-effect: non-scaling-stroke;
            pointer-events: none;
        }

        html[data-theme="light"] .runtime-value-frame {
            fill: rgba(255,255,255,.78);
            stroke: #5f5f5f;
        }

        /* Reine Status-/Messwertvariablen ohne Aktion sind im Bedienmodus
           bewusst nicht als klickbares Bedienelement dargestellt. */
        #app.view-mode .device.status-only,
        #scene.runtime-view .device.status-only,
        #scene.runtime-view .device.status-only * {
            cursor: default !important;
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

        .control-slider { min-width: 260px; padding: 6px 2px; }
        .control-slider-value { text-align: center; font-size: 18px; font-weight: 600; margin-bottom: 8px; }
        .control-slider-row { display: grid; grid-template-columns: 38px minmax(180px, 1fr) 38px; gap: 8px; align-items: center; }
        .control-slider-row button {
            width: 38px;
            height: 38px;
            min-width: 38px;
            min-height: 38px;
            padding: 0;
            font-size: 22px;
            line-height: 36px;
            touch-action: manipulation;
        }
        .control-slider input[type="range"] {
            width: 100%;
            min-height: 38px;
            margin: 0;
            cursor: pointer;
            touch-action: none;
        }
        .control-slider input[type="range"]::-webkit-slider-thumb {
            width: 22px;
            height: 22px;
        }
        .control-slider input[type="range"]::-moz-range-thumb {
            width: 22px;
            height: 22px;
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

        html[data-theme="light"] button.danger {
            color: #111111;
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

        /* Offenes Fenster muss auch im hellen Theme blau bleiben.
           Diese spezifischere Regel verhindert, dass die allgemeine
           helle Fensterfarbe den Offen-Status überschreibt. */
        html[data-theme="light"] .opening-line.opening-state-open {
            stroke: #1769aa;
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

        html[data-theme="light"] .device .climate-panel {
            fill: rgba(255,255,255,.82);
            stroke: #777777;
        }

        html[data-theme="light"] .device .climate-panel-display {
            fill: rgba(80,80,80,.07);
            stroke: #888888;
        }

        html[data-theme="light"] .device .climate-panel-dot {
            fill: #666666;
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
            fill: #303030;
            color: #303030;
            stroke: none !important;
            paint-order: normal !important;
            text-rendering: optimizeLegibility;
        }

        /* Helles Theme: SVG-Konturen bewusst ohne weiche Schatten/Filter.
           Das verhindert den verwaschenen Eindruck bei Text und Symbolen. */
        html[data-theme="light"] #scene text,
        html[data-theme="light"] #scene tspan {
            stroke: none !important;
            filter: none !important;
        }

        html[data-theme="light"] .device-glyph,
        html[data-theme="light"] .furniture,
        html[data-theme="light"] .opening,
        html[data-theme="light"] .wall {
            filter: none !important;
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

        /* Einheitlicher Cursor für den Grundriss:
           Über allen gezeichneten Elementen und Bearbeitungsgriffen wird
           bewusst immer die Hand angezeigt. Damit gibt es keine wechselnden
           Pfeil-, Verschiebe- oder Resize-Cursor mehr. */
        #scene,
        #scene * {
            cursor: pointer !important;
        }

        /* IP-Symcon / Font-Awesome Icons aus /icons.js */
        .device-icon-html {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--fp-text);
            line-height: 1;
            pointer-events: none;
        }

        html[data-theme="light"] .device-icon-html {
            color: #4f4f4f;
        }

        .icon-select-button {
            width: 38px;
            min-width: 38px;
            height: 34px;
            min-height: 34px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 3px;
            border: 1px solid var(--fp-border);
            border-radius: 6px;
            background: var(--fp-panel-2);
            color: var(--fp-text);
            cursor: pointer;
            text-align: center;
        }

        .icon-select-button i {
            width: 16px;
            text-align: center;
            font-size: 14px;
        }

        .icon-select-preview {
            width: 18px;
            height: 18px;
            flex: 0 0 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: currentColor;
        }

        .icon-select-preview svg {
            width: 15px;
            height: 15px;
            display: block;
            fill: currentColor;
            color: inherit;
        }

        .symcon-icon-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(48px, 1fr));
            gap: 6px;
            padding: 8px;
        }

        .symcon-icon-grid button {
            min-width: 0;
            height: 46px;
            padding: 4px;
            border: 1px solid var(--fp-border);
            border-radius: 6px;
            background: var(--fp-panel-2);
            color: var(--fp-text);
            cursor: pointer;
            font-size: 20px;
        }

        .symcon-icon-grid button:hover,
        .symcon-icon-grid button.current {
            outline: 2px solid var(--fp-accent);
        }

        .device-icon-html svg {
            width: 1em;
            height: 1em;
            display: block;
            margin: auto;
            fill: currentColor;
            color: inherit;
        }

        .symcon-icon-grid button svg {
            width: 1.15em;
            height: 1.15em;
            display: block;
            margin: auto;
            fill: currentColor;
            color: inherit;
        }

        .icon-picker-hint {
            padding: 6px 14px 0;
            color: var(--fp-muted);
            font-size: 11px;
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
                Gerät/Möbel/Text: Werkzeug wählen und Position anklicken.<br>Geräte: IP-Symcon-Icon wird automatisch von der zugeordneten Variable übernommen und kann manuell geändert werden.<br>Möbel: 26 Easy-Floorplan-Symbole verfügbar.<br>
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
            <button id="copyFloorBtn" type="button" title="Aktuelle Etage komplett kopieren">Etage kopieren</button>
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

<div id="iconModal" class="modal-backdrop" aria-hidden="true">
    <div class="modal">
        <h3>IP-Symcon Icon auswählen</h3>
        <div class="modal-search">
            <input id="iconSearch" placeholder="Icon suchen … z. B. light, temperature, door">
        </div>
        <div class="icon-picker-hint">Es werden die von IP-Symcon über /icons.js bereitgestellten Icons verwendet.</div>
        <div id="iconList" class="variable-list"></div>
        <div class="modal-actions">
            <button id="iconAutoBtn" type="button">Icon der Variable übernehmen</button>
            <button id="iconCloseBtn" type="button">Abbrechen</button>
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
    const lastViewFloorStorageKey = 'floorplaner:lastViewFloor:__INSTANCE_ID__';
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
    const iconModal = document.getElementById('iconModal');
    const iconList = document.getElementById('iconList');
    const iconSearch = document.getElementById('iconSearch');
    const controlModal = document.getElementById('controlModal');
    const controlTitle = document.getElementById('controlTitle');
    const controlBody = document.getElementById('controlBody');
    const controlCloseBtn = document.getElementById('controlCloseBtn');

    let state = normalizeProject(initial);
    let variablePickerTarget = null;
    let iconPickerTarget = null;
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

    function refreshPropertiesAfterStructuralChange() {
        // Änderungen wie Icon oder Variablenzuordnung können ganze
        // Eigenschaftsblöcke ein-/ausblenden (z.B. Statusfarbe).
        // Nach Abschluss des aktuellen Events die Leiste deshalb gezielt
        // neu aufbauen, statt auf einen Seiten-Reload zu warten.
        setTimeout(() => {
            const active = document.activeElement;
            if (active && properties.contains(active) && typeof active.blur === 'function') {
                active.blur();
            }
            propertiesControlActive = false;
            propertiesSelectOpen = false;
            renderProperties();
        }, 0);
    }

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
        // Einrasten verwendet immer direkt die Rastergröße.
        // Eine separate Snap-Einstellung gibt es nicht mehr.
        delete q.snap;
        q.background = q.background || '#303030';
        q.showGrid = q.showGrid !== false;
        q.mode = q.mode === 'view' ? 'view' : 'edit';
        q.floors = Array.isArray(q.floors) && q.floors.length ? q.floors : [{
            id: 'floor_1',
            name: 'Erdgeschoss',
            order: 1,
            walls: [],
            openings: [],
            items: [],
            texts: [],
            furniture: [],
            areas: [],
            trackers: []
        }];

        q.floors.forEach((floor, index) => {
            const order = Number(floor.order);
            floor.order = Number.isFinite(order) && order > 0 ? Math.round(order) : (index + 1);
        });
        q.floors.sort((a, b) => (Number(a.order) || 0) - (Number(b.order) || 0));
        q.floors.forEach((floor, index) => {
            floor.order = index + 1;
        });
        for (const floor of q.floors) {
            floor.id ||= uid('floor');
            floor.name ||= 'Etage';
            floor.walls = Array.isArray(floor.walls) ? floor.walls : [];
            floor.openings = Array.isArray(floor.openings) ? floor.openings : [];
            for (const opening of floor.openings) {
                if (typeof opening.shutterValueMappingEnabled !== 'boolean') {
                    opening.shutterValueMappingEnabled = false;
                }
                if (!opening.shutterValueMap || typeof opening.shutterValueMap !== 'object' || Array.isArray(opening.shutterValueMap)) {
                    opening.shutterValueMap = {};
                }
            }
            floor.items = Array.isArray(floor.items) ? floor.items : [];
            for (const item of floor.items) {
                item.statusColor = normalizeStatusColor(item.statusColor);
                // Migration älterer Projekte: Der frühere Gerätetyp wird nur noch
                // verwendet, um einmalig ein passendes Standardsymbol zu übernehmen.
                // Die Bedienlogik hängt NICHT mehr vom Gerätetyp ab.
                if (!item.icon || String(item.icon).startsWith('mdi:')) {
                    item.icon = defaultSymconIconForLegacyKind(item.kind);
                    item.iconManual = false;
                }
                if (typeof item.iconManual !== 'boolean') item.iconManual = false;
                if (typeof item.iconSvg !== 'string') item.iconSvg = '';
            }
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

    function rememberLastViewFloor() {
        if (state.mode !== 'view' || !state.activeFloor) return;
        try {
            localStorage.setItem(lastViewFloorStorageKey, state.activeFloor);
        } catch (e) {
            // localStorage kann je nach WebView/Browser deaktiviert sein.
        }
    }

    function restoreLastViewFloor() {
        if (state.mode !== 'view') return;
        try {
            const savedFloorID = localStorage.getItem(lastViewFloorStorageKey);
            if (savedFloorID && state.floors.some(f => f.id === savedFloorID)) {
                state.activeFloor = savedFloorID;
            }
        } catch (e) {
            // Ohne localStorage bleibt die im Projekt gespeicherte Etage aktiv.
        }
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
        scene.classList.toggle('runtime-view', isView);
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
        const s = Math.max(2, Number(state.grid) || 20);
        return Math.round(v / s) * s;
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

        /*
         * Oben liegt die Visualisierungs-Kopfzone von IP-Symcon.
         * Dieser Bereich kann Maus-/Touch-Ereignisse abfangen. Deshalb darf der
         * interaktive Grundriss dort nicht hineinragen.
         *
         * 40 px + 24 px Innenabstand werden für die Kopfzone verwendet.
         * Der Abstand gilt sowohl im Editor- als auch im Bedienmodus.
         */
        const headerTop = 40;

        // Im Bedienmodus bleibt unten eine echte Fußzeile für Etagenwahl + Editor-Icon frei.
        const footerBottom = state.mode === 'view' ? 40 : 0;
        const padding = 24;

        return {
            left: padding,
            right: Math.max(padding, box.width - padding),
            top: padding + headerTop,
            bottom: Math.max(padding + headerTop, box.height - padding - footerBottom)
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
            fit();
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
        rememberLastViewFloor();
        selected = null;
        wallStart = null;
        preview = null;
        render();
        requestAnimationFrame(fit);
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

    function contentBounds(floor = currentFloor()) {
        const points = [];
        const addBox = (minX, minY, maxX, maxY) => {
            points.push([minX, minY], [maxX, maxY]);
        };

        // Hauptreferenz bleibt der eigentliche Grundriss.
        for (const w of floor.walls || []) {
            points.push([Number(w.x1) || 0, Number(w.y1) || 0]);
            points.push([Number(w.x2) || 0, Number(w.y2) || 0]);
        }

        for (const o of floor.openings || []) {
            const wall = (floor.walls || []).find(w => w.id === o.wallId);
            if (!wall) continue;
            const g = openingGeometry(wall, o);
            points.push(
                [g.x1, g.y1], [g.x2, g.y2],
                [g.wx1, g.wy1], [g.wx2, g.wy2]
            );
        }

        /*
         * Geräte werden zusätzlich mit ihrer EFFEKTIV sichtbaren Größe
         * berücksichtigt. Befindet sich ein Gerät innerhalb des Grundrisses,
         * verändert es die Bounds nicht. Steht es z.B. auf einer Terrasse
         * außerhalb, erweitert es die Bounds nur genau so weit wie nötig.
         */
        for (const item of floor.items || []) {
            const x = Number(item.x) || 0;
            const y = Number(item.y) || 0;
            const radius = Math.max(0, Number(item.size) || 18);
            const showIcon = item.showIcon !== false;
            const showName = item.showName === true;
            const showValue = item.showValue === true;
            const labelSize = Math.max(8, Math.min(40, Number(item.labelSize) || 12));
            const valueSize = Math.max(8, Math.min(40, Number(item.valueSize) || 12));
            const valueText = item._valueText !== undefined && item._valueText !== ''
                ? String(item._valueText)
                : '—';

            if (showIcon) {
                addBox(x - radius, y - radius, x + radius, y + radius);
            }

            const addDeviceText = (textValue, position, size, extra = 0) => {
                if (!textValue) return;

                const width = Math.max(size, String(textValue).length * size * 0.62);
                const height = size * 1.2;
                const pos = ['above','left','right','below'].includes(position) ? position : 'below';

                let minX, minY, maxX, maxY;

                if (pos === 'above') {
                    const baselineY = y - (radius + 7 + extra);
                    minX = x - width / 2;
                    maxX = x + width / 2;
                    minY = baselineY - height;
                    maxY = baselineY + size * 0.3;
                } else if (pos === 'left') {
                    const rightX = x - (radius + 7 + extra);
                    minX = rightX - width;
                    maxX = rightX;
                    minY = y - height * 0.55;
                    maxY = y + height * 0.45;
                } else if (pos === 'right') {
                    const leftX = x + radius + 7 + extra;
                    minX = leftX;
                    maxX = leftX + width;
                    minY = y - height * 0.55;
                    maxY = y + height * 0.45;
                } else {
                    const baselineY = y + radius + size + 5 + extra;
                    minX = x - width / 2;
                    maxX = x + width / 2;
                    minY = baselineY - height;
                    maxY = baselineY + size * 0.3;
                }

                addBox(minX, minY, maxX, maxY);
            };

            if (showName && item.name) {
                addDeviceText(String(item.name), item.labelPosition || 'below', labelSize, 0);
            }

            if (showValue) {
                let valueExtra = 0;
                if (showName && (item.valuePosition || 'below') === (item.labelPosition || 'below')) {
                    valueExtra = Math.max(labelSize, valueSize) + 3;
                }
                addDeviceText(valueText, item.valuePosition || 'below', valueSize, valueExtra);
            }

            // Unsichtbares Gerät nur minimal berücksichtigen.
            if (!showIcon && !showName && !showValue) {
                addBox(x - 2, y - 2, x + 2, y + 2);
            }
        }

        // Nur bei einer Etage komplett ohne Wände noch Möbel/Texte als Fallback verwenden.
        if (!(floor.walls || []).length) {
            for (const f of floor.furniture || []) {
                const x = Number(f.x) || 0;
                const y = Number(f.y) || 0;
                const halfW = Math.max(8, Number(f.width) || 100) / 2;
                const halfH = Math.max(8, Number(f.height) || 60) / 2;
                points.push([x - halfW, y - halfH], [x + halfW, y + halfH]);
            }

            for (const t of floor.texts || []) {
                const x = Number(t.x) || 0;
                const y = Number(t.y) || 0;
                const size = Math.max(6, Number(t.size) || 18);
                const width = Math.max(20, String(t.text || 'Text').length * size * 0.65);
                points.push([x, y - size], [x + width, y + size * 0.35]);
            }
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
         * Jede Etage wird für die AKTUELLE Fenster-/Tile-Größe separat optimal
         * eingepasst. Dadurch nutzt jeder Grundriss den verfügbaren Platz aus.
         * Wichtig: Beim Etagenwechsel wird immer neu berechnet; alte Zoom-/Pan-
         * Werte werden dabei nicht wiederverwendet.
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

        // Proportional einpassen: maximal groß, aber vollständig sichtbar.
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

    function updateLiveFloorSelectWidth() {
        if (!liveFloorSelect) return;

        const style = getComputedStyle(liveFloorSelect);
        const canvas = updateLiveFloorSelectWidth._canvas || (updateLiveFloorSelectWidth._canvas = document.createElement('canvas'));
        const ctx = canvas.getContext('2d');

        if (ctx) {
            ctx.font = `${style.fontWeight} ${style.fontSize} ${style.fontFamily}`;

            let longestWidth = 0;
            for (const option of liveFloorSelect.options) {
                longestWidth = Math.max(longestWidth, ctx.measureText(option.text || '').width);
            }

            // Immer gleich breit: längster Etagenname + Innenabstand + nativer Auswahlpfeil.
            // Etwas Reserve verhindert Abschneiden je nach Browser/WebView.
            const width = Math.ceil(longestWidth + 64);
            const finalWidth = Math.max(110, width);
            liveFloorSelect.style.width = `${finalWidth}px`;
            liveFloorSelect.style.minWidth = `${finalWidth}px`;
            liveFloorSelect.style.maxWidth = 'none';
        }
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
            updateLiveFloorSelectWidth();
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

    function defaultSymconIconForLegacyKind(kind) {
        const icons = {
            light: 'fa-light fa-lightbulb',
            switch: 'fa-light fa-toggle-on',
            socket: 'fa-light fa-plug',
            shutter: 'fa-light fa-blinds',
            temperature: 'fa-light fa-temperature-half',
            humidity: 'fa-light fa-droplet-percent',
            motion: 'fa-light fa-person-walking',
            window: 'fa-light fa-window-frame',
            door: 'fa-light fa-door-open',
            climate: 'fa-light fa-temperature-half',
            fan: 'fa-light fa-fan',
            radiator: 'fa-light fa-radiator',
            television: 'fa-light fa-tv',
            camera: 'fa-light fa-camera',
            washer: 'fa-light fa-washing-machine',
            dishwasher: 'fa-light fa-dishwasher',
            boiler: 'fa-light fa-water',
            car: 'fa-light fa-car',
            vacuum: 'fa-light fa-vacuum-robot',
            lock: 'fa-light fa-lock',
            generic: 'fa-light fa-circle'
        };
        return icons[String(kind || 'generic')] || icons.generic;
    }

    const legacySymconIconMap = {
        'light': 'fa-light fa-lightbulb',
        'bulb': 'fa-light fa-lightbulb',
        'lamp': 'fa-light fa-lightbulb',
        'switch': 'fa-light fa-toggle-on',
        'power': 'fa-light fa-power-off',
        'electricity': 'fa-light fa-bolt',
        'energy': 'fa-light fa-bolt',
        'temperature': 'fa-light fa-temperature-half',
        'thermometer': 'fa-light fa-temperature-half',
        'humidity': 'fa-light fa-droplet-percent',
        'rainfall': 'fa-light fa-droplet',
        'window': 'fa-light fa-window-frame',
        'door': 'fa-light fa-door-open',
        'lock': 'fa-light fa-lock',
        'motion': 'fa-light fa-person-walking',
        'presence': 'fa-light fa-person',
        'camera': 'fa-light fa-camera',
        'speaker': 'fa-light fa-speaker',
        'music': 'fa-light fa-music',
        'tv': 'fa-light fa-tv',
        'car': 'fa-light fa-car',
        'battery': 'fa-light fa-battery-half',
        'clock': 'fa-light fa-clock',
        'calendar': 'fa-light fa-calendar',
        'information': 'fa-light fa-circle-info',
        'warning': 'fa-light fa-triangle-exclamation',
        'alert': 'fa-light fa-triangle-exclamation',
        'gear': 'fa-light fa-gear',
        'cog': 'fa-light fa-gear',
        'home': 'fa-light fa-house',
        'house': 'fa-light fa-house'
    };

    function normalizeSymconIcon(icon) {
        const raw = String(icon || '').trim();
        if (!raw) return 'fa-light fa-circle';
        if (/\bfa-(light|brands|kit|solid|regular|thin|duotone|sharp)\b/.test(raw) && /\bfa-[a-z0-9-]+\b/.test(raw.replace(/fa-(light|brands|kit|solid|regular|thin|duotone|sharp)/g, ''))) {
            return raw;
        }
        if (/^fa-[a-z0-9-]+$/i.test(raw)) return `fa-light ${raw}`;
        const key = raw.toLowerCase().replace(/[^a-z0-9]/g, '');
        if (legacySymconIconMap[key]) return legacySymconIconMap[key];
        // Alte Symcon-Iconnamen bestmöglich auf Font-Awesome-Namen abbilden.
        const slug = raw.replace(/([a-z])([A-Z])/g, '$1-$2').replace(/[_\s]+/g, '-').replace(/[^a-zA-Z0-9-]/g, '').toLowerCase();
        return slug ? `fa-light fa-${slug}` : 'fa-light fa-circle';
    }

    function parseSymconIcon(icon) {
        const cls = normalizeSymconIcon(icon);
        const parts = cls.split(/\s+/).filter(Boolean);
        const styleClass = parts.find(part => /^fa-(light|brands|kit|solid|regular|thin|duotone|sharp)$/.test(part)) || 'fa-light';
        const iconClass = parts.find(part => /^fa-[a-z0-9-]+$/i.test(part) && part !== styleClass) || 'fa-circle';
        const prefixMap = {
            'fa-light': 'fal',
            'fa-brands': 'fab',
            'fa-kit': 'fak',
            'fa-solid': 'fas',
            'fa-regular': 'far',
            'fa-thin': 'fat',
            'fa-duotone': 'fad',
            'fa-sharp': 'fass'
        };
        return {
            cls,
            prefix: prefixMap[styleClass] || 'fal',
            iconName: iconClass.replace(/^fa-/, '')
        };
    }

    function fontAwesomeSvgHtml(icon) {
        const parsed = parseSymconIcon(icon);

        // 1) Offizielle Font-Awesome-API verwenden, wenn verfügbar.
        try {
            if (window.FontAwesome && typeof window.FontAwesome.icon === 'function') {
                const rendered = window.FontAwesome.icon({ prefix: parsed.prefix, iconName: parsed.iconName });
                if (rendered && Array.isArray(rendered.html) && rendered.html.length > 0) {
                    return rendered.html.join('');
                }
            }
        } catch (e) {}

        // 2) Robuster HTML-SDK-Fallback: SVG direkt aus den durch /icons.js
        // geladenen Definitionen bauen. Das funktioniert auch innerhalb des
        // SVG-Editors, ohne dass i2svg ein <i>-Element nachträglich finden muss.
        const definitionSources = [];
        try { definitionSources.push(window.FontAwesome?.library?.definitions); } catch (e) {}
        try { definitionSources.push(window.___FONT_AWESOME___?.styles); } catch (e) {}

        for (const defs of definitionSources) {
            if (!defs || typeof defs !== 'object') continue;
            const style = defs?.[parsed.prefix];
            if (!style || typeof style !== 'object') continue;
            const def = style?.[parsed.iconName];
            if (!def) continue;

            let width = 512, height = 512, pathData = '';
            if (Array.isArray(def)) {
                width = Number(def[0]) || 512;
                height = Number(def[1]) || 512;
                pathData = def[4] ?? '';
            } else if (Array.isArray(def.icon)) {
                width = Number(def.icon[0]) || 512;
                height = Number(def.icon[1]) || 512;
                pathData = def.icon[4] ?? '';
            } else if (def.icon && typeof def.icon === 'object') {
                width = Number(def.icon.width) || 512;
                height = Number(def.icon.height) || 512;
                pathData = def.icon.svgPathData ?? '';
            } else {
                width = Number(def.width) || 512;
                height = Number(def.height) || 512;
                pathData = def.svgPathData ?? '';
            }

            const paths = Array.isArray(pathData) ? pathData : [pathData];
            if (!paths.length || paths.every(p => !String(p || '').trim())) continue;
            const body = paths.map(p => `<path fill="currentColor" d="${escapeHtml(String(p || ''))}"></path>`).join('');
            return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${width} ${height}" aria-hidden="true" focusable="false">${body}</svg>`;
        }

        return '';
    }

    function refreshFontAwesome(root = document) {
        try {
            if (window.FontAwesome?.dom && typeof window.FontAwesome.dom.i2svg === 'function') {
                window.FontAwesome.dom.i2svg({ node: root });
            }
        } catch (e) {
            // Vorschau bleibt notfalls als <i>-Element stehen.
        }
    }

    function resolveLoadedSymconIcon(icon) {
        const raw = String(icon || '').trim();
        const normalized = normalizeSymconIcon(raw);
        const available = new Set(availableSymconIcons());
        if (available.has(normalized)) return normalized;

        const rawSlug = raw
            .replace(/^fa-(light|brands|kit|solid|regular|thin|duotone|sharp)\s+/i, '')
            .replace(/^fa-/i, '')
            .replace(/([a-z])([A-Z])/g, '$1-$2')
            .replace(/[_\s]+/g, '-')
            .replace(/[^a-zA-Z0-9-]/g, '')
            .toLowerCase();
        const candidates = [
            `fa-light fa-${rawSlug}`,
            `fa-kit fa-${rawSlug}`
        ];
        for (const candidate of candidates) if (available.has(candidate)) return candidate;

        const compact = rawSlug.replace(/-/g, '');
        const fuzzy = Array.from(available).find(entry => iconSearchText(entry).replace(/\s+/g, '') === compact);
        return fuzzy || normalized;
    }

    function iconPreviewHtml(icon, storedSvg = '') {
        const svg = String(storedSvg || '').trim();
        if (svg.startsWith('<svg')) return svg;
        const normalized = resolveLoadedSymconIcon(icon || 'fa-light fa-circle');
        const generated = fontAwesomeSvgHtml(normalized);
        if (generated) return generated;
        return `<i class="${escapeHtml(normalized)}"></i>`;
    }

    function propertyIconPreviewHtml(item) {
        const icon = automaticVariableIcon(item);
        const storedSvg = item?.iconManual === true ? String(item?.iconSvg || '').trim() : '';
        return iconPreviewHtml(icon, storedSvg);
    }

    function legacyAdaptiveIconForState(icon, stateValue) {
        const raw = String(icon || '').trim();
        if (!raw) return '';
        if (/\bfa-(light|brands|kit|solid|regular|thin|duotone|sharp)\b/i.test(raw)) {
            return normalizeSymconIcon(raw);
        }

        const isOn = Boolean(stateValue);
        const base = raw
            .replace(/-(?:0|1|25|30|50|60|75|100)$/i, '')
            .replace(/\.Reversed$/i, '')
            .toLowerCase()
            .replace(/[^a-z0-9]/g, '');

        const pairs = {
            bulb:       ['fa-light fa-lightbulb-slash', 'fa-light fa-lightbulb'],
            light:      ['fa-light fa-lightbulb-slash', 'fa-light fa-lightbulb'],
            lock:       ['fa-light fa-lock-open', 'fa-light fa-lock'],
            presence:   ['fa-light fa-person-circle-xmark', 'fa-light fa-person'],
            raffstore:  ['fa-light fa-blinds', 'fa-light fa-blinds-open'],
            speaker:    ['fa-light fa-volume-xmark', 'fa-light fa-volume-high'],
            window:     ['fa-light fa-window-frame', 'fa-light fa-window-frame-open'],
            climate:    ['fa-light fa-temperature-empty', 'fa-light fa-temperature-half'],
            temperature:['fa-light fa-temperature-empty', 'fa-light fa-temperature-half'],
            intensity:  ['fa-light fa-circle', 'fa-light fa-circle-dot'],
            speedo:     ['fa-light fa-gauge-min', 'fa-light fa-gauge-high'],
            windspeed:  ['fa-light fa-wind', 'fa-light fa-wind']
        };
        if (pairs[base]) return pairs[base][isOn ? 1 : 0];

        // Normale Legacy-Namen weiterhin wie früher auf Symcon/FontAwesome abbilden.
        return normalizeSymconIcon(raw);
    }

    function boolIconForState(item, stateValue) {
        const isOn = Boolean(stateValue);
        if (item?.boolIconsManual === true) {
            const manualIcon = isOn ? item?.iconTrue : item?.iconFalse;
            if (String(manualIcon || '').trim() !== '') return normalizeSymconIcon(manualIcon);
        }
        const autoIcon = isOn ? item?._iconTrue : item?._iconFalse;
        if (String(autoIcon || '').trim() !== '') return legacyAdaptiveIconForState(autoIcon, isOn);
        return automaticVariableIcon({...item, _variableType: -1});
    }

    function boolIconPreviewHtml(item, stateValue) {
        const isOn = Boolean(stateValue);
        const icon = boolIconForState(item, isOn);
        const storedSvg = item?.boolIconsManual === true
            ? String(isOn ? (item?.iconTrueSvg || '') : (item?.iconFalseSvg || '')).trim()
            : '';
        return iconPreviewHtml(icon, storedSvg);
    }

    function symconAssociationForValue(entries, raw, variableType) {
        const list = Array.isArray(entries) ? entries : [];
        if (!list.length) return null;

        // Boolean und String sind echte Zuordnungen.
        if (Number(variableType) === 0) {
            return list.find(entry => Boolean(truthyVariableValue(entry?.value)) === Boolean(truthyVariableValue(raw))) || null;
        }
        if (Number(variableType) === 3) {
            return list.find(entry => String(entry?.value) === String(raw)) || null;
        }

        const rv = Number(raw);
        if (!Number.isFinite(rv)) {
            return list.find(entry => String(entry?.value) === String(raw)) || null;
        }

        // Symcon-Assoziationen für Integer/Float gelten ab ihrem Wert bis zur
        // nächsten Assoziation. Deshalb nicht nur auf exakte Gleichheit prüfen.
        const numeric = list
            .map(entry => ({entry, value: Number(entry?.value)}))
            .filter(pair => Number.isFinite(pair.value))
            .sort((a, b) => a.value - b.value);
        let match = null;
        for (const pair of numeric) {
            if (rv >= pair.value) match = pair.entry;
            else break;
        }
        return match || (numeric.length ? numeric[0].entry : null);
    }

    function automaticVariableIcon(item) {
        if (Number(item?._variableType) === 0) {
            const raw = truthyVariableValue(item?._rawValue);
            if (item?.boolIconsManual === true) {
                const manualStateIcon = raw ? item?.iconTrue : item?.iconFalse;
                if (String(manualStateIcon || '').trim() !== '') return normalizeSymconIcon(manualStateIcon);
            }
            const stateIcon = raw ? item?._iconTrue : item?._iconFalse;
            if (String(stateIcon || '').trim() !== '') return legacyAdaptiveIconForState(stateIcon, raw);
        }

        if (item?.iconManual === true) {
            return normalizeSymconIcon(item.icon || 'fa-light fa-circle');
        }

        const resolved = String(item?._autoIcon || '').trim();
        if (resolved !== '') return normalizeSymconIcon(resolved);

        const objectIcon = String(item?._objectIcon || '').trim();
        if (objectIcon !== '') return normalizeSymconIcon(objectIcon);
        return normalizeSymconIcon(item?.icon || defaultSymconIconForLegacyKind(item?.kind));
    }

    function renderSymconGlyph(icon, radius, storedSvg = '') {
        // Immer zuerst gegen die tatsächlich durch /icons.js geladenen Icons auflösen.
        // Das ist wichtig für aus Variablen übernommene neue Icons und Legacy-Mappings.
        const parsed = parseSymconIcon(resolveLoadedSymconIcon(icon));
        const r = Math.max(8, Number(radius) || 18);
        const fontSize = Math.max(12, r * 1.18);
        // Bei manueller Auswahl speichern wir das von /icons.js tatsächlich erzeugte SVG mit.
        // Damit muss das Icon beim nächsten Rendern nicht erneut anhand seines Namens aufgelöst werden.
        const persisted = String(storedSvg || '').trim();
        const svgHtml = persisted !== '' ? persisted : fontAwesomeSvgHtml(parsed.cls);
        const content = svgHtml !== ''
            ? svgHtml
            : `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>`;
        return `<foreignObject class="device-icon-foreign" x="${-r}" y="${-r}" width="${r * 2}" height="${r * 2}" pointer-events="none">` +
            `<div xmlns="http://www.w3.org/1999/xhtml" class="device-icon-html" style="font-size:${fontSize}px">${content}</div></foreignObject>`;
    }


    const originalMdiPaths = {
        'mdi:lightbulb': 'M12,2A7,7 0 0,0 5,9C5,11.38 6.19,13.47 8,14.74V17A1,1 0 0,0 9,18H15A1,1 0 0,0 16,17V14.74C17.81,13.47 19,11.38 19,9A7,7 0 0,0 12,2M9,21A1,1 0 0,0 10,22H14A1,1 0 0,0 15,21V20H9V21Z',
        'mdi:toggle-switch': 'M17,7H7A5,5 0 0,0 2,12A5,5 0 0,0 7,17H17A5,5 0 0,0 22,12A5,5 0 0,0 17,7M17,15A3,3 0 0,1 14,12A3,3 0 0,1 17,9A3,3 0 0,1 20,12A3,3 0 0,1 17,15Z',
        'mdi:power-socket-eu': 'M7.5,10.5A1.5,1.5 0 0,1 9,12A1.5,1.5 0 0,1 7.5,13.5C6.66,13.5 6,12.83 6,12A1.5,1.5 0 0,1 7.5,10.5M16.5,10.5A1.5,1.5 0 0,1 18,12A1.5,1.5 0 0,1 16.5,13.5A1.5,1.5 0 0,1 15,12A1.5,1.5 0 0,1 16.5,10.5M4.22,2H19.78C21,2 22,3 22,4.22V19.78A2.22,2.22 0 0,1 19.78,22H4.22C3,22 2,21 2,19.78V4.22A2.22,2.22 0 0,1 4.22,2M12,4A8,8 0 0,0 4,12A8,8 0 0,0 12,20A8,8 0 0,0 20,12A8,8 0 0,0 12,4Z',
        'mdi:thermometer': 'M15 13V5A3 3 0 0 0 9 5V13A5 5 0 1 0 15 13M12 4A1 1 0 0 1 13 5V8H11V5A1 1 0 0 1 12 4Z',
        'mdi:water-percent': 'M12,3.25C12,3.25 6,10 6,14C6,17.32 8.69,20 12,20A6,6 0 0,0 18,14C18,10 12,3.25 12,3.25M14.47,9.97L15.53,11.03L9.53,17.03L8.47,15.97M9.75,10A1.25,1.25 0 0,1 11,11.25A1.25,1.25 0 0,1 9.75,12.5A1.25,1.25 0 0,1 8.5,11.25A1.25,1.25 0 0,1 9.75,10M14.25,14.5A1.25,1.25 0 0,1 15.5,15.75A1.25,1.25 0 0,1 14.25,17A1.25,1.25 0 0,1 13,15.75A1.25,1.25 0 0,1 14.25,14.5Z',
        'mdi:motion-sensor': 'M10,0.2C9,0.2 8.2,1 8.2,2C8.2,3 9,3.8 10,3.8C11,3.8 11.8,3 11.8,2C11.8,1 11,0.2 10,0.2M15.67,1A7.33,7.33 0 0,0 23,8.33V7A6,6 0 0,1 17,1H15.67M18.33,1C18.33,3.58 20.42,5.67 23,5.67V4.33C21.16,4.33 19.67,2.84 19.67,1H18.33M21,1A2,2 0 0,0 23,3V1H21M7.92,4.03C7.75,4.03 7.58,4.06 7.42,4.11L2,5.8V11H3.8V7.33L5.91,6.67L2,22H3.8L6.67,13.89L9,17V22H10.8V15.59L8.31,11.05L9.04,8.18L10.12,10H15V8.2H11.38L9.38,4.87C9.08,4.37 8.54,4.03 7.92,4.03Z',
        'mdi:door': 'M8,3C6.89,3 6,3.89 6,5V21H18V5C18,3.89 17.11,3 16,3H8M8,5H16V19H8V5M13,11V13H15V11H13Z',
        'mdi:window-closed': 'M6,11H10V9H14V11H18V4H6V11M18,13H6V20H18V13M6,2H18A2,2 0 0,1 20,4V20A2,2 0 0,1 18,22H6A2,2 0 0,1 4,20V4A2,2 0 0,1 6,2Z',
        'mdi:blinds': 'M3,2H21A1,1 0 0,1 22,3V5A1,1 0 0,1 21,6H20V13A1,1 0 0,1 19,14H13V16.17C14.17,16.58 15,17.69 15,19A3,3 0 0,1 12,22A3,3 0 0,1 9,19C9,17.69 9.83,16.58 11,16.17V14H5A1,1 0 0,1 4,13V6H3A1,1 0 0,1 2,5V3A1,1 0 0,1 3,2M12,18A1,1 0 0,0 11,19A1,1 0 0,0 12,20A1,1 0 0,0 13,19A1,1 0 0,0 12,18Z',
        'mdi:thermostat': 'M16.95,16.95L14.83,14.83C15.55,14.1 16,13.1 16,12C16,11.26 15.79,10.57 15.43,10L17.6,7.81C18.5,9 19,10.43 19,12C19,13.93 18.22,15.68 16.95,16.95M12,5C13.57,5 15,5.5 16.19,6.4L14,8.56C13.43,8.21 12.74,8 12,8A4,4 0 0,0 8,12C8,13.1 8.45,14.1 9.17,14.83L7.05,16.95C5.78,15.68 5,13.93 5,12A7,7 0 0,1 12,5M12,2A10,10 0 0,0 2,12A10,10 0 0,0 12,22A10,10 0 0,0 22,12C22,6.47 17.5,2 12,2Z',
        'mdi:fan': 'M12,11A1,1 0 0,0 11,12A1,1 0 0,0 12,13A1,1 0 0,0 13,12A1,1 0 0,0 12,11M12.5,2C17,2 17.11,5.57 14.75,6.75C13.76,7.24 13.32,8.29 13.13,9.22C13.61,9.42 14.03,9.73 14.35,10.13C18.05,8.13 22.03,8.92 22.03,12.5C22.03,17 18.46,17.1 17.28,14.73C16.78,13.74 15.72,13.3 14.79,13.11C14.59,13.59 14.28,14 13.88,14.34C15.87,18.03 15.08,22 11.5,22C7,22 6.91,18.42 9.27,17.24C10.25,16.75 10.69,15.71 10.89,14.79C10.4,14.59 9.97,14.27 9.65,13.87C5.96,15.85 2,15.07 2,11.5C2,7 5.56,6.89 6.74,9.26C7.24,10.25 8.29,10.68 9.22,10.87C9.41,10.39 9.73,9.97 10.14,9.65C8.15,5.96 8.94,2 12.5,2Z',
        'mdi:radiator': 'M7.95,3L6.53,5.19L7.95,7.4H7.94L5.95,10.5L4.22,9.6L5.64,7.39L4.22,5.19L6.22,2.09L7.95,3M13.95,2.89L12.53,5.1L13.95,7.3L13.94,7.31L11.95,10.4L10.22,9.5L11.64,7.3L10.22,5.1L12.22,2L13.95,2.89M20,2.89L18.56,5.1L20,7.3V7.31L18,10.4L16.25,9.5L17.67,7.3L16.25,5.1L18.25,2L20,2.89M2,22V14A2,2 0 0,1 4,12H20A2,2 0 0,1 22,14V22H20V20H4V22H2M6,14A1,1 0 0,0 5,15V17A1,1 0 0,0 6,18A1,1 0 0,0 7,17V15A1,1 0 0,0 6,14M10,14A1,1 0 0,0 9,15V17A1,1 0 0,0 10,18A1,1 0 0,0 11,17V15A1,1 0 0,0 10,14M14,14A1,1 0 0,0 13,15V17A1,1 0 0,0 14,18A1,1 0 0,0 15,17V15A1,1 0 0,0 14,14M18,14A1,1 0 0,0 17,15V17A1,1 0 0,0 18,18A1,1 0 0,0 19,17V15A1,1 0 0,0 18,14Z',
        'mdi:television': 'M21,17H3V5H21M21,3H3A2,2 0 0,0 1,5V17A2,2 0 0,0 3,19H8V21H16V19H21A2,2 0 0,0 23,17V5A2,2 0 0,0 21,3Z',
        'mdi:camera': 'M4,4H7L9,2H15L17,4H20A2,2 0 0,1 22,6V18A2,2 0 0,1 20,20H4A2,2 0 0,1 2,18V6A2,2 0 0,1 4,4M12,7A5,5 0 0,0 7,12A5,5 0 0,0 12,17A5,5 0 0,0 17,12A5,5 0 0,0 12,7M12,9A3,3 0 0,1 15,12A3,3 0 0,1 12,15A3,3 0 0,1 9,12A3,3 0 0,1 12,9Z',
        'mdi:washing-machine': 'M14.83,11.17C16.39,12.73 16.39,15.27 14.83,16.83C13.27,18.39 10.73,18.39 9.17,16.83L14.83,11.17M6,2H18A2,2 0 0,1 20,4V20A2,2 0 0,1 18,22H6A2,2 0 0,1 4,20V4A2,2 0 0,1 6,2M7,4A1,1 0 0,0 6,5A1,1 0 0,0 7,6A1,1 0 0,0 8,5A1,1 0 0,0 7,4M10,4A1,1 0 0,0 9,5A1,1 0 0,0 10,6A1,1 0 0,0 11,5A1,1 0 0,0 10,4M12,8A6,6 0 0,0 6,14A6,6 0 0,0 12,20A6,6 0 0,0 18,14A6,6 0 0,0 12,8Z',
        'mdi:dishwasher': 'M18,2H6A2,2 0 0,0 4,4V20A2,2 0 0,0 6,22H18A2,2 0 0,0 20,20V4A2,2 0 0,0 18,2M10,4A1,1 0 0,1 11,5A1,1 0 0,1 10,6A1,1 0 0,1 9,5A1,1 0 0,1 10,4M7,4A1,1 0 0,1 8,5A1,1 0 0,1 7,6A1,1 0 0,1 6,5A1,1 0 0,1 7,4M18,20H6V8H18V20M14.67,15.33C14.69,16.03 14.41,16.71 13.91,17.21C12.86,18.26 11.15,18.27 10.09,17.21C9.59,16.71 9.31,16.03 9.33,15.33C9.4,14.62 9.63,13.94 10,13.33C10.37,12.5 10.81,11.73 11.33,11L12,10C13.79,12.59 14.67,14.36 14.67,15.33',
        'mdi:water-boiler': 'M8 2C6.89 2 6 2.89 6 4V16C6 17.11 6.89 18 8 18H9V20H6V22H9C10.11 22 11 21.11 11 20V18H13V20C13 21.11 13.89 22 15 22H18V20H15V18H16C17.11 18 18 17.11 18 16V4C18 2.89 17.11 2 16 2H8M12 4.97A2 2 0 0 1 14 6.97A2 2 0 0 1 12 8.97A2 2 0 0 1 10 6.97A2 2 0 0 1 12 4.97M10 14.5H14V16H10V14.5Z',
        'mdi:car-electric': 'M18.92 2C18.72 1.42 18.16 1 17.5 1H6.5C5.84 1 5.29 1.42 5.08 2L3 8V16C3 16.55 3.45 17 4 17H5C5.55 17 6 16.55 6 16V15H18V16C18 16.55 18.45 17 19 17H20C20.55 17 21 16.55 21 16V8L18.92 2M6.5 12C5.67 12 5 11.33 5 10.5S5.67 9 6.5 9 8 9.67 8 10.5 7.33 12 6.5 12M17.5 12C16.67 12 16 11.33 16 10.5S16.67 9 17.5 9 19 9.67 19 10.5 18.33 12 17.5 12M5 7L6.5 2.5H17.5L19 7H5M7 20H11V18L17 21H13V23L7 20Z',
        'mdi:robot-vacuum': 'M12,2C14.65,2 17.19,3.06 19.07,4.93L17.65,6.35C16.15,4.85 14.12,4 12,4C9.88,4 7.84,4.84 6.35,6.35L4.93,4.93C6.81,3.06 9.35,2 12,2M3.66,6.5L5.11,7.94C4.39,9.17 4,10.57 4,12A8,8 0 0,0 12,20A8,8 0 0,0 20,12C20,10.57 19.61,9.17 18.88,7.94L20.34,6.5C21.42,8.12 22,10.04 22,12A10,10 0 0,1 12,22A10,10 0 0,1 2,12C2,10.04 2.58,8.12 3.66,6.5M12,6A6,6 0 0,1 18,12C18,13.59 17.37,15.12 16.24,16.24L14.83,14.83C14.08,15.58 13.06,16 12,16C10.94,16 9.92,15.58 9.17,14.83L7.76,16.24C6.63,15.12 6,13.59 6,12A6,6 0 0,1 12,6M12,8A1,1 0 0,0 11,9A1,1 0 0,0 12,10A1,1 0 0,0 13,9A1,1 0 0,0 12,8Z',
        'mdi:lock': 'M12,17A2,2 0 0,0 14,15C14,13.89 13.1,13 12,13A2,2 0 0,0 10,15A2,2 0 0,0 12,17M18,8A2,2 0 0,1 20,10V20A2,2 0 0,1 18,22H6A2,2 0 0,1 4,20V10C4,8.89 4.9,8 6,8H7V6A5,5 0 0,1 12,1A5,5 0 0,1 17,6V8H18M12,3A3,3 0 0,0 9,6V8H15V6A3,3 0 0,0 12,3Z',
        'mdi:home': 'M10,20V14H14V20H19V12H22L12,3L2,12H5V20H10Z',
        'mdi:cog': 'M12,15.5A3.5,3.5 0 0,1 8.5,12A3.5,3.5 0 0,1 12,8.5A3.5,3.5 0 0,1 15.5,12A3.5,3.5 0 0,1 12,15.5M19.43,12.97C19.47,12.65 19.5,12.33 19.5,12C19.5,11.67 19.47,11.34 19.43,11L21.54,9.37C21.73,9.22 21.78,8.95 21.66,8.73L19.66,5.27C19.54,5.05 19.27,4.96 19.05,5.05L16.56,6.05C16.04,5.66 15.5,5.32 14.87,5.07L14.5,2.42C14.46,2.18 14.25,2 14,2H10C9.75,2 9.54,2.18 9.5,2.42L9.13,5.07C8.5,5.32 7.96,5.66 7.44,6.05L4.95,5.05C4.73,4.96 4.46,5.05 4.34,5.27L2.34,8.73C2.21,8.95 2.27,9.22 2.46,9.37L4.57,11C4.53,11.34 4.5,11.67 4.5,12C4.5,12.33 4.53,12.65 4.57,12.97L2.46,14.63C2.27,14.78 2.21,15.05 2.34,15.27L4.34,18.73C4.46,18.95 4.73,19.03 4.95,18.95L7.44,17.94C7.96,18.34 8.5,18.68 9.13,18.93L9.5,21.58C9.54,21.82 9.75,22 10,22H14C14.25,22 14.46,21.82 14.5,21.58L14.87,18.93C15.5,18.67 16.04,18.34 16.56,17.94L19.05,18.95C19.27,19.03 19.54,18.95 19.66,18.73L21.66,15.27C21.78,15.05 21.73,14.78 21.54,14.63L19.43,12.97Z',
        'mdi:circle': 'M12,2A10,10 0 0,0 2,12A10,10 0 0,0 12,22A10,10 0 0,0 22,12A10,10 0 0,0 12,2Z'
    };

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
        // Rollladen-Bedienelemente werden separat gesammelt und ganz zum Schluss
        // gerendert. Dadurch liegen sie immer über Möbeln und Geräten und bleiben
        // zuverlässig anklickbar.
        const shutterControlParts = [];
        renderEditorGrid(parts);
        const bounds = visibleWorldBounds(120);

        for (const w of floor.walls) {
            const sel = selected?.type === 'wall' && selected.id === w.id ? ' selected' : '';
            parts.push(
                `<line class="wall${sel}" data-type="wall" data-id="${w.id}" x1="${w.x1}" y1="${w.y1}" x2="${w.x2}" y2="${w.y2}"/>`
            );

            if (state.mode !== 'view' && selected?.type === 'wall' && selected.id === w.id) {
                // Wie bei Möbeln: kleine sichtbare Griffe direkt am Objekt.
                // Jeder Griff verändert nur das zugehörige Wandende.
                parts.push(
                    `<circle class="resize-handle" data-resize-type="wall" data-wall-end="start" data-id="${w.id}" ` +
                    `cx="${w.x1}" cy="${w.y1}" r="2.8"/>`
                );
                parts.push(
                    `<circle class="resize-handle" data-resize-type="wall" data-wall-end="end" data-id="${w.id}" ` +
                    `cx="${w.x2}" cy="${w.y2}" r="2.8"/>`
                );
            }
        }

        for (const o of floor.openings) {
            const wall = floor.walls.find(w => w.id === o.wallId);
            if (!wall) continue;

            const geom = openingGeometry(wall, o);
            const sel = selected?.type === 'opening' && selected.id === o.id ? ' selected' : '';
            const amount = openingState(o);
            const isOpen = amount > 0.02;
            const stateClass = isOpen ? ' opening-state-open' : '';

            parts.push(`<g class="opening${sel}" data-type="opening" data-id="${o.id}" style="cursor:${state.mode === 'view' ? 'default' : 'pointer'}">`);
            parts.push(`<line class="opening-hit" style="cursor:${state.mode === 'view' ? 'default' : 'move'}" x1="${geom.x1}" y1="${geom.y1}" x2="${geom.x2}" y2="${geom.y2}"/>`);
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
                    // Geöffnetes Fenster nicht mehr schräg nach außen darstellen.
                    // Der komplette Flügel bleibt parallel zur Wand und wird mit
                    // zunehmendem Öffnungsgrad gleichmäßig nach INNEN versetzt.
                    // Die negative Normale ist hier die Innenseite des Grundrisses.
                    const maxInset = 14;
                    const inset = maxInset * amount;
                    const ix1 = geom.x1 - geom.nx * inset;
                    const iy1 = geom.y1 - geom.ny * inset;
                    const ix2 = geom.x2 - geom.nx * inset;
                    const iy2 = geom.y2 - geom.ny * inset;

                    parts.push(`<line class="opening-line opening-state-open" x1="${ix1}" y1="${iy1}" x2="${ix2}" y2="${iy2}"/>`);
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

            // Rollladen-Bedienung wird in der Mitte direkt AUF dem Rollladen platziert.
            // Sie wird separat gesammelt und erst nach Möbeln/Geräten gerendert,
            // damit kein anderes SVG-Element den Klick abfangen kann.
            const shutterField =
                Number(o.shutterVariableID) > 0 ? 'shutterVariableID' :
                (Number(o.shutterSecondaryVariableID) > 0 ? 'shutterSecondaryVariableID' : '');

            if (shutterField) {
                const sx = geom.cx;
                const sy = geom.cy;
                shutterControlParts.push(
                    `<g class="shutter-control" data-shutter-control="${o.id}" data-shutter-field="${shutterField}" ` +
                    `transform="translate(${sx} ${sy})">` +
                    `<circle class="shutter-hit" r="20"/>` +
                    `<circle r="12"/>` +
                    `<text x="0" y="0">↕</text>` +
                    `</g>`
                );
            }
        }

        for (const f of floor.furniture || []) {
            const sel = selected?.type === 'furniture' && selected.id === f.id ? ' selected' : '';
            const rot = Number(f.rotation) || 0;

            parts.push(
                `<g class="furniture${sel}" data-type="furniture" data-id="${f.id}" style="cursor:${state.mode === 'view' ? 'default' : 'move'}" ` +
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
            const isBooleanDevice = Number(item._variableType) === 0;
            const boolActive = isBooleanDevice && (raw === true || raw === 1 || raw === '1' || raw === 'true');
            const statusRingEnabled = supportsStatusColor(item);
            const numericLevel = statusRingEnabled ? numericStatusLevel(item) : null;
            const numericClass = numericLevel !== null ? ' numeric-status' : '';
            const boolClass = isBooleanDevice && statusRingEnabled
                ? (boolActive ? ' boolean-active' : ' boolean-inactive')
                : '';
            // Ganzes Lampensymbol nur bei echten Boolean-Lampen als aktiv/inaktiv behandeln.
            // Integer/Float-Lampen behalten ihre normale Deckkraft; dort wird ausschließlich
            // der farbige Statusring entsprechend dem Zahlenwert gedimmt.
            const lightClass = '';
            const statusColor = normalizeStatusColor(item.statusColor);
            const icon = automaticVariableIcon(item);

            const showName = item.showName === true;
            const showValue = item.showValue === true;
            const showIcon = item.showIcon !== false;
            const valueText = item._valueText !== undefined && item._valueText !== ''
                ? String(item._valueText)
                : '—';
            const labelSize = Math.max(8, Math.min(40, Number(item.labelSize) || 12));
            const valueSize = Math.max(8, Math.min(40, Number(item.valueSize) || 12));
            const radius = Number(item.size) || 18;
            const directSlider = item.showDirectSlider === true ? directSliderConfig(item) : null;
            const directSliderY = radius + 13;
            const directSliderWidth = Math.max(64, radius * 2.8);
            const directSliderLevel = directSlider
                ? Math.max(0, Math.min(1, (directSlider.value - directSlider.min) / (directSlider.max - directSlider.min)))
                : 0;

            function deviceTextPlacement(position, size, extra = 0) {
                const pos = ['above','left','right','below'].includes(position) ? position : 'below';
                if (pos === 'above') return {x: 0, y: -(radius + 7 + extra), anchor: 'middle'};
                if (pos === 'left') return {x: -(radius + 7 + extra), y: size * .34, anchor: 'end'};
                if (pos === 'right') return {x: radius + 7 + extra, y: size * .34, anchor: 'start'};
                return {x: 0, y: radius + size + 5 + extra, anchor: 'middle'};
            }

            // Wenn ein Direkt-Slider sichtbar ist, beginnt Text mit Position "unten"
            // erst unterhalb des Sliders. Name und Wert werden danach wie bisher
            // untereinander angeordnet.
            const sliderTextOffset = directSlider ? 16 : 0;
            const namePosition = item.labelPosition || 'below';
            const valuePosition = item.valuePosition || 'below';
            const nameBaseExtra = namePosition === 'below' ? sliderTextOffset : 0;
            const valueBaseExtra = valuePosition === 'below' ? sliderTextOffset : 0;

            const namePlace = deviceTextPlacement(namePosition, labelSize, nameBaseExtra);
            let valueExtra = valueBaseExtra;
            if (showName && valuePosition === namePosition) {
                valueExtra += Math.max(labelSize, valueSize) + 3;
            }
            const valuePlace = deviceTextPlacement(valuePosition, valueSize, valueExtra);
            const statusOnlyClass = item._canAction === true ? '' : ' status-only';
            const valueFrameWidth = Math.max(26, valueText.length * valueSize * 0.62 + 12);
            const valueFrameHeight = valueSize + 10;
            const valueFrameX = valuePlace.anchor === 'end'
                ? valuePlace.x - valueFrameWidth - 4
                : (valuePlace.anchor === 'start' ? valuePlace.x - 4 : valuePlace.x - valueFrameWidth / 2);
            const valueFrameY = valuePlace.y - valueSize * 0.82 - 5;

            parts.push(
                `<g class="device${sel}${numericClass}${boolClass}${lightClass}${statusOnlyClass}" data-type="item" data-id="${item.id}" ` +
                `style="cursor:pointer;--device-status-color:${statusColor};--device-status-opacity:${numericLevel !== null ? numericLevel.toFixed(3) : 1};--device-status-glow:${numericLevel !== null ? (numericLevel * 8).toFixed(2) : 0}px" transform="translate(${item.x} ${item.y})">` +
                (showIcon
                    ? `<circle r="${radius}"/>` +
                      (numericLevel !== null ? `<circle class="device-status-ring" r="${radius}"/>` : '') +
                      `<g class="device-glyph" transform="rotate(${Number(item.angle) || 0})">${renderSymconGlyph(icon, radius * .78, item.iconManual === true ? (item.iconSvg || '') : '')}</g>`
                    : '') +
                (showName && item.name
                    ? `<text class="device-label" x="${namePlace.x}" y="${namePlace.y}" text-anchor="${namePlace.anchor}" font-size="${labelSize}">${escapeHtml(String(item.name))}</text>`
                    : '') +
                (showValue && item.valueFrame === true
                    ? `<rect class="runtime-value-frame" x="${valueFrameX}" y="${valueFrameY}" width="${valueFrameWidth}" height="${valueFrameHeight}" rx="4"/>`
                    : '') +
                (showValue
                    ? `<text class="runtime-value" x="${valuePlace.x}" y="${valuePlace.y}" text-anchor="${valuePlace.anchor}" font-size="${valueSize}">${escapeHtml(valueText)}</text>`
                    : '') +
                (directSlider
                    ? `<g class="device-direct-slider" data-direct-slider="${item.id}" transform="translate(0 ${directSliderY})">` +
                      `<line class="device-direct-slider-hit" x1="${-directSliderWidth / 2}" y1="0" x2="${directSliderWidth / 2}" y2="0"/>` +
                      `<line class="device-direct-slider-track" x1="${-directSliderWidth / 2}" y1="0" x2="${directSliderWidth / 2}" y2="0"/>` +
                      `<line class="device-direct-slider-fill" x1="${-directSliderWidth / 2}" y1="0" x2="${-directSliderWidth / 2 + directSliderWidth * directSliderLevel}" y2="0"/>` +
                      `<circle class="device-direct-slider-thumb" cx="${-directSliderWidth / 2 + directSliderWidth * directSliderLevel}" cy="0" r="6.5"/>` +
                      `</g>`
                    : '') +
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

        // Immer als letzte Ebene: Rollladen-Steuerung bleibt sichtbar und klickbar,
        // selbst wenn an derselben Position ein Möbelstück oder Gerät liegt.
        parts.push(...shutterControlParts);

        scene.innerHTML = parts.join('');

        // Die Geräte-Icons werden dynamisch per scene.innerHTML erzeugt. Font Awesome
        // hat diesen neuen DOM-Inhalt beim initialen Laden von /icons.js noch nicht gesehen.
        // Deshalb nach JEDEM Rendern explizit in SVG umwandeln.
        refreshFontAwesome(scene);
        requestAnimationFrame(() => refreshFontAwesome(scene));
        setTransform();
        renderProperties();
        renderFloorSelect();
    }

    function renderAll() {
        render();
        updateUndoButtons();
    }

    function normalizeStatusColor(value) {
        const color = String(value || '').trim();
        return /^#[0-9a-f]{6}$/i.test(color) ? color : '#ffe66d';
    }

    function supportsStatusColor(item) {
        // Ohne Gerätetyp entscheidet nur noch die Variable, ob eine Statusfarbe
        // sinnvoll dargestellt werden kann. Die Bedienlogik bleibt unverändert.
        const type = Number(item?._variableType);
        if (type === 0) return true;
        if (type === 1 || type === 2) return numericStatusLevel(item) !== null;
        return false;
    }

    function numericStatusLevel(item) {
        const type = Number(item?._variableType);
        if (type !== 1 && type !== 2) return null;

        const profile = item?._profile || {};
        const associations = Array.isArray(profile.associations) ? profile.associations : [];
        if (associations.length > 0) return null;

        const raw = Number(item?._rawValue);
        const min = Number(profile.min);
        const max = Number(profile.max);
        if (!Number.isFinite(raw) || !Number.isFinite(min) || !Number.isFinite(max) || max <= min) return null;

        return Math.max(0, Math.min(1, (raw - min) / (max - min)));
    }

    function directSliderConfig(item) {
        // Ein Slider ist nur für Variablen sinnvoll, die in IP-Symcon
        // tatsächlich eine Aktion besitzen. Reine Istwerte bleiben Anzeige.
        if (item?._canAction !== true) return null;

        const type = Number(item?._variableType);
        if (type !== 1 && type !== 2) return null;

        const profile = item?._profile || {};
        const associations = Array.isArray(profile.associations) ? profile.associations : [];
        if (associations.length > 0) return null;

        const min = Number(profile.min);
        const max = Number(profile.max);
        if (!Number.isFinite(min) || !Number.isFinite(max) || max <= min) return null;

        const configuredStep = Number(profile.step);
        const step = Number.isFinite(configuredStep) && configuredStep > 0
            ? configuredStep
            : (type === 2 ? Math.max((max - min) / 100, 0.01) : 1);

        const raw = Number(item?._rawValue);
        const value = Number.isFinite(raw) ? Math.max(min, Math.min(max, raw)) : min;
        return {min, max, step, value};
    }

    function directSliderValueFromPointer(item, clientX, clientY) {
        const cfg = directSliderConfig(item);
        if (!cfg) return null;

        // Mauskoordinate in Grundriss-/Scene-Koordinaten umrechnen.
        // Dadurch funktioniert das Ziehen unabhängig von Zoom und Pan.
        const raw = svgPointRaw({clientX, clientY});
        const radius = Number(item.size) || 18;
        const width = Math.max(64, radius * 2.8);
        const localX = raw.x - (Number(item.x) || 0);

        const fraction = Math.max(0, Math.min(1, (localX + width / 2) / width));
        let value = cfg.min + fraction * (cfg.max - cfg.min);

        value = Math.round((value - cfg.min) / cfg.step) * cfg.step + cfg.min;
        value = Math.max(cfg.min, Math.min(cfg.max, value));

        if (Number(item._variableType) === 1) {
            value = Math.round(value);
        } else {
            // Float-Werte auf sinnvolle Genauigkeit begrenzen.
            value = Math.round(value * 10000) / 10000;
        }

        return value;
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

    function mappedShutterAmount(o) {
        if (!o || o.shutterValueMappingEnabled !== true) return null;
        if (Number(o._shutterVariableType) !== 1) return null;

        const map = o.shutterValueMap && typeof o.shutterValueMap === 'object'
            ? o.shutterValueMap
            : {};
        const raw = Number(o._shutterVariableRawValue);
        if (!Number.isFinite(raw)) return null;

        const key = String(raw);
        if (!Object.prototype.hasOwnProperty.call(map, key)) return null;

        const mapped = map[key];

        // "keep" bedeutet: Dieser Befehlswert beschreibt keine feste Position
        // (z.B. Stop / Schritt auf / Schritt zu). Dann bleibt die zuletzt
        // bekannte grafische Stellung während der Laufzeit erhalten.
        if (mapped === 'keep') {
            return Number.isFinite(Number(o._shutterVisualAmount))
                ? Math.max(0, Math.min(1, Number(o._shutterVisualAmount)))
                : 0;
        }

        const percent = Number(mapped);
        if (!Number.isFinite(percent)) return null;

        const amount = Math.max(0, Math.min(1, percent / 100));
        o._shutterVisualAmount = amount;
        return amount;
    }

    function shutterState(o) {
        let amount = mappedShutterAmount(o);

        if (amount === null) {
            amount = normalizedOpeningAmount(
                o._shutterVariableRawValue,
                o._shutterVariableType,
                o._shutterVariableProfile,
                false
            );
            o._shutterVisualAmount = amount;
        }

        amount = Math.max(0, Math.min(1, amount));
        return o.shutterInvert === true ? 1 - amount : amount;
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

    function defaultDisplayModeForKind(kind) {
        return ['temperature','humidity'].includes(kind) ? 'value' : 'icon';
    }

    function shutterMappingOptions(selectedValue) {
        const options = [
            ['keep', 'Position nicht ändern'],
            ['100', 'Offen (100 %)'],
            ['75', '75 % offen'],
            ['50', 'Halb (50 %)'],
            ['25', '25 % offen'],
            ['0', 'Geschlossen (0 %)']
        ];

        return options.map(([value, label]) =>
            `<option value="${value}"${String(selectedValue) === value ? ' selected' : ''}>${label}</option>`
        ).join('');
    }

    function shutterValueMappingHtml(obj) {
        if (!obj || Number(obj.shutterVariableID || 0) <= 0) return '';
        if (Number(obj._shutterVariableType) !== 1) return '';

        const profile = obj._shutterVariableProfile || {};
        const associations = Array.isArray(profile.associations) ? profile.associations : [];
        if (!associations.length) return '';

        const enabled = obj.shutterValueMappingEnabled === true;
        const map = obj.shutterValueMap && typeof obj.shutterValueMap === 'object'
            ? obj.shutterValueMap
            : {};

        let html = `
            <div class="field">
                <label class="check">
                    <input data-field="shutterValueMappingEnabled" type="checkbox"${enabled ? ' checked' : ''}>
                    Rollo-Werte zuordnen
                </label>
            </div>
        `;

        if (!enabled) return html;

        html += `<div class="field"><label>Integerwerte → Rollo-Status</label>`;
        html += `<div class="profile-hint">Jedem Profilwert kannst du eine grafische Stellung zuordnen.</div>`;

        for (const association of associations) {
            const rawValue = Number(association.value);
            if (!Number.isFinite(rawValue)) continue;

            const key = String(rawValue);
            let selectedValue = Object.prototype.hasOwnProperty.call(map, key)
                ? String(map[key])
                : 'keep';

            html += `
                <div class="row2" style="align-items:center;margin-top:6px">
                    <div class="field" style="margin:0">
                        <label>${escapeHtml(String(association.name || key))} (${escapeHtml(key)})</label>
                    </div>
                    <div class="field" style="margin:0">
                        <select data-shutter-map-value="${escapeHtml(key)}">
                            ${shutterMappingOptions(selectedValue)}
                        </select>
                    </div>
                </div>
            `;
        }

        html += `</div>`;
        return html;
    }

    function renderProperties() {
        // Offene native <select>-Listen dürfen bei Hintergrund-render() nicht
        // ersetzt werden. Das gilt für ALLE Auswahllisten in den Eigenschaften
        // (Möbeltyp, Icon, Profil-nahe Auswahlfelder usw.).
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
                    <label>Reihenfolge</label>
                    <input type="number" min="1" max="${state.floors.length}" step="1" data-project="floorOrder" value="${Number(floor.order) || 1}">
                </div>
                <div class="field">
                    <label>Elemente</label>
                    <input value="${floor.walls.length} Wände, ${floor.openings.length} Öffnungen, ${floor.items.length} Geräte, ${(floor.furniture || []).length} Möbel" disabled>
                </div>
            `;
            bindPropertyInputs();
            refreshFontAwesome(properties);
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
                ` : ''}

                <div class="field">
                    <label><input data-field="invert" type="checkbox"${obj.invert === true ? ' checked' : ''}> ${obj.type === 'door' ? 'Tür' : 'Fenster'}-Animation invertieren</label>
                </div>

                ${obj.shutterVariableID ? shutterValueMappingHtml(obj) : ''}
            `;
        } else if (selected.type === 'item') {
            propTitle.textContent = 'Gerät';
            const kind = obj.kind || 'generic';
            properties.innerHTML = `
                <div class="field"><label>Name</label><input data-field="name" value="${escapeHtml(obj.name || '')}"></div>
                ${Number(obj._variableType) === 0 ? `
                    <div class="field">
                        <label>Icons für Boolean-Zustände</label>
                        <div class="row2">
                            <div class="field">
                                <label>Icon AUS</label>
                                <button id="itemIconFalseSelect" class="icon-select-button" type="button" title="Icon für AUS auswählen">
                                    <span class="icon-select-preview">${boolIconPreviewHtml(obj, false)}</span>
                                </button>
                            </div>
                            <div class="field">
                                <label>Icon EIN</label>
                                <button id="itemIconTrueSelect" class="icon-select-button" type="button" title="Icon für EIN auswählen">
                                    <span class="icon-select-preview">${boolIconPreviewHtml(obj, true)}</span>
                                </button>
                            </div>
                        </div>
                        <button id="itemBoolIconsAuto" type="button">Icons aus Variable übernehmen</button>
                    </div>
                ` : `
                    <div class="field">
                        <label>Icon</label>
                        <button id="itemIconSelect" class="icon-select-button" type="button" title="IP-Symcon Icon auswählen">
                            <span class="icon-select-preview">${propertyIconPreviewHtml(obj)}</span>
                        </button>
                        <div class="profile-hint">Beim Zuordnen einer Variable wird deren IP-Symcon-Icon automatisch übernommen. Danach kann es hier geändert werden.</div>
                    </div>
                `}
                <div class="field">
                    <label>IP-Symcon Variable</label>
                    <input id="variableField" class="variable-select-field" data-variable-field="variableID" readonly title="Variable auswählen"
                        value="${obj.variableID ? '#' + obj.variableID + (obj._variablePath ? ' – ' + escapeHtml(obj._variablePath) : '') : 'nicht zugeordnet'}">
                    ${obj._profileName ? `<div class="profile-hint">Profil: ${escapeHtml(obj._profileName)}${obj._profileSummary ? ' · ' + escapeHtml(obj._profileSummary) : ''}</div>` : ''}
                </div>
                <div class="row2">
                    <div class="field"><label class="check"><input data-field="showName" type="checkbox"${obj.showName === true ? ' checked' : ''}> Name anzeigen</label></div>
                    <div class="field"><label class="check"><input data-field="showValue" type="checkbox"${obj.showValue === true ? ' checked' : ''}> Wert anzeigen</label></div>
                </div>
                <div class="row2">
                    <div class="field"><label class="check"><input data-field="showIcon" type="checkbox"${obj.showIcon !== false ? ' checked' : ''}> Symbol anzeigen</label></div>
                    <div class="field"><label class="check"><input data-field="valueFrame" type="checkbox"${obj.valueFrame === true ? ' checked' : ''}> Rahmen um Istwert</label></div>
                </div>
                ${obj.variableID && obj._canAction !== true ? `<div class="profile-hint">Reiner Istwert: Für diese Variable ist keine Aktion hinterlegt. Sie wird deshalb nur angezeigt und nicht bedient.</div>` : ''}
                ${directSliderConfig(obj) ? `
                    <div class="field">
                        <label class="check"><input data-field="showDirectSlider" type="checkbox"${obj.showDirectSlider === true ? ' checked' : ''}> Slider anzeigen</label>
                        <div class="profile-hint">Nur für echte Zahlenbereiche ohne Profil-Assoziationen.</div>
                    </div>
                ` : ''}
                ${supportsStatusColor(obj) ? `
                    <div class="field">
                        <label>${Number(obj._variableType) === 0 ? 'Statusfarbe EIN' : 'Statusfarbe'}</label>
                        <input data-field="statusColor" type="color" value="${normalizeStatusColor(obj.statusColor)}">
                        ${Number(obj._variableType) !== 0 ? `<div class="profile-hint">Leuchtstärke folgt dem Wert zwischen Profil-Minimum und -Maximum.</div>` : ''}
                    </div>
                ` : ''}
                <div class="row2">
                    <div class="field"><label>Namensgröße</label><input data-field="labelSize" type="number" min="8" max="40" value="${obj.labelSize || 12}"></div>
                    <div class="field"><label>Wertgröße</label><input data-field="valueSize" type="number" min="8" max="40" value="${obj.valueSize || 12}"></div>
                </div>
                <div class="row2">
                    <div class="field">
                        <label>Name Position</label>
                        <select data-field="labelPosition">
                            <option value="below"${(obj.labelPosition || 'below') === 'below' ? ' selected' : ''}>unten</option>
                            <option value="above"${obj.labelPosition === 'above' ? ' selected' : ''}>oben</option>
                            <option value="left"${obj.labelPosition === 'left' ? ' selected' : ''}>links</option>
                            <option value="right"${obj.labelPosition === 'right' ? ' selected' : ''}>rechts</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Wert Position</label>
                        <select data-field="valuePosition">
                            <option value="below"${(obj.valuePosition || 'below') === 'below' ? ' selected' : ''}>unten</option>
                            <option value="above"${obj.valuePosition === 'above' ? ' selected' : ''}>oben</option>
                            <option value="left"${obj.valuePosition === 'left' ? ' selected' : ''}>links</option>
                            <option value="right"${obj.valuePosition === 'right' ? ' selected' : ''}>rechts</option>
                        </select>
                    </div>
                </div>
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
        refreshFontAwesome(properties);
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

                if (selected.type === 'opening' && fieldName === 'shutterValueMappingEnabled') {
                    if (!obj.shutterValueMap || typeof obj.shutterValueMap !== 'object' || Array.isArray(obj.shutterValueMap)) {
                        obj.shutterValueMap = {};
                    }
                    refreshPropertiesAfterStructuralChange();
                }

                if (selected.type === 'item' && fieldName === 'displayMode') {
                    obj.displayModeManual = true;
                }

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

        properties.querySelectorAll('[data-shutter-map-value]').forEach(select => {
            select.addEventListener('change', () => {
                if (!selected || selected.type !== 'opening') return;

                const obj = findEntity('opening', selected.id);
                if (!obj) return;

                if (!obj.shutterValueMap || typeof obj.shutterValueMap !== 'object' || Array.isArray(obj.shutterValueMap)) {
                    obj.shutterValueMap = {};
                }

                const rawValue = String(select.dataset.shutterMapValue || '');
                if (!rawValue) return;

                obj.shutterValueMap[rawValue] = String(select.value || 'keep');

                // Die neue Zuordnung sofort auf die aktuelle grafische Stellung anwenden.
                if (rawValue === String(Number(obj._shutterVariableRawValue))) {
                    delete obj._shutterVisualAmount;
                }

                pushHistory();
                markDirty();
                render();
            });
        });

        const itemIconSelect = properties.querySelector('#itemIconSelect');
        if (itemIconSelect) {
            itemIconSelect.addEventListener('click', () => {
                if (!selected || selected.type !== 'item') return;
                iconPickerTarget = {floorId: state.activeFloor, itemId: selected.id};
                openSymconIconPicker();
            });
        }

        const itemIconFalseSelect = properties.querySelector('#itemIconFalseSelect');
        if (itemIconFalseSelect) {
            itemIconFalseSelect.addEventListener('click', () => {
                if (!selected || selected.type !== 'item') return;
                iconPickerTarget = {floorId: state.activeFloor, itemId: selected.id, role: 'false'};
                openSymconIconPicker();
            });
        }

        const itemIconTrueSelect = properties.querySelector('#itemIconTrueSelect');
        if (itemIconTrueSelect) {
            itemIconTrueSelect.addEventListener('click', () => {
                if (!selected || selected.type !== 'item') return;
                iconPickerTarget = {floorId: state.activeFloor, itemId: selected.id, role: 'true'};
                openSymconIconPicker();
            });
        }

        const itemBoolIconsAuto = properties.querySelector('#itemBoolIconsAuto');
        if (itemBoolIconsAuto) {
            itemBoolIconsAuto.addEventListener('click', () => {
                if (!selected || selected.type !== 'item') return;
                const item = findEntity('item', selected.id);
                if (!item) return;
                item.boolIconsManual = false;
                item.iconFalse = '';
                item.iconTrue = '';
                item.iconFalseSvg = '';
                item.iconTrueSvg = '';
                pushHistory();
                markDirty();
                render();
                renderProperties();
            });
        }

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
                    pushHistory();
                    markDirty();
                    render();
                    return;
                }

                if (input.dataset.project === 'floorOrder') {
                    const floor = currentFloor();
                    const oldIndex = state.floors.findIndex(f => f.id === floor.id);
                    if (oldIndex < 0) return;

                    const requested = Math.max(1, Math.min(
                        state.floors.length,
                        Math.round(Number(input.value) || (oldIndex + 1))
                    ));
                    const newIndex = requested - 1;

                    if (newIndex !== oldIndex) {
                        pushHistory();
                        const [movedFloor] = state.floors.splice(oldIndex, 1);
                        state.floors.splice(newIndex, 0, movedFloor);
                        state.floors.forEach((f, index) => {
                            f.order = index + 1;
                        });
                        markDirty();
                        renderAll();
                    } else {
                        floor.order = requested;
                        renderProperties();
                    }
                    return;
                }
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
        updateLiveFloorSelectWidth();
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
                order: 1,
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

        state.floors.forEach((f, floorIndex) => {
            f.order = floorIndex + 1;
        });

        selected = null;
        wallStart = null;
        pushHistory();
        markDirty();
        renderAll();
        requestAnimationFrame(fit);
    });


    document.getElementById('copyFloorBtn')?.addEventListener('click', () => {
        const sourceFloor = currentFloor();
        if (!sourceFloor) return;

        const name = prompt('Name der kopierten Etage:', `${sourceFloor.name} Kopie`);
        if (name === null) return;

        rememberCurrentFloorView(false);

        // Komplette Etage kopieren, aber alle internen IDs neu erzeugen.
        // Öffnungen müssen anschließend auf die neu erzeugten Wand-IDs zeigen.
        const floor = structuredClone(sourceFloor);
        floor.id = uid('floor');
        floor.name = name.trim() || `${sourceFloor.name} Kopie`;

        const wallIdMap = new Map();
        for (const wall of floor.walls || []) {
            const oldID = wall.id;
            wall.id = uid('wall');
            wallIdMap.set(oldID, wall.id);
        }

        for (const opening of floor.openings || []) {
            opening.id = uid('opening');
            if (wallIdMap.has(opening.wallId)) {
                opening.wallId = wallIdMap.get(opening.wallId);
            }
        }

        for (const item of floor.items || []) {
            item.id = uid('item');
        }

        for (const furniture of floor.furniture || []) {
            furniture.id = uid('furniture');
        }

        for (const itemText of floor.texts || []) {
            itemText.id = uid('text');
        }

        for (const area of floor.areas || []) {
            area.id = uid('area');
        }

        for (const tracker of floor.trackers || []) {
            tracker.id = uid('tracker');
        }

        const sourceIndex = state.floors.findIndex(f => f.id === sourceFloor.id);
        state.floors.splice(sourceIndex >= 0 ? sourceIndex + 1 : state.floors.length, 0, floor);
        state.floors.forEach((f, index) => {
            f.order = index + 1;
        });
        state.activeFloor = floor.id;

        // Keine alte Zoom-/Pan-Ansicht übernehmen.
        // Die kopierte Etage wird anhand ihres Inhalts neu und reproduzierbar eingepasst.
        floorViews.delete(floor.id);
        floorHomeViews.delete(floor.id);

        selected = null;
        wallStart = null;
        preview = null;
        pushHistory();
        markDirty();
        renderAll();
        requestAnimationFrame(fit);
    });

    document.getElementById('addFloorBtn').addEventListener('click', () => {
        const name = prompt('Name der neuen Etage:', 'Obergeschoss');
        if (name === null) return;
        const floor = {
            id: uid('floor'),
            name: name.trim() || 'Etage',
            order: state.floors.length + 1,
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
        requestAnimationFrame(fit);
    });


    floorSelect.addEventListener('change', () => {
        switchFloorView(floorSelect.value);
    });

    function openShutterControl(opening, field = 'shutterVariableID', clientX = null, clientY = null) {
        if (!controlModal || !controlBody || !opening) return;

        const secondary = field === 'shutterSecondaryVariableID';
        const profile = secondary
            ? (opening._shutterSecondaryVariableProfile || {})
            : (opening._shutterVariableProfile || {});
        const associations = Array.isArray(profile.associations) ? profile.associations : [];
        const raw = Number(secondary
            ? opening._shutterSecondaryVariableRawValue
            : opening._shutterVariableRawValue);
        controlTitle.textContent = 'Rollladen / Jalousie';

        let html = '';

        if (associations.length) {
            html += '<div class="control-associations">';
            for (const association of associations) {
                const value = Number(association.value);
                const current = Number.isFinite(raw) && raw === value ? ' current' : '';
                html += `<button type="button" class="${current.trim()}" data-shutter-value="${value}">${escapeHtml(association.name || String(value))}</button>`;
            }
            html += '</div>';
        }

        const min = Number(profile.min);
        const max = Number(profile.max);
        const configuredStep = Number(profile.step);
        const hasRange = Number.isFinite(min) && Number.isFinite(max) && max > min;

        if (!associations.length && hasRange) {
            const step = Number.isFinite(configuredStep) && configuredStep > 0 ? configuredStep : 1;
            const current = Number.isFinite(raw) ? Math.max(min, Math.min(max, raw)) : min;
            const suffix = String(profile.suffix || '');
            html = `
                <div class="control-slider">
                    <div class="control-slider-value" data-shutter-slider-value>${escapeHtml(String(current))}${escapeHtml(suffix)}</div>
                    <div class="control-slider-row">
                        <button type="button" data-shutter-step="-1" title="Einen Schritt kleiner">−</button>
                        <input type="range" data-shutter-slider min="${min}" max="${max}" step="${step}" value="${current}">
                        <button type="button" data-shutter-step="1" title="Einen Schritt größer">+</button>
                    </div>
                    <div class="profile-hint">${escapeHtml(String(min))}${escapeHtml(suffix)} – ${escapeHtml(String(max))}${escapeHtml(suffix)} · Schritt ${escapeHtml(String(step))}${escapeHtml(suffix)}</div>
                </div>
            `;
        }

        if (!html) {
            html = '<div class="profile-hint">Für die Rollladenvariable sind keine bedienbaren Profilwerte hinterlegt.</div>';
        }

        controlBody.innerHTML = html;

        const send = value => requestAction('operateOpeningValue', JSON.stringify({
            floorId: state.activeFloor,
            openingId: opening.id,
            field,
            value
        }));

        controlBody.querySelectorAll('[data-shutter-value]').forEach(btn => {
            btn.addEventListener('click', () => {
                send(Number(btn.dataset.shutterValue));
                controlModal.classList.remove('open');
                controlModal.setAttribute('aria-hidden', 'true');
            });
        });

        const slider = controlBody.querySelector('[data-shutter-slider]');
        const sliderValue = controlBody.querySelector('[data-shutter-slider-value]');
        controlBody.querySelectorAll('[data-shutter-step]').forEach(btn => {
            btn.addEventListener('click', () => {
                if (!slider) return;
                const direction = Number(btn.dataset.shutterStep) || 0;
                const next = Math.max(Number(slider.min), Math.min(Number(slider.max), Number(slider.value) + direction * Number(slider.step || 1)));
                slider.value = String(next);
                if (sliderValue) sliderValue.textContent = `${slider.value}${String(profile.suffix || '')}`;
                send(Number(slider.value));
            });
        });
        if (slider) {
            const suffix = String(profile.suffix || '');
            slider.addEventListener('input', () => {
                if (sliderValue) sliderValue.textContent = `${slider.value}${suffix}`;
            });
            slider.addEventListener('change', () => send(Number(slider.value)));
        }

        controlModal.classList.add('open');
        controlModal.setAttribute('aria-hidden', 'false');

        const dialog = controlModal.querySelector('.control-modal');
        if (dialog) {
            requestAnimationFrame(() => {
                const margin = 8;
                const offset = 10;
                const rect = dialog.getBoundingClientRect();
                let left = Number(clientX) + offset;
                let top = Number(clientY) + offset;
                if (left + rect.width > window.innerWidth - margin) left = Number(clientX) - rect.width - offset;
                if (top + rect.height > window.innerHeight - margin) top = Number(clientY) - rect.height - offset;
                dialog.style.left = `${Math.max(margin, left)}px`;
                dialog.style.top = `${Math.max(margin, top)}px`;
                dialog.style.right = '';
                dialog.style.bottom = '';
            });
        }
    }

    svg.addEventListener('pointerdown', evt => {
        if (evt.button === 1) {
            drag = {mode: 'pan', x: evt.clientX, y: evt.clientY, panX, panY};
            svg.setPointerCapture(evt.pointerId);
            return;
        }

        if (evt.button !== 0) return;

        const directSliderTarget = evt.target.closest?.('[data-direct-slider]');
        if (state.mode === 'view' && directSliderTarget) {
            const item = currentFloor().items.find(i => i.id === directSliderTarget.dataset.directSlider);
            const cfg = directSliderConfig(item);
            if (item && cfg) {
                evt.preventDefault();
                evt.stopPropagation();

                const value = directSliderValueFromPointer(item, evt.clientX, evt.clientY);
                if (value !== null) {
                    item._rawValue = value;
                    drag = {
                        mode: 'direct-slider',
                        type: 'item',
                        id: item.id,
                        value
                    };
                    svg.setPointerCapture(evt.pointerId);
                    render();
                }
                return;
            }
        }

        const shutterControl = evt.target.closest?.('[data-shutter-control]');
        if (state.mode === 'view' && shutterControl) {
            const opening = (currentFloor().openings || []).find(o => o.id === shutterControl.dataset.shutterControl);
            if (opening) {
                const field = shutterControl.dataset.shutterField || 'shutterVariableID';
                if (Number(opening[field]) > 0) {
                    evt.preventDefault();
                    evt.stopPropagation();
                    openShutterControl(opening, field, evt.clientX, evt.clientY);
                    return;
                }
            }
        }

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
                    original: structuredClone(obj),
                    wallEnd: resizeType === 'wall' ? (resizeHandle.dataset.wallEnd || 'end') : null
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

                if (!item || item._canAction !== true) {
                    // Reine Istwerte/Messwerte besitzen keine Bedienaktion.
                    return;
                }

                const variableType = Number(item._variableType);
                if (variableType === 1 || variableType === 2) {
                    // Integer/Float mit Aktion: Profil-Assoziationen oder Zahlenbereich.
                    openItemControl(item, evt.clientX, evt.clientY);
                } else if (variableType === 0) {
                    // Boolean mit Aktion direkt umschalten.
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
                shutterValueMappingEnabled: false,
                shutterValueMap: {},
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
                size: 18,
                angle: 0,
                kind: 'generic', // nur noch für Migration älterer Projekte
                icon: 'fa-light fa-circle',
                iconManual: false,
                iconSvg: '',
                showName: false,
                showValue: false,
                showIcon: true,
                showDirectSlider: false,
                valueFrame: false,
                showState: false,
                statusColor: '#ffe66d',
                displayMode: 'icon',
                displayModeManual: false,
                labelSize: 12,
                valueSize: 12,
                labelPosition: 'below',
                valuePosition: 'below'
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

        if (drag.mode === 'direct-slider') {
            const item = findEntity('item', drag.id);
            if (!item) return;

            const value = directSliderValueFromPointer(item, evt.clientX, evt.clientY);
            if (value !== null) {
                item._rawValue = value;
                drag.value = value;
                render();
            }
            return;
        }

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

            // Auf -180..180 normalisieren und Möbel nur in ganzen Grad drehen.
            rotation = ((rotation + 180) % 360 + 360) % 360 - 180;
            obj.rotation = Math.round(rotation);
            render();
            return;
        }

        if (drag.mode === 'resize' && drag.original) {
            const obj = findEntity(drag.type, drag.id);
            if (!obj) return;

            if (drag.type === 'wall') {
                // Wand wie Möbel direkt per Griff bearbeiten:
                // Start- oder Endpunkt folgt der Maus und rastet auf Snap ein.
                if (drag.wallEnd === 'start') {
                    obj.x1 = snapValue(p.x);
                    obj.y1 = snapValue(p.y);
                } else {
                    obj.x2 = snapValue(p.x);
                    obj.y2 = snapValue(p.y);
                }
            } else if (drag.type === 'furniture') {
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

        if (drag.mode === 'direct-slider') {
            const item = findEntity('item', drag.id);
            if (item && drag.value !== null && drag.value !== undefined) {
                item._rawValue = drag.value;
                sendItemValue(item, drag.value);
                render();
            }

            try { svg.releasePointerCapture(evt.pointerId); } catch (_) {}
            drag = null;
            return;
        }

        if (drag.mode === 'move' || drag.mode === 'resize' || drag.mode === 'rotate') {
            pushHistory();
            markDirty();
        }

        try { svg.releasePointerCapture(evt.pointerId); } catch (_) {}
        drag = null;
    });

    svg.addEventListener('pointercancel', evt => {
        if (!drag) return;

        if (drag.mode === 'direct-slider') {
            const item = findEntity('item', drag.id);
            if (item && drag.value !== null && drag.value !== undefined) {
                item._rawValue = drag.value;
                sendItemValue(item, drag.value);
                render();
            }
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

    function inferSensorKindFromVariableNode(node) {
        if (!node) return '';

        const profile = node.profile || {};
        const suffix = String(profile.suffix || '').trim().toLowerCase();
        const profileName = String(node.profileName || '').toLowerCase();
        const path = String(node.path || '').toLowerCase();
        const valueText = String(node.valueText || '').toLowerCase();
        const haystack = `${profileName} ${path} ${valueText}`;

        // Feuchte zuerst prüfen: Prozentprofile werden sehr häufig dafür benutzt.
        // Der Name/Pfad verhindert, dass jeder beliebige Prozentwert als Feuchte gilt.
        if (
            /\b(feuchte|luftfeuchte|humidity|humid)\b/i.test(haystack) ||
            (suffix.includes('%') && /\b(feuchte|humidity|humid)\b/i.test(haystack))
        ) {
            return 'humidity';
        }

        if (
            suffix.includes('°c') ||
            suffix.includes('°f') ||
            /\b(temperatur|temperature|temp)\b/i.test(haystack)
        ) {
            return 'temperature';
        }

        return '';
    }

    const fallbackSymconIcons = [
        'fa-light fa-circle','fa-light fa-house','fa-light fa-lightbulb','fa-light fa-lamp','fa-light fa-plug',
        'fa-light fa-power-off','fa-light fa-toggle-on','fa-light fa-bolt','fa-light fa-gauge','fa-light fa-temperature-half',
        'fa-light fa-droplet','fa-light fa-droplet-percent','fa-light fa-fan','fa-light fa-radiator','fa-light fa-fire',
        'fa-light fa-snowflake','fa-light fa-sun','fa-light fa-cloud','fa-light fa-wind','fa-light fa-window-frame',
        'fa-light fa-door-open','fa-light fa-lock','fa-light fa-unlock','fa-light fa-blinds','fa-light fa-camera',
        'fa-light fa-bell','fa-light fa-person','fa-light fa-person-walking','fa-light fa-eye','fa-light fa-tv',
        'fa-light fa-speaker','fa-light fa-music','fa-light fa-washing-machine','fa-light fa-dishwasher','fa-light fa-water',
        'fa-light fa-car','fa-light fa-bicycle','fa-light fa-battery-half','fa-light fa-clock','fa-light fa-calendar',
        'fa-light fa-circle-info','fa-light fa-triangle-exclamation','fa-light fa-gear','fa-light fa-wifi','fa-light fa-network-wired'
    ];

    function availableSymconIcons() {
        const result = new Set(fallbackSymconIcons);
        const addDefinitions = (defs) => {
            if (!defs || typeof defs !== 'object') return;
            const prefixClass = {
                fal: 'fa-light', fab: 'fa-brands', fak: 'fa-kit',
                fas: 'fa-solid', far: 'fa-regular', fat: 'fa-thin', fad: 'fa-duotone'
            };
            for (const [prefix, icons] of Object.entries(defs)) {
                if (!icons || typeof icons !== 'object') continue;
                const style = prefixClass[prefix] || (prefix.startsWith('fa-') ? prefix : '');
                if (!style) continue;
                for (const name of Object.keys(icons)) {
                    if (/^[a-z0-9-]+$/i.test(name)) result.add(`${style} fa-${name}`);
                }
            }
        };

        try { addDefinitions(window.FontAwesome?.library?.definitions); } catch (e) {}
        try { addDefinitions(window.___FONT_AWESOME___?.styles); } catch (e) {}
        return Array.from(result).sort((a, b) => a.localeCompare(b));
    }

    function iconSearchText(iconClass) {
        return String(iconClass || '')
            .replace(/fa-(light|brands|kit|solid|regular|thin|duotone)\s*/g, '')
            .replace(/\bfa-/g, '')
            .replace(/-/g, ' ')
            .trim();
    }

    const curatedSymconIconGroups = [
        { name: 'Allgemein', icons: ['fa-light fa-circle','fa-light fa-house','fa-light fa-power-off','fa-light fa-toggle-on','fa-light fa-gear','fa-light fa-circle-info','fa-light fa-triangle-exclamation'] },
        { name: 'Licht & Energie', icons: ['fa-light fa-lightbulb','fa-light fa-lamp','fa-light fa-plug','fa-light fa-bolt','fa-light fa-gauge','fa-light fa-battery-half','fa-light fa-sun'] },
        { name: 'Klima & Wasser', icons: ['fa-light fa-temperature-half','fa-light fa-droplet','fa-light fa-droplet-percent','fa-light fa-fan','fa-light fa-radiator','fa-light fa-fire','fa-light fa-snowflake','fa-light fa-wind','fa-light fa-water'] },
        { name: 'Haus & Beschattung', icons: ['fa-light fa-window-frame','fa-light fa-door-open','fa-light fa-lock','fa-light fa-unlock','fa-light fa-blinds'] },
        { name: 'Sicherheit & Präsenz', icons: ['fa-light fa-camera','fa-light fa-bell','fa-light fa-person','fa-light fa-person-walking','fa-light fa-eye'] },
        { name: 'Medien & Geräte', icons: ['fa-light fa-tv','fa-light fa-speaker','fa-light fa-music','fa-light fa-washing-machine','fa-light fa-dishwasher'] },
        { name: 'Mobilität & Zeit', icons: ['fa-light fa-car','fa-light fa-bicycle','fa-light fa-clock','fa-light fa-calendar','fa-light fa-wifi','fa-light fa-network-wired'] }
    ];

    function iconButtonHtml(icon, current) {
        const cls = icon === current ? ' current' : '';
        const preview = fontAwesomeSvgHtml(icon) || `<i class="${escapeHtml(icon)}"></i>`;
        return `<button type="button" class="${cls.trim()}" data-symcon-icon="${escapeHtml(icon)}" title="${escapeHtml(iconSearchText(icon))}">${preview}</button>`;
    }

    function bindIconPickerButtons() {
        iconList.querySelectorAll('[data-symcon-icon]').forEach(button => {
            button.addEventListener('click', () => {
                const targetFloor = state.floors.find(f => f.id === iconPickerTarget?.floorId);
                const targetItem = targetFloor?.items?.find(i => i.id === iconPickerTarget?.itemId);
                if (!targetItem) return;
                const chosenIcon = String(button.dataset.symconIcon || 'fa-light fa-circle');
                const renderedSvg = button.querySelector('svg');
                const chosenSvg = renderedSvg ? renderedSvg.outerHTML : (fontAwesomeSvgHtml(chosenIcon) || '');
                if (iconPickerTarget?.role === 'false') {
                    targetItem.boolIconsManual = true;
                    targetItem.iconFalse = chosenIcon;
                    targetItem.iconFalseSvg = chosenSvg;
                } else if (iconPickerTarget?.role === 'true') {
                    targetItem.boolIconsManual = true;
                    targetItem.iconTrue = chosenIcon;
                    targetItem.iconTrueSvg = chosenSvg;
                } else {
                    targetItem.icon = chosenIcon;
                    targetItem.iconManual = true;
                    targetItem.iconSvg = chosenSvg;
                }
                iconModal.classList.remove('open');
                iconModal.setAttribute('aria-hidden', 'true');
                pushHistory();
                markDirty();
                render();
                renderProperties();
            });
        });
    }

    function renderSymconIconPicker(filter = '') {
        if (!iconList) return;
        const query = String(filter || '').trim().toLowerCase();
        const floor = state.floors.find(f => f.id === iconPickerTarget?.floorId);
        const item = floor?.items?.find(i => i.id === iconPickerTarget?.itemId);
        const current = normalizeSymconIcon(
            iconPickerTarget?.role === 'false' ? (item?.iconFalse || item?._iconFalse || 'fa-light fa-circle') :
            iconPickerTarget?.role === 'true' ? (item?.iconTrue || item?._iconTrue || 'fa-light fa-circle') :
            (item?.icon || 'fa-light fa-circle')
        );

        if (!query) {
            iconList.innerHTML = curatedSymconIconGroups.map(group => {
                const buttons = group.icons.map(icon => iconButtonHtml(icon, current)).join('');
                return `<div style="padding:4px 8px 10px"><div style="margin:4px 0 6px;color:var(--fp-muted);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em">${escapeHtml(group.name)}</div><div class="symcon-icon-grid">${buttons}</div></div>`;
            }).join('') +
            `<div class="profile-hint" style="padding:10px 14px">Weitere Symcon-Icons findest du über die Suche oben.</div>`;
            bindIconPickerButtons();
            refreshFontAwesome(iconList);
            return;
        }

        const icons = availableSymconIcons().filter(icon => iconSearchText(icon).includes(query));
        const shown = icons.slice(0, 120);
        iconList.innerHTML = `<div class="symcon-icon-grid">` + shown.map(icon => iconButtonHtml(icon, current)).join('') + `</div>` +
            (icons.length > shown.length
                ? `<div class="profile-hint" style="padding:8px 14px">${icons.length - shown.length} weitere Treffer – Suche bitte genauer.</div>`
                : '');
        bindIconPickerButtons();
        refreshFontAwesome(iconList);
    }

    function openSymconIconPicker() {
        if (!iconModal || !iconList || !iconSearch) return;
        iconSearch.value = '';
        iconModal.classList.add('open');
        iconModal.setAttribute('aria-hidden', 'false');
        // icons.js initialisiert die Font-Awesome-Bibliothek synchron bzw. sehr früh.
        // Ein kurzer Microtask erlaubt dem Register, vollständig verfügbar zu sein.
        Promise.resolve().then(() => renderSymconIconPicker(''));
        setTimeout(() => iconSearch.focus(), 0);
    }

    if (iconModal && iconSearch && iconList) {
        iconSearch.addEventListener('input', () => renderSymconIconPicker(iconSearch.value));
        document.getElementById('iconCloseBtn')?.addEventListener('click', () => {
            iconModal.classList.remove('open');
            iconModal.setAttribute('aria-hidden', 'true');
        });
        document.getElementById('iconAutoBtn')?.addEventListener('click', () => {
            const floor = state.floors.find(f => f.id === iconPickerTarget?.floorId);
            const item = floor?.items?.find(i => i.id === iconPickerTarget?.itemId);
            if (!item) return;
            if (iconPickerTarget?.role === 'false' || iconPickerTarget?.role === 'true') {
                item.boolIconsManual = false;
                item.iconFalse = '';
                item.iconTrue = '';
                item.iconFalseSvg = '';
                item.iconTrueSvg = '';
            } else {
                item.iconManual = false;
                item.iconSvg = '';
                item.icon = item._objectIcon || 'fa-light fa-circle';
            }
            iconModal.classList.remove('open');
            iconModal.setAttribute('aria-hidden', 'true');
            pushHistory();
            markDirty();
            render();
            renderProperties();
        });
        iconModal.addEventListener('click', evt => {
            if (evt.target === iconModal) {
                iconModal.classList.remove('open');
                iconModal.setAttribute('aria-hidden', 'true');
            }
        });
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
        const canActionKey = prefix ? `_${prefix}CanAction` : '_canAction';
        const objectIconKey = prefix ? `_${prefix}ObjectIcon` : '_objectIcon';
        const autoIconKey = prefix ? `_${prefix}AutoIcon` : '_autoIcon';
        const iconFalseKey = prefix ? `_${prefix}IconFalse` : '_iconFalse';
        const iconTrueKey = prefix ? `_${prefix}IconTrue` : '_iconTrue';
        const iconSourceKey = prefix ? `_${prefix}IconSource` : '_iconSource';

        entity[pathKey] = node?.path || '';
        entity[valueKey] = node?.valueText || '';
        entity[rawKey] = node?.rawValue ?? '';
        entity[typeKey] = Number.isFinite(Number(node?.variableType)) ? Number(node.variableType) : -1;
        entity[profileNameKey] = node?.profileName || '';
        entity[profileSummaryKey] = node?.profileSummary || '';
        entity[profileKey] = node?.profile || null;
        entity[canActionKey] = node?.canAction === true;
        entity[objectIconKey] = node?.objectIcon || '';
        entity[autoIconKey] = node?.autoIcon || '';
        entity[iconFalseKey] = node?.iconFalse || '';
        entity[iconTrueKey] = node?.iconTrue || '';
        entity[iconSourceKey] = node?.iconSource || '';
        if (entityType === 'item' && field === 'variableID' && entity[canActionKey] !== true) {
            entity.showDirectSlider = false;
        }

        // Das Icon wird beim Zuordnen der Hauptvariable automatisch aus
        // IPS_GetObject(...)[ObjectIcon] übernommen, solange der Benutzer
        // im Floorplaner noch kein eigenes Icon gewählt hat.
        if (entityType === 'item' && field === 'variableID') {
            if (Number(entity[typeKey]) === 0 && entity.boolIconsManual !== true) {
                entity.iconFalse = '';
                entity.iconTrue = '';
                entity.iconFalseSvg = '';
                entity.iconTrueSvg = '';
            }
            if (node && entity.iconManual !== true) {
                entity.icon = node.autoIcon || node.objectIcon || 'fa-light fa-circle';
                entity.iconSvg = '';
            }
            if (!node && entity.iconManual !== true) {
                entity.icon = 'fa-light fa-circle';
                entity.iconSvg = '';
            }
        }

        variableModal.classList.remove('open');
        variableModal.setAttribute('aria-hidden', 'true');
        pushHistory();
        markDirty();
        render();
        refreshPropertiesAfterStructuralChange();
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
        if (!controlModal || !controlBody || item?._canAction !== true) return;

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

        const min = Number(profile.min);
        const max = Number(profile.max);
        const configuredStep = Number(profile.step);
        const hasRange = Number.isFinite(min) && Number.isFinite(max) && max > min;

        if (!associations.length && hasRange) {
            const step = Number.isFinite(configuredStep) && configuredStep > 0 ? configuredStep : 1;
            const current = Number.isFinite(raw) ? Math.max(min, Math.min(max, raw)) : min;
            const prefix = String(profile.prefix || '');
            const suffix = String(profile.suffix || '');
            html = `
                <div class="control-slider">
                    <div class="control-slider-value" data-slider-value>${escapeHtml(prefix)}${escapeHtml(String(current))}${escapeHtml(suffix)}</div>
                    <div class="control-slider-row">
                        <button type="button" data-control-step="-1" title="Einen Schritt kleiner">−</button>
                        <input type="range" data-control-slider min="${min}" max="${max}" step="${step}" value="${current}">
                        <button type="button" data-control-step="1" title="Einen Schritt größer">+</button>
                    </div>
                    <div class="profile-hint">${escapeHtml(String(min))}${escapeHtml(suffix)} – ${escapeHtml(String(max))}${escapeHtml(suffix)} · Schritt ${escapeHtml(String(step))}${escapeHtml(suffix)}</div>
                </div>
            `;
        }

        if (!html) {
            html = '<div class="profile-hint">Für diese Integer-Variable sind im Profil weder bedienbare Werte noch ein Zahlenbereich hinterlegt.</div>';
        }

        controlBody.innerHTML = html;

        controlBody.querySelectorAll('[data-control-value]').forEach(btn => {
            btn.addEventListener('click', () => {
                sendItemValue(item, Number(btn.dataset.controlValue));
                controlModal.classList.remove('open');
                controlModal.setAttribute('aria-hidden', 'true');
            });
        });

        const slider = controlBody.querySelector('[data-control-slider]');
        const sliderValue = controlBody.querySelector('[data-slider-value]');
        controlBody.querySelectorAll('[data-control-step]').forEach(btn => {
            btn.addEventListener('click', () => {
                if (!slider) return;
                const direction = Number(btn.dataset.controlStep) || 0;
                const next = Math.max(Number(slider.min), Math.min(Number(slider.max), Number(slider.value) + direction * Number(slider.step || 1)));
                slider.value = String(next);
                const prefix = String(profile.prefix || '');
                const suffix = String(profile.suffix || '');
                if (sliderValue) sliderValue.textContent = `${prefix}${slider.value}${suffix}`;
                sendItemValue(item, Number(slider.value));
            });
        });
        if (slider) {
            const prefix = String(profile.prefix || '');
            const suffix = String(profile.suffix || '');
            slider.addEventListener('input', () => {
                if (sliderValue) sliderValue.textContent = `${prefix}${slider.value}${suffix}`;
            });
            slider.addEventListener('change', () => {
                sendItemValue(item, Number(slider.value));
            });
        }

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
                            const manualIcon = item.iconManual === true;
                            Object.assign(item, meta);
                            if (!manualIcon && meta._autoIcon !== undefined) {
                                item.icon = meta._autoIcon || meta._objectIcon || 'fa-light fa-circle';
                                item.iconSvg = '';
                            }
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

    let resizeFitFrame = 0;
    const resizeObserver = new ResizeObserver(() => {
        // Die Projektgröße bleibt unverändert. Nur die Ansicht wird an die
        // tatsächlich verfügbare Tile-/Fenstergröße neu angepasst.
        cancelAnimationFrame(resizeFitFrame);
        resizeFitFrame = requestAnimationFrame(fit);
    });
    resizeObserver.observe(svg);

    restoreLastViewFloor();
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


</body>
</html>
HTML;

        return str_replace(
            ['__INITIAL_PROJECT__', '__INSTANCE_ID__'],
            [$initial, (string) $this->InstanceID],
            $html
        );
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

                /*
                 * Die Variablenzuordnung kann direkt im HTML-Editor geändert werden.
                 * ApplyChanges() läuft dabei nicht erneut. Deshalb müssen neu
                 * zugeordnete Variablen unmittelbar nach dem Speichern für VM_UPDATE
                 * registriert werden, damit externe Änderungen sofort im Floorplan erscheinen.
                 */
                $this->RegisterRuntimeVariableMessages();

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

            case 'operateOpeningValue':
                if (!is_string($Value)) {
                    throw new InvalidArgumentException('Ungültiger Öffnungs-Bedienwert.');
                }
                $request = json_decode($Value, true);
                if (!is_array($request)) {
                    throw new InvalidArgumentException('Ungültiger Öffnungs-Bedienwert.');
                }
                $this->OperateOpeningValue(
                    (string) ($request['floorId'] ?? ''),
                    (string) ($request['openingId'] ?? ''),
                    (string) ($request['field'] ?? ''),
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

    public function RestoreFloorplanBackup(string $Base64Data): void
    {
        $json = base64_decode($Base64Data, true);
        if ($json === false || trim($json) === '') {
            throw new InvalidArgumentException('Die ausgewählte Sicherungsdatei ist ungültig.');
        }

        $this->SetFloorplanJSON($json);
        $this->RegisterRuntimeVariableMessages();
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
                'objectIcon'     => (string) ($object['ObjectIcon'] ?? ''),
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
                    $node['canAction'] = (bool) ($meta['_canAction'] ?? false);
                    $node['autoIcon'] = (string) ($meta['_autoIcon'] ?? '');
                    $node['iconFalse'] = (string) ($meta['_iconFalse'] ?? '');
                    $node['iconTrue'] = (string) ($meta['_iconTrue'] ?? '');
                    $node['iconSource'] = (string) ($meta['_iconSource'] ?? '');
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
                    'icon'         => (string) ($p['Icon'] ?? ''),
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

        $variableInfo = IPS_GetVariable($VariableID);
        $actionID = (int) (($variableInfo['VariableCustomAction'] ?? 0) ?: ($variableInfo['VariableAction'] ?? 0));

        $objectInfo = IPS_GetObject($VariableID);

        // Effektive Variablendarstellung ermitteln.
        $presentation = null;
        $effectivePresentation = [];

        try {
            if (function_exists('IPS_GetVariablePresentation')) {
                $candidate = IPS_GetVariablePresentation($VariableID);
                if (is_array($candidate)) {
                    $effectivePresentation = $candidate;
                }
            }

            if ($effectivePresentation === []) {
                $systemPresentation = $variable['VariablePresentation'] ?? [];
                $customPresentation = $variable['VariableCustomPresentation'] ?? [];

                if (is_array($systemPresentation)) {
                    $effectivePresentation = $systemPresentation;
                }
                if (is_array($customPresentation) && $customPresentation !== []) {
                    $effectivePresentation = array_replace($effectivePresentation, $customPresentation);
                }
            }

            if ($effectivePresentation !== []) {
                $options = [];
                $optionsRaw = $effectivePresentation['OPTIONS'] ?? [];

                if (is_string($optionsRaw) && $optionsRaw !== '') {
                    $decodedOptions = json_decode($optionsRaw, true);
                    if (is_array($decodedOptions)) {
                        $optionsRaw = $decodedOptions;
                    }
                }

                if (is_array($optionsRaw)) {
                    foreach ($optionsRaw as $option) {
                        if (!is_array($option)) {
                            continue;
                        }

                        $iconValue = (string) (
                            $option['IconValue']
                            ?? $option['ICON_VALUE']
                            ?? $option['Icon']
                            ?? $option['ICON']
                            ?? ''
                        );

                        $iconActive = array_key_exists('IconActive', $option)
                            ? (bool) $option['IconActive']
                            : (array_key_exists('ICON_ACTIVE', $option)
                                ? (bool) $option['ICON_ACTIVE']
                                : ($iconValue !== ''));

                        $options[] = [
                            'value'      => $option['Value'] ?? $option['VALUE'] ?? null,
                            'name'       => (string) ($option['Caption'] ?? $option['CAPTION'] ?? $option['Name'] ?? ''),
                            'icon'       => $iconValue,
                            'iconActive' => $iconActive,
                            'color'      => (int) ($option['Color'] ?? $option['ColorValue'] ?? -1)
                        ];
                    }
                }

                $findPresentationKey = static function (array $data, array $keys) use (&$findPresentationKey): mixed {
                    foreach ($keys as $key) {
                        if (array_key_exists($key, $data)) {
                            return $data[$key];
                        }
                    }
                    foreach ($data as $value) {
                        if (is_array($value)) {
                            $found = $findPresentationKey($value, $keys);
                            if ($found !== null) {
                                return $found;
                            }
                        }
                    }
                    return null;
                };

                $presentation = [
                    'icon'      => (string) ($effectivePresentation['ICON'] ?? $effectivePresentation['Icon'] ?? ''),
                    'iconFalse' => (string) ($findPresentationKey($effectivePresentation, ['ICON_FALSE', 'IconFalse', 'iconFalse']) ?? ''),
                    'iconTrue'  => (string) ($findPresentationKey($effectivePresentation, ['ICON_TRUE', 'IconTrue', 'iconTrue']) ?? ''),
                    'useFalse'  => (bool) ($findPresentationKey($effectivePresentation, ['USE_ICON_FALSE', 'UseIconFalse', 'useIconFalse']) ?? true),
                    'options'   => $options
                ];
            }
        } catch (Throwable $e) {
            $this->SendDebug('VariablePresentation', $VariableID . ': ' . $e->getMessage(), 0);
        }

        $autoIcon = '';
        $iconFalse = '';
        $iconTrue = '';
        $iconSource = '';

        $matchValue = static function (array $entries, mixed $value, int $type): ?array {
            if ($entries === []) {
                return null;
            }

            if ($type === 0) {
                $target = (bool) $value;
                foreach ($entries as $entry) {
                    if ((bool) ($entry['value'] ?? false) === $target) {
                        return $entry;
                    }
                }
                return null;
            }

            if ($type === 3) {
                foreach ($entries as $entry) {
                    if ((string) ($entry['value'] ?? '') === (string) $value) {
                        return $entry;
                    }
                }
                return null;
            }

            if (is_numeric($value)) {
                $numeric = [];
                foreach ($entries as $entry) {
                    if (isset($entry['value']) && is_numeric($entry['value'])) {
                        $numeric[] = $entry;
                    }
                }
                usort($numeric, static fn(array $a, array $b): int => (float) $a['value'] <=> (float) $b['value']);

                $match = null;
                foreach ($numeric as $entry) {
                    if ((float) $value >= (float) $entry['value']) {
                        $match = $entry;
                    } else {
                        break;
                    }
                }
                return $match ?? ($numeric[0] ?? null);
            }

            return null;
        };

        if ($variableType === 0) {
            if (is_array($presentation)) {
                $pFalse = trim((string) ($presentation['iconFalse'] ?? ''));
                $pTrue = trim((string) ($presentation['iconTrue'] ?? ''));
                if ($pTrue !== '' || $pFalse !== '') {
                    $iconTrue = $pTrue;
                    // Symcon-Schalter: Wenn USE_ICON_FALSE deaktiviert ist,
                    // gilt ICON_TRUE ausdrücklich auch für false.
                    $iconFalse = (($presentation['useFalse'] ?? true) ? $pFalse : $pTrue);
                    $iconSource = 'presentation-switch';
                }

                if ($iconTrue === '' || $iconFalse === '') {
                    $entries = $presentation['options'] ?? [];
                    $falseMatch = $matchValue($entries, false, 0);
                    $trueMatch = $matchValue($entries, true, 0);
                    if ($iconFalse === '' && is_array($falseMatch) && ($falseMatch['iconActive'] ?? true)) {
                        $iconFalse = trim((string) ($falseMatch['icon'] ?? ''));
                    }
                    if ($iconTrue === '' && is_array($trueMatch) && ($trueMatch['iconActive'] ?? true)) {
                        $iconTrue = trim((string) ($trueMatch['icon'] ?? ''));
                    }
                    if (($iconFalse !== '' || $iconTrue !== '') && $iconSource === '') {
                        $iconSource = 'presentation-options';
                    }
                }
            }

            if (is_array($profile) && ($iconFalse === '' || $iconTrue === '')) {
                $entries = $profile['associations'] ?? [];
                $falseMatch = $matchValue($entries, false, 0);
                $trueMatch = $matchValue($entries, true, 0);
                if ($iconFalse === '' && is_array($falseMatch)) {
                    $iconFalse = trim((string) ($falseMatch['icon'] ?? ''));
                }
                if ($iconTrue === '' && is_array($trueMatch)) {
                    $iconTrue = trim((string) ($trueMatch['icon'] ?? ''));
                }
                if (($iconFalse !== '' || $iconTrue !== '') && $iconSource === '') {
                    $iconSource = 'legacy-profile';
                }
            }

            $fallbackStateIcon = '';
            if (is_array($presentation)) {
                $fallbackStateIcon = trim((string) ($presentation['icon'] ?? ''));
            }
            if ($fallbackStateIcon === '' && is_array($profile)) {
                $fallbackStateIcon = trim((string) ($profile['icon'] ?? ''));
            }
            if ($fallbackStateIcon === '') {
                $fallbackStateIcon = trim((string) ($objectInfo['ObjectIcon'] ?? ''));
            }
            if ($iconFalse === '') $iconFalse = $fallbackStateIcon;
            if ($iconTrue === '') $iconTrue = $fallbackStateIcon;
        }

        if (is_array($presentation)) {
            $presentationMatch = $matchValue($presentation['options'] ?? [], $rawValue, $variableType);
            if (
                is_array($presentationMatch)
                && ($presentationMatch['iconActive'] ?? true)
                && trim((string) ($presentationMatch['icon'] ?? '')) !== ''
            ) {
                $autoIcon = trim((string) $presentationMatch['icon']);
            }

            if ($autoIcon === '' && trim((string) ($presentation['icon'] ?? '')) !== '') {
                $autoIcon = trim((string) $presentation['icon']);
            }
        }

        if ($autoIcon === '' && is_array($profile)) {
            $profileMatch = $matchValue($profile['associations'] ?? [], $rawValue, $variableType);
            if (is_array($profileMatch) && trim((string) ($profileMatch['icon'] ?? '')) !== '') {
                $autoIcon = trim((string) $profileMatch['icon']);
            }

            if ($autoIcon === '' && trim((string) ($profile['icon'] ?? '')) !== '') {
                $autoIcon = trim((string) $profile['icon']);
            }
        }

        if ($autoIcon === '') {
            $autoIcon = trim((string) ($objectInfo['ObjectIcon'] ?? ''));
        }

        return [
            '_variableType'   => $variableType,
            '_objectIcon'     => (string) ($objectInfo['ObjectIcon'] ?? ''),
            '_variablePath'   => $this->GetObjectPath($VariableID),
            '_rawValue'       => $rawValue,
            '_valueText'      => $valueText,
            '_profileName'    => $profileName,
            '_profileSummary' => $profileSummary,
            '_profile'        => $profile,
            '_presentation'   => $presentation,
            '_autoIcon'       => $autoIcon,
            '_iconFalse'      => $iconFalse,
            '_iconTrue'       => $iconTrue,
            '_iconSource'     => $iconSource,
            '_canAction'      => $actionID > 0
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

    private function OperateOpeningValue(string $FloorID, string $OpeningID, string $Field, mixed $Value): void
    {
        if (!in_array($Field, ['variableID', 'secondaryVariableID', 'shutterVariableID', 'shutterSecondaryVariableID'], true)) {
            return;
        }

        $project = $this->GetProject();

        foreach (($project['floors'] ?? []) as $floor) {
            if ((string) ($floor['id'] ?? '') !== $FloorID) {
                continue;
            }

            foreach (($floor['openings'] ?? []) as $opening) {
                if ((string) ($opening['id'] ?? '') !== $OpeningID) {
                    continue;
                }

                $variableID = (int) ($opening[$Field] ?? 0);
                if ($variableID <= 0 || !IPS_VariableExists($variableID)) {
                    return;
                }

                $variable = IPS_GetVariable($variableID);
                $variableType = (int) ($variable['VariableType'] ?? -1);

                if ($variableType === 1) {
                    $targetValue = (int) round((float) $Value);
                } elseif ($variableType === 2) {
                    $targetValue = (float) $Value;
                } elseif ($variableType === 0) {
                    $targetValue = (bool) $Value;
                } else {
                    return;
                }

                $this->DispatchVariableAction($variableID, $targetValue);
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
