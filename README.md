# Symcon-FACESignage

IP-Symcon-Modul zur Steuerung des **FACE SignageServers** (Digital Signage auf
Raspberry-Pi-Basis, siehe Repo `FACE-Signage`) über dessen REST-API.

## Funktionen

- **Notfall-Playlist** auslösen/beenden (übersteuert ALLE Player, sperrt
  Zeitpläne, stellt danach den vorherigen Zustand wieder her) – als schaltbare
  Boolean-Variable **„Notfall"** und per Skript
- **Playlist wechseln** pro Player
- **Live-Status**: je registriertem Player eine automatisch angelegte
  String-Variable mit der laufenden Playlist (bzw. `offline`)
- **Thumbnails** der aktuell laufenden Folie (URL für WebFront/IPSView)

## Einrichtung

1. Modul über die Modulverwaltung installieren (Git-URL) oder Ordner kopieren
2. Instanz **„FACE Signage Server"** anlegen
3. Host (Pi-IP) und **API-Token** eintragen – der Token steht in der
   Signage-Verwaltung unter *Einstellungen → API-Token*
4. Die Notfall-Playlist wird in der Signage-Verwaltung geflaggt:
   *Playlists → „Als Notfall-Playlist"*

## Skript-Beispiele

```php
FSIG_Notfall(12345, '');                        // Notfall (geflaggte Playlist)
FSIG_Notfall(12345, 'unwetter');                // Notfall mit bestimmter Playlist
FSIG_NotfallEnde(12345);                        // Entwarnung, Zustand wiederherstellen
FSIG_Playlist(12345, 'Meeting', 'Samsung', 'Immer');
FSIG_Bild(12345, 'Meeting', 'Samsung', 'angebot.jpg');
FSIG_ThumbUrl(12345, 'Meeting', 'Samsung');     // Bild-URL fuer HTML-Box
```

## Hinweise

- SymBox-kompatibel (PHP 7, keine Zusatzmodule, kein MQTT nötig)
- Kommunikation: HTTP im LAN, Token-gesichert; Polling-Intervall einstellbar,
  nach Aktionen wird der Status automatisch zeitnah nachgeladen
