<?php

/*
 * FACE Signage - IP-Symcon Modul
 *
 * Steuert den FACE SignageServer (Raspberry Pi) ueber dessen REST-API.
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

        // Attribute (persistent): Player-Zuordnung + Stand des Playlist-Profils
        $this->RegisterAttributeString('PlayerMap', '{}');    // ident -> {ort, screen}
        $this->RegisterAttributeString('PlaylistNames', '[]'); // Reihenfolge im Profil
        $this->RegisterAttributeInteger('PlaylistCount', 0);   // fuer Profil-Aufraeumen

        // Variables
        $this->RegisterVariableBoolean('Online', 'Online', '~Switch', 10);
        $this->DisableAction('Online');

        $this->RegisterVariableBoolean('Emergency', 'Notfall', '~Switch', 20);
        $this->EnableAction('Emergency');

        $this->RegisterVariableString('EmergencyPlaylist', 'Aktive Notfall-Playlist', '', 30);
        $this->RegisterVariableString('LastError', 'Letzter Fehler', '~TextBox', 900);

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
                $this->SetValue($Ident, $i);   // optimistisch, Poll bestaetigt
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

            // Playlist-Profil aktuell halten (Auswahlliste zum Umschalten)
            $pl = $this->HttpGet('/api/hook/playlists');
            $names = (isset($pl['playlists']) && is_array($pl['playlists'])) ? array_values($pl['playlists']) : array();
            $profile = $this->SyncPlaylistProfile($names);

            // je Player eine schaltbare Integer-Variable (aktuelle Playlist)
            $pos = 100;
            $map = array();
            foreach ($st['players'] as $p) {
                $ident = $this->PlayerIdent((string)$p['location'], (string)$p['screen']);
                $this->MaintainVariable($ident, $p['location'] . ' / ' . $p['screen'],
                    VARIABLETYPE_INTEGER, $profile, $pos, true);
                $this->EnableAction($ident);
                $running = (isset($p['playlist']) && $p['playlist'] !== null) ? (string)$p['playlist'] : '';
                $idx = array_search($running, $names, true);
                if ($idx !== false) {
                    $this->SetValueIfChanged($ident, (int)$idx);
                }
                $map[$ident] = array('ort' => (string)$p['location'], 'screen' => (string)$p['screen']);
                $pos += 10;
            }
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
        $token = trim($this->ReadPropertyString('Token'));
        if ($host === '' || $token === '') {
            return array('error' => 'Host oder Token nicht konfiguriert');
        }
        $url = 'http://' . $host . $path
             . (strpos($path, '?') === false ? '?' : '&') . 'token=' . rawurlencode($token);
        $res = @Sys_GetURLContentEx($url, array('Timeout' => 5000));
        $d = json_decode((string)$res, true);
        if (!is_array($d)) {
            return array('error' => 'SignageServer nicht erreichbar (' . $host . ')');
        }
        return $d;
    }

    private function OkOrLog($r, $what)
    {
        $ok = isset($r['ok']) && $r['ok'] === true;
        if (!$ok) {
            $msg = isset($r['error']) ? (string)$r['error'] : 'unbekannter Fehler';
            $this->SetValueIfChanged('LastError', $msg);
            $this->LogMessage('FACE Signage: ' . $what . ' fehlgeschlagen: ' . $msg, KL_WARNING);
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

    private function PlayerIdent($ort, $screen)
    {
        return 'PL_' . preg_replace('/[^A-Za-z0-9_]/', '_', $ort . '_' . $screen);
    }

    /** Instanz-eigenes Integer-Profil mit den Playlist-Namen als Assoziationen.
     *  Nur neu aufbauen, wenn sich die Liste geaendert hat (kein Kernel-Spam). */
    private function ProfileName()
    {
        return 'FSIG.' . $this->InstanceID . '.PL';
    }
    private function SyncPlaylistProfile($names)
    {
        $profile = $this->ProfileName();
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileIcon($profile, 'Database');
        }
        // nur bei Aenderung neu schreiben
        if (json_encode(json_decode($this->ReadAttributeString('PlaylistNames'), true)) !== json_encode($names)) {
            for ($i = 0; $i < count($names); $i++) {
                IPS_SetVariableProfileAssociation($profile, $i, (string)$names[$i], '', -1);
            }
            // frueher laengere Liste: ueberzaehlige Assoziationen leeren
            $prev = (int)$this->ReadAttributeInteger('PlaylistCount');
            for ($i = count($names); $i < $prev; $i++) {
                IPS_SetVariableProfileAssociation($profile, $i, '', '', -1);
            }
            $this->WriteAttributeString('PlaylistNames', json_encode($names));
            $this->WriteAttributeInteger('PlaylistCount', count($names));
        }
        return $profile;
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
