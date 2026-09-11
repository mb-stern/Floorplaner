# 🏠 Floorplan für IP-Symcon

Ein interaktiver Grundriss-Editor für **IP-Symcon**. Räume, Wände, Türen, Fenster, Möbel, Formen und Geräte lassen sich direkt im Browser platzieren und anschließend in der Visualisierung anzeigen und bedienen.

Floorplan arbeitet vollständig innerhalb von IP-Symcon und benötigt keine externe Cloud.

## ✨ Funktionen

- Grundrisse direkt im Browser zeichnen und bearbeiten
- Mehrere Etagen mit eigener Ansicht
- Wände, Türen und Fenster frei platzieren
- Möbel, Formen, Texte und Geräte frei positionieren und skalieren
- Möbel und Formen alphabetisch sortiert auswählbar
- Formen zur Kennzeichnung von Grundflächen
- Rasterfunktion und Zoom im Editor
- Grundriss automatisch an die verfügbare Fläche anpassen
- Live-Ansicht ohne Editor-Raster
- Etagenwechsel direkt in der Live-Ansicht
- Unterstützung des hellen und dunklen IP-Symcon-Themes
- Geräte direkt aus dem Grundriss bedienen
- Unterstützung von Kamerastreams
- Variablen und Geräte über den IP-Symcon-Objektbaum auswählen

## 🎛️ IP-Symcon Variablen

Geräte werden mit einer IP-Symcon-Variable verknüpft. Je nach Variablentyp und Variablendarstellung werden passende Anzeige- und Bedienmöglichkeiten angeboten.

Unterstützt werden **Boolean-, Integer- und Float-Variablen**.

Variablen ohne Aktion dienen ausschließlich zur Anzeige und erhalten keine unnötigen Bedienelemente.

Sowohl klassische Legacy-Profile als auch die aktuellen IP-Symcon-Variablendarstellungen werden berücksichtigt.

## 🖼️ Icons

Das Gerätesymbol wird soweit möglich automatisch aus der ausgewählten IP-Symcon-Variable übernommen.

Unterstützt werden sowohl **Legacy-Profile** als auch die aktuellen **IP-Symcon-Variablendarstellungen**.

Bei Boolean-Variablen können getrennte Icons für **AUS** und **EIN** verwendet werden.

Zusätzlich steht die umfangreiche IP-Symcon-Iconauswahl mit mehreren Tausend Icons zur Verfügung. Automatisch übernommene Icons können dadurch jederzeit manuell geändert werden.

Mit **Variableneinstellungen aktualisieren** können die aktuell in IP-Symcon hinterlegten Icons und Darstellungsinformationen erneut eingelesen werden.

## 🎨 Statusfarben

Boolean-Variablen können ihren aktiven Zustand farbig darstellen.

Bei aktuellen IP-Symcon-Variablendarstellungen werden unter anderem `GLOW_COLOR` und `GLOW_INTENSITY` berücksichtigt.

Die Symcon-Farbe wird als Statusfarbe für den aktiven Zustand verwendet. Im ausgeschalteten Zustand wird kein entsprechender Farb-Glow angezeigt.

Die Statusfarbe kann bei Bedarf im Floorplan manuell festgelegt werden.

Auch **Integer-Variablen** können Statusfarben aus ihrer IP-Symcon-Darstellung bzw. ihrem Profil übernehmen. Dadurch kann beispielsweise bereits die Farbe des Statusrings den aktuellen Zustand wiedergeben, ohne dass der eigentliche Zahlenwert eingeblendet werden muss.

Bei numerischen Variablen ohne automatisch vorgegebene Statusfarbe kann weiterhin eine eigene Statusfarbe im Floorplan konfiguriert werden.

## 🚪 Türen und Fenster

Türen und Fenster werden direkt einer Wand zugeordnet und bewegen sich zusammen mit dieser.

Türen können in Position, Breite und Anschlag konfiguriert werden.

Fensterkontakte können mit einer Boolean-Variable verknüpft werden. Ein geöffnetes Fenster wird entsprechend dargestellt und farblich hervorgehoben.

Rollläden und Jalousien können direkt am Fenster mit eigenen Variablen verknüpft und bedient werden.

Die Darstellung und Zuordnung der Fenster- und Rollladenfunktionen kann im Editor konfiguriert werden.

## 🛋️ Möbel

Möbel dienen zur Gestaltung des Grundrisses und können frei platziert, verschoben, gedreht und skaliert werden.

Es stehen zahlreiche Easy-Floorplan-Möbelsymbole zur Verfügung.

Die Möbelliste wird im Konfigurator **alphabetisch** angezeigt.

Die Beschriftung eines Möbels kann optional ein- oder ausgeblendet werden.

## 🔷 Formen

Zusätzlich zu Möbeln können Formen zur Gestaltung und Kennzeichnung von Flächen verwendet werden.

Verfügbar sind:

- Dreieck
- Kreis / Ellipse
- Linie
- Pfeil
- Rechteck

Die Formen werden im Konfigurator **alphabetisch** aufgelistet.

Nach dem Platzieren können Formtyp, Position, Größe, Drehung, Name und Darstellung über die Eigenschaften angepasst werden.

Für geschlossene Formen kann optional eine Füllung verwendet werden.

Formen liegen grafisch **unterhalb der Möbel**, da sie beispielsweise zur Kennzeichnung von Grundflächen oder Bereichen verwendet werden können.

Die Beschriftung einer Form kann optional eingeblendet werden.

## 🕹️ Bedienung

Ein Klick auf ein Gerät öffnet – sofern erforderlich – die passende Bedienung direkt am Grundriss.

Boolean-Werte können direkt geschaltet werden.

