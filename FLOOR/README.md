# 🏠 Floorplaner für IP-Symcon

**Floorplaner** ist ein grafischer Grundriss-Editor für IP-Symcon.

Grundrisse können direkt in der Visualisierung erstellt und bearbeitet
werden. Geräte und IP-Symcon-Variablen lassen sich anschließend direkt
im Grundriss anzeigen und -- sofern die Variable eine Aktion unterstützt
-- bedienen.

Die Bedienung orientiert sich an [Easy
Floorplan](https://github.com/nicosandller/easy-floorplan), wurde jedoch
speziell für die Verwendung innerhalb von IP-Symcon umgesetzt.

------------------------------------------------------------------------

## ✨ Funktionen

### 🏗️ Grundriss erstellen

Der Grundriss wird direkt im integrierten Editor erstellt.

Unterstützt werden unter anderem:

-   Wände zeichnen
-   Türen und Fenster in Wände einsetzen
-   Texte platzieren
-   Möbel platzieren
-   Geräte platzieren
-   Elemente mit der Maus verschieben
-   Wände, Türen/Fenster, Texte, Möbel und Geräte bearbeiten bzw.
    skalieren
-   Möbel drehen
-   Raster mit automatischem Einrasten
-   mehrere Etagen
-   Etagen kopieren
-   Reihenfolge der Etagen festlegen
-   komplette Etagen löschen
-   jede Etage separat einpassen

Die Zeichenfläche besitzt keine fest vorgegebene Projektgröße. Der
Grundriss wird dynamisch an die verfügbare Fläche angepasst.

------------------------------------------------------------------------

## 🛋️ Möbel

Für die Einrichtung stehen verschiedene Möbel und Objekte zur Verfügung,
unter anderem:

-   Sofa
-   Sessel / Sitzmöbel
-   Bett
-   Tisch
-   runder Tisch
-   Stuhl
-   Schreibtisch
-   Schrank
-   Teppich
-   Fernseher
-   Waschmaschine
-   Trockner
-   Geschirrspüler
-   Kühlschrank
-   Herd
-   Spüle
-   Badewanne
-   Toilette
-   Waschtisch
-   Pflanze
-   Aquarium
-   Whirlpool
-   Klavier
-   Treppe
-   Boiler / Wassererwärmer
-   Lüftungsgerät

Möbel können mit der Maus verschoben, skaliert und in ganzen
Gradschritten gedreht werden.

Der Name eines Möbelstücks kann bei Bedarf eingeblendet werden.

------------------------------------------------------------------------

## 💡 Geräte und IP-Symcon-Variablen

Geräte werden mit einer IP-Symcon-Variable verbunden.

Die Variable wird direkt über den IP-Symcon-Objektbaum ausgewählt. Ein
separates Eingabefeld für Variablen-IDs ist nicht notwendig.

Folgende Gerätetypen stehen zur Verfügung:

-   Allgemein
-   Licht
-   Schalter
-   Steckdose
-   Bewegung / Präsenz
-   Temperatur
-   Feuchte
-   Klima / Heizung

Je nach Gerätetyp wird automatisch das passende Symbol verwendet. Für
die Symbole werden Material Design Icons (MDI) verwendet.

Eine zusätzliche manuelle Symbolauswahl ist nicht notwendig -- der
ausgewählte Gerätetyp bestimmt die Darstellung.

Fenster, Türen und Rollläden werden nicht als normale Geräte angelegt,
sondern direkt an der jeweiligen Öffnung konfiguriert.

------------------------------------------------------------------------

## 👁️ Geräteanzeige

Für Geräte können die einzelnen Bestandteile der Anzeige unabhängig
voneinander aktiviert werden:

-   **Name anzeigen**
-   **Wert anzeigen**
-   **Symbol anzeigen**

Name und Wert können in ihrer Größe angepasst und oberhalb, unterhalb,
links oder rechts vom Gerät positioniert werden.

Dadurch kann die Darstellung je nach Gerät sehr kompakt gehalten werden.

------------------------------------------------------------------------

## 🌡️ Temperatur und Luftfeuchtigkeit

Messwerte können direkt im Grundriss dargestellt werden.

Beispiele:

    21.6 °C

oder:

    48 %

Temperatur- und Feuchtigkeitsvariablen werden soweit möglich automatisch
erkannt.

Für Temperatur und Luftfeuchtigkeit wird standardmäßig die reine
Wertanzeige ohne zusätzliches Gerätesymbol verwendet.

Name, Wert und Symbol können trotzdem individuell ein- oder ausgeblendet
werden.

------------------------------------------------------------------------

## 🌡️ Klima / Heizung

Für Klima- und Heizungsvariablen steht ein eigenes kompaktes
Bedienelement zur Verfügung.

Es wird als kleines rechteckiges Wand-Bedienteil dargestellt und
unterscheidet sich dadurch optisch von normalen Gerätesymbolen.

Die zugeordnete IP-Symcon-Variable kann -- abhängig vom Variablenprofil
und der hinterlegten Aktion -- direkt bedient werden.

------------------------------------------------------------------------

## 🎨 Statusanzeige von Geräten

Unterstützte Geräte können ihren Zustand direkt am Symbol darstellen.

Dies gilt insbesondere für:

-   Allgemein
-   Licht
-   Schalter
-   Steckdose
-   Bewegung / Präsenz

Bei Boolean-Variablen kann der aktive Zustand über eine konfigurierbare
Statusfarbe dargestellt werden.

Bei geeigneten Integer- und Float-Variablen folgt die Intensität des
farbigen Statusrings dem aktuellen Wert zwischen Profil-Minimum und
Profil-Maximum.

Der normale Geräteumriss bleibt dabei unabhängig vom Wert sichtbar.

Temperatur, Feuchte und Klima / Heizung verwenden keine Statusfarbe.

------------------------------------------------------------------------

## 🎚️ Direkter Slider

Für geeignete Integer- und Float-Variablen kann ein Slider direkt unter
dem Gerät eingeblendet werden.

Voraussetzungen sind:

-   numerische Variable
-   gültiger Wertebereich mit Minimum und Maximum
-   keine Profil-Assoziationen

Der Slider kann über die Option **Slider anzeigen** aktiviert werden.

Der Wert wird während des Verschiebens unmittelbar in der Darstellung
aktualisiert. Beim Loslassen wird der neue Wert an IP-Symcon übergeben.

Sind Name oder Wert ebenfalls unterhalb des Geräts angeordnet, werden
sie automatisch unterhalb des Sliders platziert.

------------------------------------------------------------------------

## 🎛️ Geräte bedienen

Unterstützte IP-Symcon-Variablen können direkt aus dem Grundriss bedient
werden.

Bei Boolean-Variablen kann beispielsweise ein Licht direkt ein- oder
ausgeschaltet werden.

Bei Integer-Variablen mit Profil-Assoziationen werden die vorhandenen
Profilwerte als kompakte Schaltflächen angeboten.

Bei numerischen Integer- oder Float-Variablen ohne Assoziationen kann --
sofern das Profil einen gültigen Wertebereich enthält -- ein Slider zur
Bedienung verwendet werden.

Die eigentliche Aktion wird über die in IP-Symcon hinterlegte
Variablenaktion bzw. die zugehörige Instanz ausgeführt.

------------------------------------------------------------------------

## 🚪 Türen und Fenster

Türen und Fenster werden direkt in eine Wand eingesetzt.

Einer Öffnung kann eine IP-Symcon-Variable für den Kontakt bzw. die
Position zugeordnet werden.

Der aktuelle Zustand wird grafisch dargestellt:

-   Türen werden als einzelner Türflügel dargestellt.
-   Fenster werden im geöffneten Zustand als einzelner Fensterflügel mit
    einer kleinen Öffnungsprojektion dargestellt.
-   Die Fensteröffnung ist bewusst dezent gehalten.

Die Animation von Tür bzw. Fenster kann bei Bedarf invertiert werden.

------------------------------------------------------------------------

## 🪟 Rollladen / Jalousie

Fenstern kann optional eine Rollladen- bzw. Jalousievariable zugeordnet
werden.

Die Bedienung erfolgt im Live-Modus direkt am Fenster.

Unterstützt werden unter anderem:

-   Integer-Variablen mit Profil-Assoziationen
-   numerische Variablen mit Wertebereich
-   Rollladen- bzw. Klappladen-Darstellung
-   invertierbare Rollladen-Animation

### Integerwerte dem Rollladenstatus zuordnen

Nicht jede Rollladenvariable liefert eine Position in Prozent. Manche
Variablen verwenden Integerwerte als Befehle, beispielsweise:

-   Öffnen
-   Schritt auf
-   Stop
-   Schritt zu
-   Schließen
-   Dimmen
-   Halb

Für solche Integerprofile kann **Rollo-Werte zuordnen** aktiviert
werden.

Danach kann jedem vorhandenen Profilwert eine grafische
Rollladenstellung zugeordnet werden:

-   Offen (100 %)
-   75 % offen
-   Halb (50 %)
-   25 % offen
-   Geschlossen (0 %)
-   Position nicht ändern

Damit kann dieselbe Variable sowohl zur Bedienung als auch zur
sinnvollen grafischen Darstellung verwendet werden.

Bei Befehlen wie Stop oder Schritt auf/zu kann **Position nicht ändern**
verwendet werden, damit ein reiner Steuerbefehl nicht fälschlicherweise
als feste Rollladenposition interpretiert wird.

------------------------------------------------------------------------

## 🔄 Live-Aktualisierung

Ändert sich eine verwendete IP-Symcon-Variable, wird die Anzeige im
Grundriss aktualisiert.

Dabei wird nicht der komplette Floorplan neu geladen. Nur die betroffene
Variable bzw. deren Darstellung wird aktualisiert.

Dadurch bleiben unter anderem die aktuell ausgewählte Etage und die
aktuelle Bedienansicht erhalten.

Neu zugeordnete Variablen werden nach dem Speichern automatisch für die
Laufzeitaktualisierung registriert.

------------------------------------------------------------------------

## 🏢 Mehrere Etagen

Ein Projekt kann mehrere Etagen enthalten.

Beispielsweise:

-   UG
-   EG
-   OG
-   Dachgeschoss

Für jede Etage kann eine **Reihenfolge** festgelegt werden.

Etagen können außerdem vollständig kopiert werden. Dabei werden
Grundriss, Möbel, Geräte und Variablenzuordnungen übernommen.

Eine komplette Etage kann jederzeit gelöscht werden.

Im Live-Modus erscheint bei mehreren Etagen eine kompakte Etagenauswahl
am unteren Rand.

Die zuletzt ausgewählte Live-Etage wird pro Floorplaner-Instanz
gespeichert und beim nächsten Öffnen wieder verwendet.

Jede Etage besitzt ihre eigene Ansicht und wird unabhängig von den
anderen Etagen eingepasst.

------------------------------------------------------------------------

## 🔍 Ansicht und Navigation

Der Grundriss kann unabhängig von seiner tatsächlichen Größe an die
verfügbare Visualisierungsfläche angepasst werden.

### Einpassen

**Einpassen** skaliert die aktuelle Etage proportional auf die
verfügbare Fläche.

Das Seitenverhältnis bleibt erhalten. Auch außerhalb der Wände
platzierte Geräte werden bei der Berechnung berücksichtigt.

Jede Etage wird separat eingepasst.

### Verschieben

Mit dem Werkzeug **Verschieben** kann der komplette Grundriss mit der
Maus bewegt werden.

Alternativ kann die Ansicht mit der mittleren Maustaste verschoben
werden.

### Zoom

Über die Schaltflächen:

    −   +

kann hinein- und herausgezoomt werden.

Bewusst nicht verwendet wird das Mausrad zum Zoomen, damit die normale
Bedienung der Visualisierung nicht gestört wird.

### Start

Mit **Start** kann jederzeit zur ursprünglichen Startansicht der
aktuellen Etage zurückgekehrt werden.

------------------------------------------------------------------------

## 📐 Raster und Einrasten

Das Raster wird direkt im Editor eingestellt.

Eine separate Snap-Einstellung ist nicht erforderlich. Das Einrasten
verwendet automatisch die eingestellte Rastergröße.

Das Raster wird ausschließlich im Editor angezeigt und ist im Live-Modus
unsichtbar.

------------------------------------------------------------------------

## ✏️ Editor und Live-Modus

Der Floorplaner unterscheidet zwischen Editor und Live-Modus.

### Editor

Im Editor wird der Grundriss erstellt und verändert.

Hier stehen unter anderem zur Verfügung:

-   Zeichenwerkzeuge
-   Raster
-   Wände
-   Türen und Fenster
-   Texte
-   Möbel
-   Geräte
-   Etagenverwaltung
-   Eigenschaften der ausgewählten Elemente

Änderungen werden automatisch gespeichert.

### Live-Modus

Im Live-Modus wird der fertige Grundriss angezeigt und bedient.

Das Raster wird hier nicht dargestellt.

Bei mehreren Etagen steht am unteren Rand eine kompakte Etagenauswahl
zur Verfügung.

Zusätzlich befindet sich unten ein kleiner Stift:

    ✎

Damit kann jederzeit wieder in den Editor gewechselt werden.

------------------------------------------------------------------------


## 💾 Sicherung und Wiederherstellung

Im Konfigurationsformular der Floorplaner-Instanz stehen unter **Projektwerkzeuge** zwei Funktionen zur Verfügung:

- **Sichern**
- **Wiederherstellen**

Mit **Sichern** wird der komplette aktuell gespeicherte Floorplan als JSON-Datei heruntergeladen.

Die Sicherung enthält den gespeicherten Projektstand, einschließlich der vorhandenen Etagen, Wände, Türen und Fenster, Möbel, Geräte, Variablenzuordnungen sowie der zugehörigen Einstellungen.

Mit **Wiederherstellen** kann eine zuvor erstellte JSON-Sicherung wieder eingelesen werden.

Dabei wird der aktuell gespeicherte Floorplan durch den Inhalt der ausgewählten Sicherungsdatei ersetzt. Anschließend werden die verwendeten IP-Symcon-Variablen erneut für die Live-Aktualisierung registriert.

Es empfiehlt sich, vor größeren Änderungen oder Umbauten am Grundriss eine Sicherung anzulegen.

---

## 💾 Automatisches Speichern

Änderungen am Grundriss werden automatisch gespeichert.

Ein zusätzlicher **Speichern-Button ist deshalb nicht erforderlich**.

Nach einer Änderung wartet der Floorplaner kurz und speichert
anschließend automatisch den aktuellen Projektstand.

------------------------------------------------------------------------

## 🎨 IP-Symcon Theme

Der Floorplaner berücksichtigt das aktuelle Erscheinungsbild von
IP-Symcon.

Unterstützt werden:

-   Dark Mode
-   Light Mode

Der Hintergrund des Floorplaners bleibt transparent, sodass sich die
Darstellung möglichst natürlich in die IP-Symcon-Visualisierung
integriert.

Die Darstellung von Bedienelementen, Geräten und Eigenschaften wurde für
beide Themes angepasst.

------------------------------------------------------------------------

## 🧩 Mehrere Floorplaner-Instanzen

Es können mehrere Floorplaner-Instanzen innerhalb einer
IP-Symcon-Installation verwendet werden.

Damit können beispielsweise unterschiedliche Gebäude oder Bereiche
getrennt dargestellt werden.

Die Projekte der einzelnen Instanzen werden unabhängig voneinander
verwaltet.

------------------------------------------------------------------------

## 📦 Voraussetzungen

-   IP-Symcon 8.2 oder neuer
-   HTML-SDK
-   moderner Browser mit SVG- und JavaScript-Unterstützung

------------------------------------------------------------------------

## 🖼️ Material Design Icons

Für Gerätesymbole verwendet Floorplaner **Material Design Icons (MDI)**.

Die benötigten SVG-Pfade sind lokal in das Modul integriert.

Dadurch ist für die Darstellung der Gerätesymbole keine externe
Icon-Bibliothek und keine Internetverbindung erforderlich.

------------------------------------------------------------------------

## 🙏 Easy Floorplan

Die Idee und Teile des Bedienkonzepts orientieren sich an:

**Easy Floorplan**

https://github.com/nicosandller/easy-floorplan

Easy Floorplan ist ein interaktiver Floorplan-Editor für Home Assistant
und steht unter der MIT-Lizenz.

Floorplaner übernimmt dieses Konzept nicht unverändert, sondern passt
die Bedienung und insbesondere die Geräte-/Variablenanbindung an
IP-Symcon an.

------------------------------------------------------------------------

## 📋 Versionen

### 1.1

-   Erweiterte Geräte- und Variablenanbindung
-   Variablenauswahl über den IP-Symcon-Objektbaum
-   Automatische Gerätesymbole mit lokal integrierten Material Design
    Icons
-   Getrennte Anzeige von Name, Wert und Symbol
-   Einstellbare Position und Größe von Name und Wert
-   Statusfarben für unterstützte Geräte
-   Wertabhängiger Statusring für Integer- und Float-Variablen
-   Direkter Slider für geeignete numerische Variablen
-   Optimierte Temperatur- und Feuchteanzeige
-   Eigenes Bedienelement für Klima / Heizung
-   Fenster- und Türkontakte direkt an Öffnungen
-   Grafische Tür- und Fensterzustände
-   Rollladen-/Jalousiesteuerung direkt am Fenster
-   Frei konfigurierbare Integerwert-Zuordnung für Rollladenstatus
-   Invertierbare Tür-, Fenster- und Rollladenanimation
-   Etagen kopieren, sortieren und löschen
-   Letzte ausgewählte Live-Etage wird gespeichert
-   Etagen werden separat und automatisch eingepasst
-   Verbesserte Möbelbearbeitung und Rotation
-   Mausbasierte Größenänderung verschiedener Grundrisselemente
-   Raster und Einrasten vereinheitlicht
-   Verbesserte Darstellung in Light und Dark Mode
-   Live-Aktualisierung ohne komplettes Neuladen des Grundrisses
-   Automatisches Speichern ohne separaten Speichern-Button
-   Optimierte Bedienung und Darstellung im Live-Modus
-   Zahlreiche Detailverbesserungen an Editor, Darstellung und Bedienung

### 1.0

-   Initiale Version

------------------------------------------------------------------------

## 📄 Lizenz

Für dieses Modul gilt die im Repository hinterlegte Lizenz.

Für übernommene bzw. verwendete Bestandteile aus Drittprojekten gelten
zusätzlich deren jeweilige Lizenzbedingungen.

Easy Floorplan:

Copyright (c) nicosandller\
MIT License

Material Design Icons:

Die verwendeten Icons unterliegen den Lizenzbedingungen des Material
Design Icons Projekts.

------------------------------------------------------------------------

## ⚠️ Hinweis

Floorplaner befindet sich in Entwicklung.

Vor größeren Änderungen empfiehlt es sich, eine Sicherung der
IP-Symcon-Konfiguration bzw. des Projekts anzulegen.

Fehler und Verbesserungsvorschläge können über das Repository gemeldet
werden.
