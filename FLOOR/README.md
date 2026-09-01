# 🏠 Floorplaner für IP-Symcon

**Floorplaner** ist ein grafischer Grundriss-Editor für IP-Symcon.

Grundrisse können direkt in der Visualisierung erstellt und bearbeitet werden. Geräte und IP-Symcon-Variablen lassen sich anschließend direkt im Grundriss anzeigen und – sofern die Variable eine Aktion unterstützt – bedienen.

Die Bedienung orientiert sich an [Easy Floorplan](https://github.com/nicosandller/easy-floorplan), wurde jedoch speziell für die Verwendung innerhalb von IP-Symcon umgesetzt.

---

## ✨ Funktionen

### 🏗️ Grundriss erstellen

Der Grundriss wird direkt im integrierten Editor erstellt.

Unterstützt werden unter anderem:

- Wände zeichnen
- Türen und Fenster einsetzen
- Texte platzieren
- Möbel platzieren
- Geräte platzieren
- Elemente verschieben
- Elemente vergrößern und verkleinern
- Möbel drehen
- Raster und Einrasten
- mehrere Etagen
- komplette Etagen löschen

Die Zeichenfläche besitzt keine fest vorgegebene Projektgröße.

---

## 🛋️ Möbel

Für die Einrichtung stehen verschiedene Möbel und Objekte zur Verfügung, unter anderem:

- Sofa
- Sessel
- Bett
- Tisch
- runder Tisch
- Stuhl
- Schreibtisch
- Schrank
- Teppich
- Fernseher
- Waschmaschine
- Trockner
- Geschirrspüler
- Kühlschrank
- Herd
- Spüle
- Badewanne
- Toilette
- Waschtisch
- Pflanze
- Aquarium
- Whirlpool
- Klavier
- Treppe
- Boiler
- Lüftungsgerät

Möbel können mit der Maus:

- verschoben
- skaliert
- gedreht

werden.

Der Name eines Möbelstücks kann bei Bedarf eingeblendet werden.

---

## 💡 Geräte und IP-Symcon-Variablen

Geräte werden mit einer IP-Symcon-Variable verbunden.

Die Variable wird direkt über den IP-Symcon-Objektbaum ausgewählt.

Je nach Gerätetyp wird automatisch das passende Symbol verwendet.

Beispiele:

- 💡 Licht
- Schalter
- Steckdose
- Fenster
- Tür
- Rollladen
- Heizung
- Thermostat
- Ventilator
- Kamera
- Fernseher
- Waschmaschine
- Geschirrspüler
- Boiler
- Elektroauto
- Saugroboter
- Schloss

Für die Symbole werden Material Design Icons (MDI) verwendet.

Eine zusätzliche manuelle Symbolauswahl ist nicht notwendig – der ausgewählte Gerätetyp bestimmt das Symbol.

---

## 🌡️ Temperatur und Luftfeuchtigkeit

Messwerte können direkt im Grundriss dargestellt werden.

Beispiel:

    21.6 °C

oder:

    48 %

Temperatur- und Feuchtigkeitsvariablen werden soweit möglich automatisch erkannt.

Für Geräte stehen folgende Darstellungsarten zur Verfügung:

- **Symbol**
- **Wert**
- **Symbol + Wert**

Für Temperatur und Luftfeuchtigkeit wird standardmäßig die reine Wertanzeige verwendet.

Dadurch muss beispielsweise für einen Temperatursensor nicht zusätzlich ein Thermometer-Symbol im Grundriss angezeigt werden.

---

## 🎛️ Geräte bedienen

Unterstützte IP-Symcon-Variablen können direkt aus dem Grundriss bedient werden.

Bei einfachen Boolean-Variablen kann beispielsweise ein Licht direkt ein- oder ausgeschaltet werden.

Bei Integer-Variablen mit einem passenden Variablenprofil werden die vorhandenen Profilwerte als kompakte Schaltflächen angeboten.

Damit können beispielsweise Rollläden oder andere Geräte mit mehreren Zuständen bedient werden.

Die eigentliche Aktion wird über die in IP-Symcon hinterlegte Variablenaktion bzw. die zugehörige Instanz ausgeführt.

---

## 🔄 Live-Aktualisierung

Ändert sich eine verwendete IP-Symcon-Variable, wird die Anzeige im Grundriss aktualisiert.

Dabei wird nicht der komplette Floorplan neu geladen.

Nur die betroffene Variable bzw. deren Darstellung wird aktualisiert.

Dadurch bleibt beispielsweise die aktuell ausgewählte Etage erhalten.

---

## 🏢 Mehrere Etagen

Ein Projekt kann mehrere Etagen enthalten.

Beispielsweise:

- UG
- EG
- OG
- Dachgeschoss

Im Live-Modus erscheint bei mehreren Etagen eine kompakte Etagenauswahl am unteren Rand.

Jede Etage besitzt ihre eigene Ansicht.

---

## 🔍 Ansicht und Navigation

Der Grundriss kann unabhängig von seiner tatsächlichen Größe an die verfügbare Visualisierungsfläche angepasst werden.

### Einpassen

**Einpassen** skaliert die aktuelle Etage proportional auf die verfügbare Fläche.

Das Seitenverhältnis bleibt erhalten.

### Verschieben

Mit dem Werkzeug **Verschieben** kann der komplette Grundriss mit der Maus bewegt werden.

Alternativ kann die Ansicht mit der mittleren Maustaste verschoben werden.

### Zoom

Über die Schaltflächen:

    −   +

kann hinein- und herausgezoomt werden.

Bewusst nicht verwendet wird das Mausrad zum Zoomen, damit die normale Bedienung der Visualisierung nicht gestört wird.

### Start

Mit **Start** kann jederzeit zur ursprünglichen Startansicht der aktuellen Etage zurückgekehrt werden.

---

## ✏️ Editor und Live-Modus

Der Floorplaner unterscheidet zwischen:

### Editor

Im Editor wird der Grundriss erstellt und verändert.

Hier stehen Zeichenwerkzeuge, Raster, Geräte, Möbel und die jeweiligen Eigenschaften zur Verfügung.

### Live-Modus

Im Live-Modus wird der fertige Grundriss angezeigt und bedient.

Das Raster wird hier nicht dargestellt.

Am unteren Rand befindet sich lediglich ein kleiner Stift:

    ✎

Damit kann jederzeit wieder in den Editor gewechselt werden.

---

## 💾 Automatisches Speichern

Änderungen am Grundriss werden automatisch gespeichert.

Ein zusätzlicher **Speichern-Button ist deshalb nicht erforderlich**.

Nach einer Änderung wartet der Floorplaner kurz und speichert anschließend automatisch den aktuellen Projektstand.

---

## 🎨 IP-Symcon Theme

Der Floorplaner berücksichtigt das aktuelle Erscheinungsbild von IP-Symcon.

Unterstützt werden:

- Dark Mode
- Light Mode

Der Hintergrund des Floorplaners bleibt transparent, sodass sich die Darstellung möglichst natürlich in die IP-Symcon-Visualisierung integriert.

---

## 🧩 Mehrere Floorplaner-Instanzen

Es können mehrere Floorplaner-Instanzen innerhalb einer IP-Symcon-Installation verwendet werden.

Damit können beispielsweise unterschiedliche Gebäude oder Bereiche getrennt dargestellt werden.

---

## 📦 Voraussetzungen

- IP-Symcon 8.2 oder neuer
- HTML-SDK
- moderner Browser mit SVG- und JavaScript-Unterstützung

---

## 🖼️ Material Design Icons

Für Gerätesymbole verwendet Floorplaner **Material Design Icons (MDI)**.

Die benötigten SVG-Pfade sind lokal in das Modul integriert.

Dadurch ist für die Darstellung der Gerätesymbole keine externe Icon-Bibliothek und keine Internetverbindung erforderlich.

---

## 🙏 Easy Floorplan

Die Idee und Teile des Bedienkonzepts orientieren sich an:

**Easy Floorplan**  
https://github.com/nicosandller/easy-floorplan

Easy Floorplan ist ein interaktiver Floorplan-Editor für Home Assistant und steht unter der MIT-Lizenz.

Floorplaner übernimmt dieses Konzept nicht unverändert, sondern passt die Bedienung und insbesondere die Geräte-/Variablenanbindung an IP-Symcon an.

---

## 📄 Lizenz

Für dieses Modul gilt die im Repository hinterlegte Lizenz.

Für übernommene bzw. verwendete Bestandteile aus Drittprojekten gelten zusätzlich deren jeweilige Lizenzbedingungen.

Easy Floorplan:

Copyright (c) nicosandller  
MIT License

Material Design Icons:

Die verwendeten Icons unterliegen den Lizenzbedingungen des Material Design Icons Projekts.

---

## ⚠️ Hinweis

Floorplaner befindet sich in Entwicklung.

Vor größeren Änderungen empfiehlt es sich, eine Sicherung der IP-Symcon-Konfiguration bzw. des Projekts anzulegen.

Fehler und Verbesserungsvorschläge können über das Repository gemeldet werden.