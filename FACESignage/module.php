<?php

/*
 * CampaignRED Remote (ehem. FACE Signage) - IP-Symcon Modul
 *
 * Steuert den CampaignRED-Server (Standort-Server, Raspberry Pi) ueber dessen Hook-API.
 *
 * Designentscheidungen (kurz):
 * - SymBox-sicher: kein strict_types, keine PHP8-Funktionen, keine Fatals.
 * - Kein IO: HTTP via Sys_GetURLContentEx mit kurzem Timeout pro Request.
 * - Polling: ein Timer (FSIG_Poll), SetValueIfChanged gegen Kernel-Spam.
 * - Player-Variablen werden dynamisch per MaintainVariable angelegt
 *   (eine String-Variable je registriertem Player: laufende Playlist).
 * - Notfall: Boolean-Variable mit Action - true loest die im SignageServer
 *   geflaggte Notfall-Playlist aus, false stellt den Vorzustand wieder her.
 */

class FACESignage extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Properties
        $this->RegisterPropertyString('Host', '192.168.2.146');
        $this->RegisterPropertyString('Token', '');
        $this->RegisterPropertyInteger('PollInterval', 30);
        // Vorschaubild je Player: kostet pro Folienwechsel einen Bild-Download,
        // darum abschaltbar (bei vielen Playern spuerbar).
        $this->RegisterPropertyBoolean('Preview', true);

        // Attribute (persistent): Player-Zuordnung + Stand des Playlist-Profils
        $this->RegisterAttributeString('PlayerMap', '{}');    // ident -> {ort, screen}
        $this->RegisterAttributeString('PlaylistNames', '[]'); // Schluessel, Reihenfolge im Profil
        $this->RegisterAttributeString('PlaylistLabels', '[]'); // Klarnamen dazu (Beschriftung)
        $this->RegisterAttributeInteger('PlaylistCount', 0);   // fuer Profil-Aufraeumen

        // Variables
        $this->RegisterVariableBoolean('Online', 'Online', '~Switch', 10);
        $this->DisableAction('Online');

        $this->RegisterVariableBoolean('Emergency', 'Notfall', '~Switch', 20);
        $this->EnableAction('Emergency');

        $this->RegisterVariableString('EmergencyPlaylist', 'Aktive Notfall-Playlist', '', 30);
        $this->RegisterVariableString('LastError', 'Letzter Fehler', '~TextBox', 900);
        // je Player entsteht darunter ein Ordner (Kategorie) mit Online +
        // Playlist-Umschalter, dynamisch beim Poll (EnsurePlayerFolder).

        // Timer (Prefix-Funktion, nicht IPS_RequestAction)
        $this->RegisterTimer('Poll', 0, 'FSIG_Poll($_IPS["TARGET"]);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        if (IPS_GetKernelRunlevel() != KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }

        // Bestandsinstanzen: alten Variablennamen auf "Online" ziehen
        $onlineID = @$this->GetIDForIdent('Online');
        if ($onlineID && IPS_GetName($onlineID) !== 'Online') {
            IPS_SetName($onlineID, 'Online');
        }
        // Migration: fruehere flache PL_-Variablen (direkte Instanz-Kinder)
        // entfernen - Player-Variablen liegen jetzt in Ordnern (Kategorien).
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $cid) {
            $obj = IPS_GetObject($cid);
            if ($obj['ObjectType'] === 2 && strncmp($obj['ObjectIdent'], 'PL_', 3) === 0) {
                @IPS_DeleteVariable($cid);
            }
        }

        $ok = (trim($this->ReadPropertyString('Host')) !== '') &&
              (trim($this->ReadPropertyString('Token')) !== '');
        $this->SetStatus($ok ? 102 : 201);
        $this->SetTimerInterval('Poll', $ok ? max(5, (int)$this->ReadPropertyInteger('PollInterval')) * 1000 : 0);
        if ($ok) {
            $this->Poll();
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message == IPS_KERNELSTARTED) {
            $this->ApplyChanges();
        }
    }

    public function Destroy()
    {
        // instanz-eigene Profile aufraeumen
        $prefix = 'FSIG.' . $this->InstanceID . '.';
        foreach (IPS_GetVariableProfileList() as $p) {
            if (strpos($p, $prefix) === 0) {
                @IPS_DeleteVariableProfile($p);
            }
        }
        parent::Destroy();
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident === 'Emergency') {
            if ((bool)$Value) {
                $this->Notfall('');     // geflaggte Notfall-Playlist des Servers
            } else {
                $this->NotfallEnde();
            }
            return;
        }
        // Player-Playlist umschalten (Integer -> Playlist-Name ueber Profil)
        if (strncmp($Ident, 'PL_', 3) === 0) {
            $map = json_decode($this->ReadAttributeString('PlayerMap'), true);
            $names = json_decode($this->ReadAttributeString('PlaylistNames'), true);
            $i = (int)$Value;
            if (isset($map[$Ident]) && is_array($names) && isset($names[$i])) {
                if (isset($map[$Ident]['plid'])) {
                    SetValueInteger((int)$map[$Ident]['plid'], $i);   // optimistisch, Poll bestaetigt
                }
                $this->Playlist((string)$map[$Ident]['ort'], (string)$map[$Ident]['screen'], (string)$names[$i]);
            }
            return;
        }
    }

    /* ================= Oeffentliche Funktionen (FSIG_*) ================= */

    /** Status abrufen und Variablen aktualisieren. */
    public function Poll()
    {
        if (IPS_GetKernelRunlevel() != KR_READY) {
            return;
        }
        if (!$this->Lock()) {
            return;
        }
        try {
            $st = $this->HttpGet('/api/hook/status');
            $online = !isset($st['error']) && isset($st['players']);
            $this->SetValueIfChanged('Online', $online);
            if (!$online) {
                // Erreichbarkeit von der Token-Frage trennen (klare Diagnose):
                // TCP zum Webserver offen, aber Aufruf gescheitert = Token/Route falsch.
                $host = trim($this->ReadPropertyString('Host'));
                if ($host !== '' && $this->TestTcp($host, 80, 1500)) {
                    $this->SetStatus(203);   // erreichbar, aber abgelehnt
                    $this->SetValueIfChanged('LastError', 'Erreichbar, aber abgelehnt - API-Token pruefen (Verwaltung -> Einstellungen -> API-Token)');
                } else {
                    $this->SetStatus(202);   // gar nicht erreichbar
                    $this->SetValueIfChanged('LastError', 'SignageServer nicht erreichbar (' . $host . ':80)');
                }
                return;
            }
            $this->SetStatus(102);
            $this->SetValueIfChanged('LastError', '');

            $em = isset($st['emergency']) ? $st['emergency'] : null;
            $this->SetValueIfChanged('Emergency', $em !== null);
            $this->SetValueIfChanged('EmergencyPlaylist', $em !== null ? (string)$em : '');

            /* Playlist-Profil aktuell halten (Auswahlliste zum Umschalten).
             *
             * SCHLUESSEL UND NAME SIND ZWEIERLEI. Geschaltet wird ueber den
             * Schluessel - aus der Cloud ist das "pl_6", und daran haengen am
             * Standort die Geraetezuweisung, die Zeitplaene und die
             * Rueckmeldung des Players. Angezeigt gehoert der Name aus dem Hub
             * ("Test"). Frueher stand der Schluessel in der Auswahlliste, und
             * niemand wusste, was pl_6 ist.
             *
             * "listen" liefert beides; aeltere Server kennen nur "playlists"
             * mit den Schluesseln - dann ist der Name eben der Schluessel. */
            $pl = $this->HttpGet('/api/hook/playlists');
            $names = array();
            $labels = array();
            if (isset($pl['listen']) && is_array($pl['listen'])) {
                foreach ($pl['listen'] as $e) {
                    if (!is_array($e) || !isset($e['id'])) {
                        continue;
                    }
                    $names[] = (string)$e['id'];
                    $name = isset($e['name']) ? trim((string)$e['name']) : '';
                    $labels[] = ($name !== '') ? $name : (string)$e['id'];
                }
            } elseif (isset($pl['playlists']) && is_array($pl['playlists'])) {
                $names = array_values($pl['playlists']);
                $labels = $names;
            }
            $profile = $this->SyncPlaylistProfile($names, $labels);

            // je Player ein Ordner (Kategorie) mit Online + Playlist-Umschalter
            $pos = 100;
            $map = array();
            $keep = array();   // Ident der aktuell vom Server gemeldeten Player-Ordner
            $preview = (bool)$this->ReadPropertyBoolean('Preview');
            foreach ($st['players'] as $p) {
                $o = $this->EnsurePlayerFolder((string)$p['location'], (string)$p['screen'], $profile, $pos);
                SetValueBoolean($o['online'], (bool)$p['online']);
                $running = (isset($p['playlist']) && $p['playlist'] !== null) ? (string)$p['playlist'] : '';
                $idx = array_search($running, $names, true);
                if ($idx !== false && GetValueInteger($o['playlist']) !== (int)$idx) {
                    SetValueInteger($o['playlist'], (int)$idx);
                }

                // Laufende Folie: offline zeigt der Player nichts - dann die
                // Felder leeren statt den letzten Stand als aktuell auszugeben.
                $on = (bool)$p['online'];
                $slide = ($on && isset($p['slide']) && $p['slide'] !== null) ? (string)$p['slide'] : '';
                $nummer = ($on && !empty($p['position']) && !empty($p['count']))
                    ? ((int)$p['position'] . ' / ' . (int)$p['count']) : '';
                // Bild nur holen, wenn eine ANDERE Folie laeuft - sonst laedt
                // jeder Abruf dasselbe JPEG erneut.
                $gewechselt = GetValueString($o['slide']) !== $slide;
                $this->SetObjIfChanged($o['slide'], $slide);
                $this->SetObjIfChanged($o['position'], $nummer);
                if ($preview) {
                    $this->UpdatePreview($o['cat'], $o['key'],
                        (string)$p['location'], (string)$p['screen'], $gewechselt);
                }
                $keep['P_' . $o['key']] = true;
                $map['PL_' . $o['key']] = array(
                    'ort' => (string)$p['location'], 'screen' => (string)$p['screen'], 'plid' => $o['playlist']);
                $pos += 10;
            }
            // Auf dem Server geloeschte Player -> ihren Ordner hier entfernen
            $this->PruneOrphans($keep);
            $this->WriteAttributeString('PlayerMap', json_encode($map));
        } finally {
            // regulaeres Intervall wiederherstellen (PollSoon setzt kurzzeitig 2 s)
            $this->SetTimerInterval('Poll', max(5, (int)$this->ReadPropertyInteger('PollInterval')) * 1000);
            $this->Unlock();
        }
    }

    /** Playlist eines Players wechseln. */
    public function Playlist(string $Ort, string $Screen, string $Name)
    {
        $r = $this->HttpGet('/api/hook/playlist?ort=' . rawurlencode($Ort)
            . '&screen=' . rawurlencode($Screen) . '&name=' . rawurlencode($Name));
        $this->PollSoon();
        return $this->OkOrLog($r, 'Playlist ' . $Name . ' -> ' . $Ort . '/' . $Screen);
    }

    /** Einzelbild sofort anzeigen. */
    public function Bild(string $Ort, string $Screen, string $Datei)
    {
        $r = $this->HttpGet('/api/hook/asset?type=image&ort=' . rawurlencode($Ort)
            . '&screen=' . rawurlencode($Screen) . '&src=' . rawurlencode($Datei));
        return $this->OkOrLog($r, 'Bild ' . $Datei . ' -> ' . $Ort . '/' . $Screen);
    }

    /** Einzelvideo sofort abspielen. */
    public function Video(string $Ort, string $Screen, string $Datei)
    {
        $r = $this->HttpGet('/api/hook/asset?type=video&ort=' . rawurlencode($Ort)
            . '&screen=' . rawurlencode($Screen) . '&src=' . rawurlencode($Datei));
        return $this->OkOrLog($r, 'Video ' . $Datei . ' -> ' . $Ort . '/' . $Screen);
    }

    /** Notfall ausloesen. Leerer String = im SignageServer geflaggte Playlist. */
    public function Notfall(string $Playlist)
    {
        $q = ($Playlist !== '') ? 'name=' . rawurlencode($Playlist) : 'start=1';
        $r = $this->HttpGet('/api/hook/emergency?' . $q);
        $this->PollSoon();
        return $this->OkOrLog($r, 'NOTFALL' . ($Playlist !== '' ? ' (' . $Playlist . ')' : ''));
    }

    /** Notfall beenden, vorherigen Zustand aller Player wiederherstellen. */
    public function NotfallEnde()
    {
        $r = $this->HttpGet('/api/hook/emergency?end=1');
        $this->PollSoon();
        return $this->OkOrLog($r, 'Notfall beendet');
    }

    /** URL des Vorschaubilds der aktuell laufenden Folie eines Players. */
    /* Text einer gestalteten Folie setzen (Vorlagen mit Textfeldern).
     * $Key ist der Schluessel aus dem Vorlagen-Editor ("Symcon-Text") bzw. der
     * {platzhalter} im Text. Wird retained gesetzt und ueberlebt Neustarts;
     * leerer Wert loescht das Feld. Datum, Wochentag und Uhr macht der Player
     * selbst - die muessen von hier nicht gefuellt werden.
     *   FSIG_Text($id, 'Foyer', '1', 'gast', "Andre Freitag
Siegfried Mack"); */
    public function Text(string $Ort, string $Screen, string $Key, string $Wert)
    {
        $r = $this->HttpGet('/api/hook/text?ort=' . rawurlencode($Ort)
            . '&screen=' . rawurlencode($Screen)
            . '&key=' . rawurlencode($Key)
            . '&value=' . rawurlencode($Wert));
        return $this->OkOrLog($r, 'Text ' . $Key . ' -> ' . $Ort . '/' . $Screen);
    }

    public function ThumbUrl(string $Ort, string $Screen)
    {
        return 'http://' . trim($this->ReadPropertyString('Host'))
             . '/api/hook/thumb?ort=' . rawurlencode($Ort)
             . '&screen=' . rawurlencode($Screen)
             . '&token=' . rawurlencode(trim($this->ReadPropertyString('Token')))
             . '&t=' . time();
    }

    /* ================= Intern ================= */

    // Alle Aufrufe laufen als GET mit Query-Parametern - Sys_GetURLContentEx
    // sendet POST-Bodies auf der SymBox nicht zuverlaessig (faellt auf GET
    // zurueck). Der SignageServer bietet fuer jede Aktion eine GET-Route.
    private function HttpGet($path)
    {
        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '' || trim($this->ReadPropertyString('Token')) === '') {
            return array('error' => 'Host oder Token nicht konfiguriert');
        }
        $d = json_decode($this->HttpGetRaw($path, 5000), true);
        if (!is_array($d)) {
            return array('error' => 'SignageServer nicht erreichbar (' . $host . ')');
        }
        return $d;
    }

    /** Rohdaten holen (auch binaer, fuers Vorschaubild) - HttpGet erwartet JSON. */
    private function HttpGetRaw($path, $timeoutMs)
    {
        $host = trim($this->ReadPropertyString('Host'));
        $token = trim($this->ReadPropertyString('Token'));
        if ($host === '' || $token === '') {
            return '';
        }
        $url = 'http://' . $host . $path
             . (strpos($path, '?') === false ? '?' : '&') . 'token=' . rawurlencode($token);
        return (string)@Sys_GetURLContentEx($url, array('Timeout' => $timeoutMs));
    }

    private function OkOrLog($r, $what)
    {
        $ok = isset($r['ok']) && $r['ok'] === true;
        if (!$ok) {
            $msg = isset($r['error']) ? (string)$r['error'] : 'unbekannter Fehler';
            $this->SetValueIfChanged('LastError', $msg);
            $this->LogMessage('CampaignRED Remote: ' . $what . ' fehlgeschlagen: ' . $msg, KL_WARNING);
        } else {
            $this->SendDebug('FSIG', $what . ' OK', 0);
        }
        return $ok;
    }

    /** Nach Aktionen zeitnah den neuen Zustand abholen (Poll() stellt das
     *  regulaere Intervall danach selbst wieder her). */
    private function PollSoon()
    {
        $this->SetTimerInterval('Poll', 2000);
    }

    /** Aktionsskript fuer die Playlist-Variablen der Player-Ordner (einmal je
     *  Instanz, Ident 'PlaylistAction'). Inhalt wird bei Bedarf nachgezogen. */
    private function EnsureActionScript()
    {
        $id = @IPS_GetObjectIDByIdent('PlaylistAction', $this->InstanceID);
        if (!$id) {
            $id = IPS_CreateScript(0);
            IPS_SetParent($id, $this->InstanceID);
            IPS_SetIdent($id, 'PlaylistAction');
            IPS_SetName($id, 'Playlist-Aktion');
            IPS_SetPosition($id, 950);
            IPS_SetHidden($id, true);
        }
        $code = "<?php\n// Von CampaignRED Remote verwaltet: Playlist-Wahl eines Players an die Instanz durchreichen.\n"
              . "IPS_RequestAction(" . $this->InstanceID . ", IPS_GetObject(\$_IPS['VARIABLE'])['ObjectIdent'], (int)\$_IPS['VALUE']);\n";
        if (IPS_GetScriptContent($id) !== $code) {
            IPS_SetScriptContent($id, $code);
        }
        return (int)$id;
    }

    private function PlayerKey($ort, $screen)
    {
        return preg_replace('/[^A-Za-z0-9_]/', '_', $ort . '_' . $screen);
    }

    /** Pro Player einen Ordner (Kategorie) unter der Instanz mit den Variablen
     *  Online (Anzeige) und Playlist (schaltbar). Idempotent - legt nur an,
     *  was fehlt. Liefert die Objekt-IDs zurueck. */
    private function EnsurePlayerFolder($ort, $screen, $profile, $pos)
    {
        $key = $this->PlayerKey($ort, $screen);

        $cat = @IPS_GetObjectIDByIdent('P_' . $key, $this->InstanceID);
        if (!$cat) {
            $cat = IPS_CreateCategory();
            IPS_SetParent($cat, $this->InstanceID);
            IPS_SetIdent($cat, 'P_' . $key);
        }
        IPS_SetName($cat, $ort . ' / ' . $screen);
        IPS_SetPosition($cat, $pos);

        $onl = @IPS_GetObjectIDByIdent('Online', $cat);
        if (!$onl) {
            $onl = IPS_CreateVariable(0);           // 0 = Boolean
            IPS_SetParent($onl, $cat);
            IPS_SetIdent($onl, 'Online');
            IPS_SetName($onl, 'Online');
            IPS_SetVariableCustomProfile($onl, '~Switch');
            IPS_SetPosition($onl, 10);
        }

        // Playlist-Umschalter: eindeutiger Ident (PL_<key>) fuers RequestAction
        $pl = @IPS_GetObjectIDByIdent('PL_' . $key, $cat);
        if (!$pl) {
            $pl = IPS_CreateVariable(1);            // 1 = Integer
            IPS_SetParent($pl, $cat);
            IPS_SetIdent($pl, 'PL_' . $key);
            IPS_SetName($pl, 'Playlist');
            IPS_SetPosition($pl, 20);
        }
        if (IPS_GetVariable($pl)['VariableCustomProfile'] !== $profile) {
            IPS_SetVariableCustomProfile($pl, $profile);
        }
        // Aktion an diese Instanz binden -> RequestAction('PL_<key>', <index>).
        // Symcon 9 nimmt als CustomAction keine Instanz-ID mehr an ("Skript #<id>
        // existiert nicht", bei jedem Poll) - deshalb ein kleines Aktionsskript
        // in der Instanz, das den Wert an RequestAction() der Instanz durchreicht.
        $action = $this->EnsureActionScript();
        if ((int)IPS_GetVariable($pl)['VariableCustomAction'] !== $action) {
            IPS_SetVariableCustomAction($pl, $action);
        }

        // Laufende Folie im Klartext ("angebot.jpg", "Vorlage: Willkommen",
        // "NDI: STUDIO-PC") und ihre Nummer in der Playlist ("3 / 8").
        $sl = $this->EnsureString($cat, 'Folie', 'Folie', 30);
        $po = $this->EnsureString($cat, 'FolieNr', 'Position', 40);

        return array('key' => $key, 'cat' => $cat, 'online' => $onl, 'playlist' => $pl,
            'slide' => $sl, 'position' => $po);
    }

    /** String-Variable im Player-Ordner anlegen, falls sie fehlt. */
    private function EnsureString($cat, $ident, $name, $pos)
    {
        $vid = @IPS_GetObjectIDByIdent($ident, $cat);
        if (!$vid) {
            $vid = IPS_CreateVariable(3);           // 3 = String
            IPS_SetParent($vid, $cat);
            IPS_SetIdent($vid, $ident);
            IPS_SetName($vid, $name);
            IPS_SetPosition($vid, $pos);
        }
        return $vid;
    }

    /** Vorschaubild der laufenden Folie als Medienobjekt (Bild) im Player-
     *  Ordner - in Kachel, WebFront und IPSView direkt anzeigbar. Geladen wird
     *  nur bei Folienwechsel bzw. beim ersten Anlegen. */
    private function UpdatePreview($cat, $key, $ort, $screen, $gewechselt)
    {
        $mid = @IPS_GetObjectIDByIdent('Vorschau', $cat);
        if (!$mid) {
            $mid = IPS_CreateMedia(1);              // 1 = Bild
            IPS_SetParent($mid, $cat);
            IPS_SetIdent($mid, 'Vorschau');
            IPS_SetName($mid, 'Vorschau');
            IPS_SetPosition($mid, 50);
            IPS_SetMediaFile($mid, 'media/fsig_' . $this->InstanceID . '_' . $key . '.jpg', false);
            $gewechselt = true;                     // frisch angelegt -> Bild holen
        }
        if (!$gewechselt) {
            return;
        }
        // Nur echte JPEGs uebernehmen: liefert der Server keins (Video ohne
        // ffmpeg, Vorlage ohne Bild), antwortet er mit JSON - dann soll das
        // letzte gute Bild stehen bleiben statt eines kaputten.
        $bild = $this->HttpGetRaw('/api/hook/thumb?ort=' . rawurlencode($ort)
            . '&screen=' . rawurlencode($screen), 8000);
        if (strncmp($bild, "\xFF\xD8", 2) === 0) {
            IPS_SetMediaContent($mid, base64_encode($bild));
            IPS_SendMediaEvent($mid);
        }
    }

    /** Entfernt Player-Ordner (Ident P_*), die der Server nicht mehr meldet.
     *  Beruehrt ausschliesslich vom Modul erzeugte Kategorien - die eigenen
     *  Status-Variablen (Online, Emergency, ...) haben keinen P_-Ident. */
    private function PruneOrphans($keep)
    {
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $cid) {
            $obj = IPS_GetObject($cid);
            if ($obj['ObjectType'] != 0) {   // 0 = Kategorie
                continue;
            }
            $ident = $obj['ObjectIdent'];
            if (strncmp($ident, 'P_', 2) !== 0 || isset($keep[$ident])) {
                continue;
            }
            $this->DeleteTree($cid);   // verwaister Player -> weg
        }
    }

    /** Loescht ein Objekt samt Unterobjekten (Kinder zuerst). */
    private function DeleteTree($id)
    {
        foreach (IPS_GetChildrenIDs($id) as $cid) {
            $this->DeleteTree($cid);
        }
        switch (IPS_GetObject($id)['ObjectType']) {
            case 0: IPS_DeleteCategory($id); break;   // Kategorie
            case 2: IPS_DeleteVariable($id); break;   // Variable
            case 3: IPS_DeleteScript($id, false); break;
            case 4: IPS_DeleteEvent($id); break;
            case 5: IPS_DeleteMedia($id, true); break;
            case 6: IPS_DeleteLink($id); break;
            // Typ 1 (Instanz) bewusst nicht loeschen
        }
    }

    /** Instanz-eigenes Integer-Profil mit den Playlist-Namen als Assoziationen.
     *  Nur neu aufbauen, wenn sich die Liste geaendert hat (kein Kernel-Spam). */
    private function ProfileName()
    {
        return 'FSIG.' . $this->InstanceID . '.PL';
    }
    private function SyncPlaylistProfile($names, $labels = null)
    {
        if (!is_array($labels) || count($labels) !== count($names)) {
            $labels = $names;   // ohne Klarnamen bleibt der Schluessel stehen
        }
        $profile = $this->ProfileName();
        // Wichtig: bei Modul-Reload loescht Destroy() das Profil, der Merker
        // ueberlebt aber. Ein frisch erzeugtes Profil daher IMMER befuellen,
        // sonst bliebe es leer ("Profile nicht befuellt").
        $fresh = !IPS_VariableProfileExists($profile);
        if ($fresh) {
            IPS_CreateVariableProfile($profile, VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileIcon($profile, 'Database');
        }
        if (count($names) === 0) {
            return $profile;   // keine Playlists bekannt -> altes Profil unangetastet lassen
        }
        // Der Vergleich muss BEIDES umfassen: wird eine Playlist im Hub nur
        // umbenannt, aendern sich die Schluessel nicht - das Profil bliebe
        // sonst auf dem alten Namen stehen.
        $stored = json_encode(array(
            json_decode($this->ReadAttributeString('PlaylistNames'), true),
            json_decode($this->ReadAttributeString('PlaylistLabels'), true)
        ));
        if ($fresh || $stored !== json_encode(array($names, $labels))) {
            for ($i = 0; $i < count($names); $i++) {
                IPS_SetVariableProfileAssociation($profile, $i, (string)$labels[$i], '', -1);
            }
            // frueher laengere Liste: ueberzaehlige Assoziationen leeren
            $prev = (int)$this->ReadAttributeInteger('PlaylistCount');
            for ($i = count($names); $i < $prev; $i++) {
                IPS_SetVariableProfileAssociation($profile, $i, '', '', -1);
            }
            $this->WriteAttributeString('PlaylistNames', json_encode($names));
            $this->WriteAttributeString('PlaylistLabels', json_encode($labels));
            $this->WriteAttributeInteger('PlaylistCount', count($names));
        }
        return $profile;
    }

    /** Wie SetValueIfChanged, aber fuer Variablen in den Player-Ordnern -
     *  die haben keinen Ident auf Instanz-Ebene, nur eine Objekt-ID. */
    private function SetObjIfChanged($vid, $value)
    {
        if (!$vid || GetValue($vid) === $value) {
            return;
        }
        SetValue($vid, $value);
    }

    private function SetValueIfChanged($ident, $value)
    {
        $vid = @$this->GetIDForIdent($ident);
        if ($vid === 0 || $vid === false) {
            return;
        }
        if (GetValue($vid) === $value) {
            return;
        }
        $this->SetValue($ident, $value);
    }

    /** Erreichbarkeit des Webservers pruefen (kurzlebiger TCP-Connect). */
    private function TestTcp($Host, $Port, $ConnectTimeoutMs)
    {
        $errno = 0;
        $errstr = '';
        $fp = @fsockopen($Host, $Port, $errno, $errstr, max(0.05, $ConnectTimeoutMs / 1000.0));
        if (is_resource($fp)) {
            fclose($fp);
            return true;
        }
        return false;
    }

    private function Lock()
    {
        return @IPS_SemaphoreEnter('FSIG_' . $this->InstanceID, 200);
    }

    private function Unlock()
    {
        @IPS_SemaphoreLeave('FSIG_' . $this->InstanceID);
    }
}