Integer- und Float-Werte können abhängig von der IP-Symcon-Konfiguration über Profilwerte, Auswahlmöglichkeiten oder Slider bedient werden.

Variablen ohne hinterlegte Aktion werden ausschließlich als Status angezeigt.

Das Geräte-Popup wird durch einen Klick oder Tipp außerhalb wieder geschlossen.

## ✏️ Editor

Die Werkzeugleiste ermöglicht unter anderem das Erstellen von:

- Formen
- Wänden
- Türen
- Fenstern
- Geräten
- Texten
- Möbeln

Werkzeuge sind nur aktiv, solange sie tatsächlich benötigt werden.

Ein erneuter Klick auf ein bereits aktives Werkzeug deaktiviert dieses wieder.

Beim Wechsel zu einem anderen Werkzeug oder einer anderen Funktion wird das bisher aktive Werkzeug automatisch deaktiviert.

Auch beim Wechsel zwischen **Editor** und **Live-Ansicht** bleiben keine alten Werkzeuge aktiv.

Bestehende Elemente können direkt angeklickt und anschließend über ihre Eigenschaften bearbeitet werden.

Der komplette Grundriss kann über **Verschieben** oder jederzeit mit der mittleren Maustaste verschoben werden.

## 🏢 Etagen

Es können mehrere Etagen angelegt werden. Jede Etage besitzt einen eigenen Grundriss und eigene Geräte.

Bestehende Etagen können kopiert oder vollständig gelöscht werden.

In der Live-Ansicht kann über die Etagenwahl schnell zwischen den Grundrissen gewechselt werden.

Zoom und Position werden für die Darstellung entsprechend berücksichtigt.

## 🎥 Kamerastreams

Kamerastreams können als Geräte in den Floorplan eingebunden und direkt innerhalb der Visualisierung dargestellt werden.

Die Streamdarstellung berücksichtigt dabei die verfügbare Kachelgröße, damit das Videofenster nicht unnötig durch den Rand der Visualisierung abgeschnitten wird.

## 🌗 Theme-Unterstützung

Floorplan unterstützt das helle und dunkle IP-Symcon-Theme.

Farben, Bedienelemente, Statusanzeigen und Editor-Elemente passen sich entsprechend an die verwendete Darstellung an.

## ⚙️ Technische Bereitstellung

Floorplan verwendet für größere JavaScript-Ressourcen einen eigenen WebHook.

Dadurch müssen große Ressourcen nicht vollständig über den HTML-Output der Visualisierung übertragen werden und die Output-Buffer-Grenzen von IP-Symcon werden vermieden.

Die benötigten Ressourcen werden lokal aus dem Modul bereitgestellt. Eine externe Cloud ist nicht erforderlich.

## 📦 Installation

Das Modul kann über den IP-Symcon Module Store bzw. die Modulverwaltung installiert werden.

Anschließend eine **Floorplan-Instanz** anlegen, den Editor öffnen und den Grundriss erstellen.

**Voraussetzung:** IP-Symcon ab Version 8.2.

## 📝 Änderungen

### 1.10

- Konfigurationsformular aktualisiert.
- Auswahl der Formen derjenigen der Möbel angepasst.
- Formen um Pfeil und Dreieck erweitert.
- Verhalten der Buttons und Werkzeuge im Editor überarbeitet.
- Aktive Werkzeuge werden beim Wechsel zu anderen Funktionen automatisch deaktiviert.
- Formen liegen nun unterhalb der Möbel, da sie unter anderem Grundflächen markieren sollen.
- Möbel und Formen werden nun alphabetisch gelistet.
- Floorplaner in Floorplan umbenannt.

### 1.9

- Einige Sichtbarkeits-Verbesserungen im Bearbeitungsmodus des Light-Themes.
- Die Formen sind nun analog zu den Möbeln konfigurierbar und es kann nach Wunsch eine Füllung ausgewählt werden.
- Integerfarben werden nun unterstützt. So kann ein Icon bzw. Statusring den Zustand auch ohne angezeigten Wert wiedergeben.
- Farben und Darstellungsinformationen können aus IP-Symcon-Profilen und aktuellen Variablendarstellungen übernommen werden.

### 1.8

- Fenster und Türen sind nun umfassend konfigurierbar.
- Videofenster werden nicht mehr durch den Rand der Kachel abgeschnitten.
- Es können nun Formen wie Rechtecke, Kreise oder Linien erstellt werden.
- Größere Ressourcen werden nun über einen WebHook bereitgestellt, um Output-Buffer-Meldungen von IP-Symcon zu verhindern.
- Diverse kleinere Anpassungen.

### 1.7

- Der farbige Statusring funktioniert nun auch im hellen Theme.
- Kamerastreams können nun dargestellt werden.

### 1.6

- Die Leuchtfarbe der Icons kann nun durch den Konfigurator übersteuert werden.

### 1.5

- Icons mit einer reinen Statusvariable reagieren nun nicht mehr auf Betätigung.
- Die Mauerdicke lässt sich nun konfigurieren.

### 1.4

- Icons werden nun sowohl aus den neuen IP-Symcon-Variablendarstellungen als auch aus Legacy-Profilen automatisch übernommen.
- Bei Boolean-Variablen können zwei unterschiedliche Icons für AUS und EIN aus der Variablendarstellung übernommen und verwendet werden.
- Die übernommenen Icons können weiterhin manuell geändert oder über **Variableneinstellungen aktualisieren** neu aus IP-Symcon eingelesen werden.

### 1.3

- IP-Symcon-Icons werden automatisch aus der Variable übernommen und können manuell geändert werden.
- Umfangreiche Iconauswahl mit rund 4000 IP-Symcon-Icons.
- Verbesserungen an Geräte-, Fenster- und Statusdarstellung.

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