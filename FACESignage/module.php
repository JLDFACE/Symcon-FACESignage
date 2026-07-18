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

        // Variables
        $this->RegisterVariableBoolean('Online', 'SignageServer erreichbar', '~Switch', 10);
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
            $st = $this->HttpCall('/api/hook/status', null);
            $online = !isset($st['error']) && isset($st['players']);
            $this->SetValueIfChanged('Online', $online);
            if (!$online) {
                $this->SetValueIfChanged('LastError', isset($st['error']) ? (string)$st['error'] : 'keine Antwort');
                return;
            }
            $this->SetValueIfChanged('LastError', '');

            $em = isset($st['emergency']) ? $st['emergency'] : null;
            $this->SetValueIfChanged('Emergency', $em !== null);
            $this->SetValueIfChanged('EmergencyPlaylist', $em !== null ? (string)$em : '');

            // eine String-Variable je Player: laufende Playlist (oder offline)
            $pos = 100;
            foreach ($st['players'] as $p) {
                $ident = $this->PlayerIdent((string)$p['location'], (string)$p['screen']);
                $this->MaintainVariable($ident, $p['location'] . ' / ' . $p['screen'],
                    VARIABLETYPE_STRING, '', $pos, true);
                $value = ((bool)$p['online'])
                    ? (isset($p['playlist']) && $p['playlist'] !== null ? (string)$p['playlist'] : '-')
                    : 'offline';
                $this->SetValueIfChanged($ident, $value);
                $pos += 10;
            }
        } finally {
            // regulaeres Intervall wiederherstellen (PollSoon setzt kurzzeitig 2 s)
            $this->SetTimerInterval('Poll', max(5, (int)$this->ReadPropertyInteger('PollInterval')) * 1000);
            $this->Unlock();
        }
    }

    /** Playlist eines Players wechseln. */
    public function Playlist(string $Ort, string $Screen, string $Name)
    {
        $q = '/api/hook/playlist?ort=' . rawurlencode($Ort) . '&screen=' . rawurlencode($Screen)
           . '&name=' . rawurlencode($Name);
        $r = $this->HttpCall($q, null);
        $this->PollSoon();
        return $this->OkOrLog($r, 'Playlist ' . $Name . ' -> ' . $Ort . '/' . $Screen);
    }

    /** Einzelbild sofort anzeigen. */
    public function Bild(string $Ort, string $Screen, string $Datei)
    {
        $r = $this->HttpCall('/api/hook/command', array(
            'location' => $Ort, 'screen' => $Screen,
            'payload' => array('asset' => array('type' => 'image', 'src' => $Datei))
        ));
        return $this->OkOrLog($r, 'Bild ' . $Datei . ' -> ' . $Ort . '/' . $Screen);
    }

    /** Einzelvideo sofort abspielen. */
    public function Video(string $Ort, string $Screen, string $Datei)
    {
        $r = $this->HttpCall('/api/hook/command', array(
            'location' => $Ort, 'screen' => $Screen,
            'payload' => array('asset' => array('type' => 'video', 'src' => $Datei))
        ));
        return $this->OkOrLog($r, 'Video ' . $Datei . ' -> ' . $Ort . '/' . $Screen);
    }

    /** Notfall ausloesen. Leerer String = im SignageServer geflaggte Playlist. */
    public function Notfall(string $Playlist)
    {
        $body = ($Playlist !== '') ? array('playlist' => $Playlist) : array('start' => true);
        $r = $this->HttpCall('/api/hook/emergency', $body);
        $this->PollSoon();
        return $this->OkOrLog($r, 'NOTFALL' . ($Playlist !== '' ? ' (' . $Playlist . ')' : ''));
    }

    /** Notfall beenden, vorherigen Zustand aller Player wiederherstellen. */
    public function NotfallEnde()
    {
        $r = $this->HttpCall('/api/hook/emergency', array('end' => true));
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

    private function HttpCall($path, $json)
    {
        $host = trim($this->ReadPropertyString('Host'));
        $token = trim($this->ReadPropertyString('Token'));
        if ($host === '' || $token === '') {
            return array('error' => 'Host oder Token nicht konfiguriert');
        }
        $url = 'http://' . $host . $path
             . (strpos($path, '?') === false ? '?' : '&') . 'token=' . rawurlencode($token);
        $opts = array('Timeout' => 5000);
        if ($json !== null) {
            $opts['method'] = 'POST';
            $opts['content'] = json_encode($json);
            $opts['header'] = 'Content-Type: application/json';
        }
        $res = @Sys_GetURLContentEx($url, $opts);
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

    private function Lock()
    {
        return @IPS_SemaphoreEnter('FSIG_' . $this->InstanceID, 200);
    }

    private function Unlock()
    {
        @IPS_SemaphoreLeave('FSIG_' . $this->InstanceID);
    }
}
