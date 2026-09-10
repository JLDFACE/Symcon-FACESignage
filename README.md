# CampaignRED Remote – IP-Symcon-Modul

*Bis Build 1010 hieß das Modul „FACE Signage“. Funktionen (`FSIG_*`), GUIDs und Instanzen bleiben unverändert.*

IP-Symcon-Modul zur Steuerung des **CampaignRED-Servers** (Digital-Signage-System
auf Raspberry-Pi-Basis, siehe Repo [`FACE-Signage`](https://github.com/facegmbh/FACE-Signage))
über dessen REST-API.

Das Modul bindet jeden am SignageServer registrierten Player als eigenen Ordner
in Symcon ein, zeigt Online-Status und laufende Playlist an und erlaubt das
Umschalten der Playlist – direkt per Variable oder aus Skripten/Ablaufplänen.
Zusätzlich lässt sich eine **Notfall-Playlist** über alle Player auslösen.

- **Autor:** FACE GmbH
- **Symcon-Kompatibilität:** ab Version 6.0 (inkl. SymBox)
- **Kommunikation:** HTTP im LAN, per API-Token gesichert (kein MQTT nötig)

---

## Inhalt

1. [Funktionsüberblick](#funktionsüberblick)
2. [Voraussetzungen](#voraussetzungen)
3. [Installation](#installation)
4. [Instanz einrichten](#instanz-einrichten)
5. [Was die Instanz anlegt (Objektbaum)](#was-die-instanz-anlegt-objektbaum)
6. [Bedienung](#bedienung)
7. [Funktionsreferenz (FSIG_*)](#funktionsreferenz-fsig_)
8. [Automatisierungs-Beispiele](#automatisierungs-beispiele)
9. [Status-Codes](#status-codes)
10. [Funktionsweise / Technik](#funktionsweise--technik)
11. [Fehlerbehebung](#fehlerbehebung)
12. [Änderungshistorie](#änderungshistorie)

---

## Funktionsüberblick

- **Ein Ordner je Player** (Kategorie unter der Instanz), wird beim Abruf
  automatisch angelegt – mit:
  - **Online** – ob der Player gerade erreichbar ist (Anzeige)
  - **Playlist** – schaltbare Integer-Variable mit Auswahlprofil aller
    Playlists; zeigt zugleich die aktuell laufende Playlist an
- **Playlist umschalten** pro Player – per Variable oder Skript
- **Notfall-Playlist** auslösen/beenden: übersteuert **alle** Player, sperrt
  Zeitpläne und stellt danach den vorherigen Zustand wieder her – als
  schaltbare Boolean-Variable **„Notfall"** und per Skript
- **Einzelbild/-video** ad hoc auf einen Player schicken
- **Thumbnail-URL** der aktuell laufenden Folie (für WebFront/IPSView/HTML-Box)
- **Automatische Bereinigung**: am SignageServer gelöschte Player entfernen beim
  nächsten Abruf auch ihren Ordner in Symcon (ab Build 1007)
- **Robuste Diagnose**: klar unterscheidbare Status – erreichbar / nicht
  erreichbar / Token abgelehnt – plus Variable **„Letzter Fehler"**

---

## Voraussetzungen

- **IP-Symcon 6.0** oder neuer (getestet auch auf der **SymBox**)
- Ein laufender **FACE SignageServer** im selben Netz, erreichbar per HTTP (Port 80)
- Der **API-Token** des SignageServers
  (Signage-Verwaltung → *Einstellungen → API-Token*)
- Keine Zusatzmodule, kein MQTT-Broker in Symcon nötig

---

## Installation

**Über die Modulverwaltung (empfohlen):**

1. In Symcon: *Objektbaum → Kern-Instanzen → Modules* öffnen
2. Neues Modul über die Git-URL hinzufügen:
   ```
   https://github.com/JLDFACE/Symcon-FACESignage
   ```
3. Symcon lädt das Modul und meldet Updates automatisch anhand der Build-Nummer.

**Manuell:**

Den Ordner `Symcon-FACESignage` in das `modules`-Verzeichnis der Symcon-Installation
kopieren und Symcon neu starten.

---

## Instanz einrichten

1. Neue Instanz anlegen: *Objekt hinzufügen → Instanz →* nach
   **„CampaignRED Remote"** suchen (Hersteller *FACE GmbH*).
2. Im Konfigurationsformular ausfüllen:

   | Feld | Bedeutung |
   |---|---|
   | **SignageServer (IP oder Hostname)** | IP/Hostname des Pi, z. B. `192.168.2.146` |
   | **API-Token** | Token aus der Signage-Verwaltung → *Einstellungen → API-Token* |
   | **Abrufintervall (Sekunden)** | Poll-Takt (min. 5 s, Standard 30 s) |
   | **Vorschaubild … laden** | legt je Player ein Medienobjekt mit dem Bild der laufenden Folie an (Standard: an) |

3. **Übernehmen**. Bei korrektem Host + Token wechselt die Instanz auf Status
   **„Verbunden"** und ruft sofort den ersten Status ab.

Im Formular stehen zusätzlich drei Schaltflächen bereit:

- **Jetzt abrufen** – Status sofort neu laden (`FSIG_Poll`)
- **Notfall auslösen (geflaggte Playlist)** – `FSIG_Notfall($id, '')`
- **Notfall beenden** – `FSIG_NotfallEnde($id)`

> **Notfall-Playlist festlegen:** Welche Playlist der Notfall verwendet, wird
> **im SignageServer** bestimmt – Verwaltung → *Playlists → „Als Notfall-Playlist"*.
> Der leere Aufruf `FSIG_Notfall($id, '')` nutzt genau diese geflaggte Playlist.

---

## Was die Instanz anlegt (Objektbaum)

```
CampaignRED Remote (Instanz)
├─ Online                      (Bool, ~Switch)   – SignageServer erreichbar?
├─ Notfall                     (Bool, ~Switch)   – schaltbar: Notfall an/aus
├─ Aktive Notfall-Playlist     (String)          – Name der laufenden Notfall-Playlist
├─ Letzter Fehler              (String, TextBox) – Klartext der letzten Störung
│
├─ Meeting / Samsung           (Kategorie = ein Player)
│  ├─ Online                   (Bool, ~Switch)   – dieser Player erreichbar?
│  ├─ Playlist                 (Integer)         – schaltbar; laufende Playlist
│  ├─ Folie                    (String)          – was gerade läuft, im Klartext
│  ├─ Position                 (String)          – Stelle in der Playlist, z. B. „3 / 8"
│  └─ Vorschau                 (Medienobjekt)    – Bild der laufenden Folie
│
└─ Empfang / Screen1           (Kategorie = weiterer Player)
   ├─ Online
   ├─ Playlist
   ├─ Folie
   ├─ Position
   └─ Vorschau
```

Die Player-Ordner entstehen **automatisch** beim Abruf – für jeden am
SignageServer registrierten Player einen. Die **Playlist**-Variable nutzt ein
instanz-eigenes Variablenprofil (`FSIG.<InstanzID>.PL`) mit allen verfügbaren
Playlists als Auswahlliste; es wird bei jeder Änderung der Playlist-Liste
automatisch aktualisiert.

**Folie** benennt die laufende Folie so, wie ein Mensch es täte: `angebot.jpg`,
`Vorlage: Willkommen`, `NDI: STUDIO-PC (Kamera 1)` oder `Now Playing`. Ist der
Player offline, sind **Folie** und **Position** leer – er zeigt ja nichts.
**Vorschau** ist ein Medienobjekt und lässt sich unmittelbar in
Kachel-Visualisierung, WebFront und IPSView einbinden.

Bestehende Instanzen bekommen die drei neuen Objekte beim nächsten Abruf von
selbst dazu; es ist nichts nachzutragen.

---

## Bedienung

### Playlist umschalten

**Per Variable (WebFront/Visualisierung):** In der **Playlist**-Variable des
gewünschten Player-Ordners einfach die Ziel-Playlist aus der Auswahlliste wählen.
Das Modul schaltet den Player sofort um; der Abruf bestätigt den neuen Zustand
kurz darauf.

**Per Skript:**
```php
FSIG_Playlist(12345, 'Meeting', 'Samsung', 'Immer');
```

### Notfall-Playlist

Der Notfall **übersteuert alle Player**, sperrt reguläre Zeitpläne und stellt bei
Entwarnung den vorherigen Zustand jedes Players wieder her.

**Per Variable:** die Boolean-Variable **„Notfall"** einschalten (nutzt die im
SignageServer geflaggte Notfall-Playlist), zum Beenden wieder ausschalten.

**Per Skript:**
```php
FSIG_Notfall(12345, '');           // geflaggte Notfall-Playlist des Servers
FSIG_Notfall(12345, 'Unwetter');   // bestimmte Playlist erzwingen
FSIG_NotfallEnde(12345);           // Entwarnung, Vorzustand wiederherstellen
```

### Einzelbild/-video sofort zeigen

Zeigt ad hoc eine einzelne Datei (muss auf dem SignageServer als Inhalt
vorhanden sein), ohne die Playlist dauerhaft zu ändern:
```php
FSIG_Bild(12345,  'Meeting', 'Samsung', 'angebot.jpg');
FSIG_Video(12345, 'Meeting', 'Samsung', 'trailer.mp4');
```

### Text einer gestalteten Folie setzen

Der SignageServer kennt **Vorlagen**: eine feste Gestaltung (Hintergrundbild,
Schriften, Positionen), in die der Player Textfelder live einsetzt. Datum,
Wochentag und Uhr macht der Player selbst – die wechselnden Texte kommen von
hier. Der Schlüssel ist derselbe, der im Vorlagen-Editor als *Symcon-Text*
hinterlegt ist bzw. als `{platzhalter}` im Text steht.

```php
FSIG_Text(12345, 'Foyer', '1', 'gast', "Andre Freitag
Siegfried Mack");
FSIG_Text(12345, 'Foyer', '1', 'firma', 'Stadt Schüttorf');
FSIG_Text(12345, 'Foyer', '1', 'gast', '');   // Feld leeren
```

Die Werte werden **retained** gesetzt: nach einem Neustart des Players steht
wieder der richtige Gast da. Sie gelten pro Player – im Foyer also ein anderer
Gast als im Besprechungsraum. Mehrzeilige Werte sind erlaubt (`
`), die
Vorlage bricht sie an derselben Stelle um wie im Editor sichtbar.

### Was läuft gerade? (Folie + Vorschaubild)

Ohne eigenen Code: Im Player-Ordner stehen **Folie** („Vorlage: Willkommen"),
**Position** („3 / 8") und das Medienobjekt **Vorschau**. Letzteres einfach in
die Kachel-Visualisierung ziehen – fertig.

Das Bild wird **nur bei einem Folienwechsel** neu geholt, nicht bei jedem
Abruf. Liefert der Server keins (Video ohne ffmpeg, Vorlage ohne Bild), bleibt
das letzte gute Bild stehen statt eines kaputten. Wer die Bilder nicht braucht,
schaltet **Vorschaubild … laden** im Formular ab; bereits angelegte
Medienobjekte bleiben dann unverändert stehen.

Woher das Bild stammt:

| Folie | Bild |
|---|---|
| Bild / Video | Standbild der Datei (erzeugt der SignageServer per ffmpeg) |
| Gestaltete Folie | Bild der **Vorlage**, das der Vorlagen-Editor beim Speichern ablegt |

> Im Bild einer gestalteten Folie stehen Symcon-Texte mit ihrem *Platzhalter*
> („‹Gast›"), nicht mit dem echten Wert – das Bild gehört zur Vorlage, der Wert
> ist je Player verschieden. Den echten Bildschirminhalt zeigt die
> Signage-Verwaltung (Knopf **Bildschirm**) bei Windows- und Linux-Playern.

**Als reine URL** – etwa für eine eigene `~HTMLBox`-Variable:
```php
$url = FSIG_ThumbUrl(12345, 'Meeting', 'Samsung');
// <img src="{$url}" style="max-width:100%">
```
Die URL enthält einen Zeitstempel (`&t=…`), sodass der Browser stets das
aktuelle Bild lädt.

---

## Funktionsreferenz (FSIG_*)

Alle Funktionen erwarten als erstes Argument die **InstanzID** des Moduls.
`$Ort`/`$Screen` sind exakt die im SignageServer vergebenen Bezeichner des
Players (**Groß-/Kleinschreibung beachten**).

| Funktion | Rückgabe | Beschreibung |
|---|---|---|
| `FSIG_Poll(int $id)` | – | Status sofort abrufen und alle Variablen aktualisieren |
| `FSIG_Playlist(int $id, string $Ort, string $Screen, string $Name)` | `bool` | Player auf Playlist `$Name` umschalten |
| `FSIG_Bild(int $id, string $Ort, string $Screen, string $Datei)` | `bool` | Einzelbild `$Datei` sofort anzeigen |
| `FSIG_Video(int $id, string $Ort, string $Screen, string $Datei)` | `bool` | Einzelvideo `$Datei` sofort abspielen |
| `FSIG_Text(int $id, string $Ort, string $Screen, string $Key, string $Wert)` | `bool` | Textfeld einer Vorlage setzen (retained); leerer Wert löscht |
| `FSIG_Notfall(int $id, string $Playlist)` | `bool` | Notfall auslösen; `''` = geflaggte Playlist |
| `FSIG_NotfallEnde(int $id)` | `bool` | Notfall beenden, Vorzustand wiederherstellen |
| `FSIG_ThumbUrl(int $id, string $Ort, string $Screen)` | `string` | URL des Vorschaubilds der laufenden Folie |

Die `bool`-Funktionen liefern `true` bei Erfolg. Schlägt ein Aufruf fehl, wird
zusätzlich die Variable **„Letzter Fehler"** gesetzt und eine Warnung ins
Symcon-Log geschrieben.

---

## Automatisierungs-Beispiele

**Zeitgesteuert per Ablaufplan/Ereignis** (morgens Werbeschleife, abends Ruhebild):
```php
// 08:00 Uhr
FSIG_Playlist(12345, 'Empfang', 'Screen1', 'Tagbetrieb');
// 20:00 Uhr
FSIG_Playlist(12345, 'Empfang', 'Screen1', 'Nachtbild');
```

**Notfall an einen Alarm koppeln** (z. B. Brandmeldeanlage → Fluchtweg-Info):
```php
// Ausgelöst durch ein Ereignis auf der Alarm-Variable
if ($alarmAktiv) {
    FSIG_Notfall(12345, 'Evakuierung');
} else {
    FSIG_NotfallEnde(12345);
}
```

**Anwesenheit/Szene** (alle Screens einer Szene folgen lassen):
```php
foreach (['Screen1', 'Screen2', 'Screen3'] as $s) {
    FSIG_Playlist(12345, 'Empfang', $s, 'Meeting-Modus');
}
```

---

## Status-Codes

| Code | Anzeige | Bedeutung |
|---|---|---|
| **102** | Verbunden | Alles ok, Abruf funktioniert |
| **201** | Host oder API-Token fehlt | Konfiguration unvollständig |
| **202** | SignageServer nicht erreichbar | Kein TCP zu `<Host>:80` – IP/Netz prüfen |
| **203** | Erreichbar, aber abgelehnt | Server antwortet, aber Token falsch – Token prüfen |

Der Unterschied zwischen **202** und **203** hilft bei der Diagnose: 202 = Netz-/
Erreichbarkeitsproblem, 203 = der Server ist da, lehnt aber den Token ab.

---

## Funktionsweise / Technik

- **Nur GET-Aufrufe.** Alle Aktionen laufen als HTTP-GET mit Query-Parametern
  über `Sys_GetURLContentEx` (Timeout 5 s). Grund: die SymBox sendet POST-Bodies
  nicht zuverlässig. Der SignageServer bietet für jede Aktion eine GET-Route
  unter `/api/hook/…`; der Token wird als Query-Parameter angehängt.
- **Ein Timer (`Poll`).** Der zyklische Abruf hält alle Variablen aktuell.
  `SetValueIfChanged` verhindert unnötige Kernel-Ereignisse.
- **Schnelle Bestätigung.** Nach einer Aktion (Umschalten, Notfall) wird der
  nächste Abruf kurzzeitig auf 2 s vorgezogen und danach das reguläre Intervall
  wiederhergestellt.
- **Dynamische Player-Ordner.** Beim Abruf wird je gemeldetem Player eine
  Kategorie (`Ident P_<ort_screen>`) mit **Online** und **Playlist** angelegt
  bzw. aktualisiert. Am Server gelöschte Player werden hier automatisch
  entfernt (Build 1007).
- **Instanz-eigenes Profil.** Das Auswahlprofil `FSIG.<InstanzID>.PL` gehört
  zur Instanz und wird bei Deinstallation wieder aufgeräumt.

### Genutzte Server-Routen (REST-API)

| Route | Zweck |
|---|---|
| `GET /api/hook/status` | Gesamtstatus + Player-Liste (Basis des Polls) |
| `GET /api/hook/playlists` | Namen aller Playlists (füllt das Auswahlprofil) |
| `GET /api/hook/playlist?ort&screen&name` | Playlist eines Players wechseln |
| `GET /api/hook/asset?ort&screen&type&src` | Einzelbild/-video sofort zeigen |
| `GET /api/hook/emergency?start=1 \| name= \| end=1` | Notfall auslösen/beenden |
| `GET /api/hook/thumb?ort&screen` | Vorschaubild der laufenden Folie |

Alle Routen erwarten den Parameter `token=<API-Token>`.

---

## Fehlerbehebung

**Status „Host oder API-Token fehlt" (201)**
Host und/oder Token im Formular sind leer. Beides eintragen und übernehmen.

**Status „SignageServer nicht erreichbar" (202)**
Der Pi antwortet nicht auf Port 80. Prüfen: IP/Hostname korrekt? Gleiches Netz?
Pi eingeschaltet und der Dienst gestartet? (Tipp: die IP des Pi lässt sich in
der Signage-Verwaltung unter *Einstellungen → Netzwerk* fest vergeben.)

**Status „Erreichbar, aber abgelehnt" (203)**
Der Server ist erreichbar, weist aber den Token ab. Token in der Verwaltung
(*Einstellungen → API-Token*) kopieren und exakt ins Formular übernehmen.

**Playlist schaltet nicht / „Playlist unbekannt"**
Die Playlist-Namen sind **case-sensitive**. Die Auswahlliste in der
**Playlist**-Variable wird automatisch vom Server befüllt – wähle daraus,
statt Namen manuell zu tippen. Ist die Liste veraltet, hilft **Jetzt abrufen**.

**Ein neu registrierter Player fehlt in Symcon**
Der Ordner entsteht erst beim nächsten Abruf nach der Registrierung. Einmal
**Jetzt abrufen** auslösen oder ein Poll-Intervall abwarten.

**Gelöschte Player bleiben als Ordner stehen**
Ab **Build 1007** werden sie beim nächsten erfolgreichen Abruf automatisch
entfernt. Modul auf Build 1007+ aktualisieren und einmal **Jetzt abrufen**.

---

## Änderungshistorie

| Build | Änderung |
|---|---|
| **1012** | Playlist-Profil mit Wertebereich (IPSView zeigte sonst nur den ersten Eintrag) und Eintrag „–“ für Player ohne Playlist |
| **1011** | Umbenennung in **CampaignRED Remote**; Playlist-Aktion über ein Aktionsskript in der Instanz (Symcon 9 lehnt Instanz-IDs als CustomAction ab, Warnung bei jedem Poll) |
| **1010** | Playlists heißen in der Auswahlliste wie im Hub statt „pl_6" (Feld `listen`) |
| **1009** | Je Player: laufende Folie, Position und Vorschaubild als Medienobjekt |
| **1008** | `FSIG_Text`: Textfelder gestalteter Folien aus Symcon setzen |
| **1007** | Am Server gelöschte Player entfernen automatisch ihren Ordner in Symcon |
| **1006** | Pro Player ein Ordner mit Online + Playlist-Umschalter |
| **1005** | Variablenprofil nach Modul-Reload zuverlässig wieder befüllen |
| **1004** | Umstellung auf reine GET-Aufrufe (SymBox-kompatibel) |

---

© FACE GmbH · Modul für IP-Symcon · Steuert den CampaignRED-Server.
