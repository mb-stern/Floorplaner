# 🏠 Floorplaner für IP-Symcon

Ein interaktiver Grundriss-Editor für **IP-Symcon**. Räume, Wände, Türen, Fenster, Möbel und Geräte lassen sich direkt im Browser platzieren und anschließend in der Visualisierung bedienen.

Der Floorplaner arbeitet vollständig innerhalb von IP-Symcon und benötigt keine externe Cloud.

## ✨ Funktionen

- Grundrisse direkt im Browser zeichnen und bearbeiten
- Mehrere Etagen mit eigener Ansicht
- Wände, Türen und Fenster frei platzieren
- Möbel und Geräte per Drag & Drop positionieren und skalieren
- Rasterfunktion und Zoom im Editor
- Live-Ansicht ohne Editor-Raster
- Unterstützung des hellen und dunklen IP-Symcon-Themes
- Geräte direkt aus dem Grundriss bedienen

## 🎛️ IP-Symcon Variablen

Geräte werden mit einer IP-Symcon-Variable verknüpft. Je nach Variablentyp werden passende Bedienmöglichkeiten angeboten.

Unterstützt werden **Boolean-, Integer- und Float-Variablen**. Variablen ohne Aktion dienen nur zur Anzeige und erhalten keine unnötigen Bedienelemente.

## 🖼️ Icons

Das Gerätesymbol wird soweit möglich automatisch aus der ausgewählten IP-Symcon-Variable übernommen. Unterstützt werden sowohl **Legacy-Profile** als auch aktuelle IP-Symcon-Variablendarstellungen.

Bei Boolean-Variablen können getrennte Icons für **AUS** und **EIN** verwendet werden. Die automatisch übernommenen Icons lassen sich jederzeit über die umfangreiche IP-Symcon-Iconauswahl manuell ändern.

Mit **Variableneinstellungen aktualisieren** können die aktuell in IP-Symcon hinterlegten Icons und Darstellungsinformationen erneut eingelesen werden.

## 🎨 Statusfarben

Boolean-Variablen können ihren aktiven Zustand farbig darstellen. Bei aktuellen IP-Symcon-Variablendarstellungen werden `GLOW_COLOR` und `GLOW_INTENSITY` übernommen.

Die Symcon-Farbe wird als **Statusfarbe EIN** verwendet. Im ausgeschalteten Zustand wird kein entsprechender Farb-Glow angezeigt. Die Farbe kann im Floorplaner weiterhin manuell geändert werden.

Bei Integer- und Float-Variablen kann die Intensität des Statusrings dem aktuellen Wert zwischen Profil-Minimum und Profil-Maximum folgen.

## 🚪 Türen und Fenster

Türen und Fenster werden direkt einer Wand zugeordnet und bewegen sich zusammen mit dieser.

Fensterkontakte können mit einer Boolean-Variable verknüpft werden. Ein geöffnetes Fenster wird nach innen versetzt und farblich hervorgehoben.

Rollläden und Jalousien können direkt am Fenster mit einer eigenen Variable verknüpft und bedient werden.

## 🛋️ Möbel

Möbel dienen zur Gestaltung des Grundrisses und können frei platziert, verschoben und skaliert werden.

Die Beschriftung kann optional ein- oder ausgeblendet werden.

## 🕹️ Bedienung

Ein Klick auf ein Gerät öffnet die passende Bedienung direkt am Grundriss. Boolean-Werte können geschaltet, Integer-/Float-Werte über die jeweilige Profil- oder Sliderdarstellung bedient werden.

Das Geräte-Popup wird durch einen Klick oder Tipp außerhalb wieder geschlossen.

## 🏢 Etagen

Es können beliebig viele Etagen angelegt werden. Jede Etage besitzt einen eigenen Grundriss und eigene Geräte.

In der Live-Ansicht kann über die Etagenwahl schnell zwischen den Grundrissen gewechselt werden.

## 📦 Installation

Das Modul kann über den IP-Symcon Module Store bzw. die Modulverwaltung installiert werden.

Anschließend eine **Floorplaner-Instanz** anlegen, den Editor öffnen und den Grundriss erstellen.

**Voraussetzung:** IP-Symcon ab Version 8.2.

## 📝 Änderungen

### 1.4

### 1.4

- Icons werden nun sowohl aus den **neuen IP-Symcon-Variablendarstellungen** als auch aus **Legacy-Profilen** automatisch übernommen.
- Bei Boolean-Variablen können **zwei unterschiedliche Icons für AUS und EIN** aus der Variablendarstellung übernommen und verwendet werden.
- Die übernommenen Icons können weiterhin manuell geändert oder über **Variableneinstellungen aktualisieren** neu aus IP-Symcon eingelesen werden.

### 1.3

### 1.4

- Icons werden nun sowohl aus neuen IP-Symcon-Variablendarstellungen als auch aus Legacy-Profilen automatisch übernommen. Bei Boolean-Variablen sind unterschiedliche Icons für AUS und EIN möglich.
- Statusfarben werden ebenfalls aus IP-Symcon übernommen: bei neuen Darstellungen aus der konfigurierten Glow-Farbe und bei Legacy-Profilen aus der Profilfarbe für EIN.
- Über Variableneinstellungen aktualisieren können die aktuellen Darstellungsinformationen erneut aus der zugeordneten Variable eingelesen werden.

### 1.2

- Geräte können mit einem Rahmen um den Istwert dargestellt werden.
- Variablen ohne Aktion werden nur angezeigt und bieten keine Steuerung.
- Fensterdarstellung und helles IP-Symcon-Theme wurden verbessert.

### 1.1

- Verbesserte Gerätebedienung sowie Unterstützung von Rollläden und Jalousien.
- Optimierungen für Editor, Möbel, Skalierung und verschiedene Displaygrößen.
- Verbesserte Darstellung im hellen und dunklen IP-Symcon-Theme.

### 1.0

- Erste Beta-Version mit Grundriss-Editor, Etagen, Möbeln und IP-Symcon-Geräten.
- Direkte Bedienung von Variablen aus der Live-Ansicht.
- Unterstützung für Wände, Türen und Fenster.