<?php

require_once __DIR__ . '/../libs/RLTCore.php';

// NRG-Stack RLTHubDiscovery — sucht Lüftungsanlagen im Netzwerk und legt
// RLTHub-Instanzen an.
//
// Modbus TCP: durchsucht einen IP-Bereich nach offenem Port (parallel, nicht
// blockierend) und fragt bei jedem Treffer die Register jedes TCP-Treibers
// rein lesend ab. Ein Fund heißt „an den Registern dieses Treibers stehen
// plausible Temperaturen" — mehr nicht: RLT-Steuerungen wie TrueControl
// haben kein festes Erkennungsmerkmal, die Register werden je Projekt
// vergeben. Jeder Fund ist deshalb ein Vorschlag, den der Nutzer prüft.
//
// Modbus RTU (RS485): nicht per Netzwerksuche auffindbar. Hier werden nur die
// vorhandenen ModBus-Gateways und RLTHubGateway-Instanzen aufgelistet;
// RLTHubGateway wird manuell angelegt und mit dem Gateway verbunden (eine
// Configurator-Zeile kann laut Modul-Doku keine Verbindung zu einem
// bestehenden Parent herstellen, daher bewusst kein Anlegen-Knopf).
class RLTHubDiscovery extends IPSModule
{
    use RLT_PanelTrait;

    public const PREFIX = 'RLTD';
    public const MODULE_NAME = 'RLTHubDiscovery';
    public const MODULE_GUID = '{CC2C38CA-7675-4F27-B993-7B68187B8879}';

    public const NEWS_VERSION = '0.3.0';
    public const FORUM_THREAD_URL = 'https://community.symcon.de/t/modul-nrg-stack-rlthub-lueftungsanlagen-rlt-kwl-per-modbus-anbinden-robatherm-truecontrol-proxon-fwt/144442';
    public const LICENSE_URL = 'https://github.com/DG65/NRGRLTHub/blob/beta/LICENSE';
    public const PURPOSE = [
        'Durchsucht dein lokales Netz nach Lüftungsanlagen mit Modbus TCP und legt auf Klick eine vorausgefüllte RLTHub-Instanz an — IP-Adresse, Port, Unit-ID und Gerätetyp müssen nicht von Hand eingetragen werden.',
        'Der Nutzen: bei mehreren Anlagen ein paar Klicks statt manueller Konfiguration jeder einzelnen Instanz. Ein Fund heißt nur, dass an den Registern des jeweiligen Gerätetyps plausible Temperaturen stehen — bitte jeden Vorschlag prüfen.',
        'Das Modul selbst misst nichts — die eigentliche Datenerfassung übernimmt danach die neu angelegte RLTHub-Instanz. Geräte mit RS485/Modbus RTU sind im Netzwerk nicht auffindbar, dafür gibt es RLTHubGateway.',
    ];
    public const NEWS = [
        '• 🆕 Erste Version: Netzwerksuche nach Lüftungsanlagen mit Modbus TCP (Robatherm TrueControl). Die Suche liest nur und schreibt nichts auf die Geräte.',
        '• ⚠️ Noch an keiner echten Anlage verifiziert. Steuerungen wie TrueControl haben kein festes Erkennungsmerkmal — ein Fund ist ein Vorschlag, den man prüfen sollte.',
    ];

    private const HUB_GUID            = '{19C33A5B-8C10-45F5-9A33-9928D9005B69}';
    private const GATEWAY_HUB_GUID    = '{1AE15A54-8ECC-4599-961B-447BD8671715}';
    private const MODBUS_GATEWAY_GUID = '{A5F663AB-C400-4FE5-B207-4D67CC030564}';
    private const MAX_HOSTS           = 1024;

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('ScanStartIP', '');
        $this->RegisterPropertyString('ScanEndIP', '');
        $this->RegisterPropertyInteger('ScanPort', 502);
        $this->RegisterPropertyInteger('ScanUnitId', 1);
        $this->RegisterAttributeString('ResultsJSON', '[]');
        // Status-Kopfzeile (SUITE.md „Einheitliche Verbund-Status-Kopfzeile").
        $this->RegisterAttributeInteger('LastScanTs', 0);
        $this->RegisterAttributeString('ScanDetails', '');
        $this->rltPanelCreate();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->rltAdoptDismissFromSibling();
    }

    // Eine Zeile: Symbol, Kernzahl, Zeitstempel der letzten Suche.
    private function ScanSummaryLine(): string
    {
        $ts = (int)$this->ReadAttributeInteger('LastScanTs');
        if ($ts === 0) {
            return 'ℹ️ Noch nicht gesucht — Schaltfläche oben drücken.';
        }
        $rows = json_decode((string)$this->ReadAttributeString('ResultsJSON'), true);
        $count = is_array($rows) ? count($rows) : 0;
        return sprintf('%s %d Lüftungsanlage(n) gefunden (zuletzt %s Uhr).', $count > 0 ? '✅' : '⚠️', $count, date('H:i:s', $ts));
    }

    public function GetConfigurationForm()
    {
        $prefix = $this->guessLocalSubnetPrefix();
        $hint  = $prefix !== ''
            ? 'Leer = ' . $prefix . '.1 bis ' . $prefix . '.254 (aus dem eigenen Netz abgeleitet).'
            : 'Start- und End-IP-Adresse eintragen.';

        $rows = json_decode((string)$this->ReadAttributeString('ResultsJSON'), true);
        $values = $this->buildValues(is_array($rows) ? $rows : []);

        $libraryInfo = @json_decode((string)@file_get_contents(__DIR__ . '/../library.json'), true);
        $version = (is_array($libraryInfo) && isset($libraryInfo['version'])) ? (string)$libraryInfo['version'] : '?';

        $doku = [
            'type'     => 'ExpansionPanel',
            'caption'  => '📖 Dokumentation & Hilfe',
            'expanded' => false,
            'items'    => [
                ['type' => 'Label', 'caption' => static::MODULE_NAME . ' Version ' . $version . ' — noch an keiner echten Anlage verifiziert.'],
                ['type' => 'Label', 'caption' => 'Ablauf: Suchbereich prüfen, „Netzwerk durchsuchen" drücken, in der Fundliste die gewünschte Anlage markieren und „Erstellen" wählen. Die neue RLTHub-Instanz ist vorausgefüllt und muss nur noch geprüft und aktiv geschaltet werden.'],
                ['type' => 'Label', 'caption' => 'Die Suche prüft, welche Adressen den Modbus-Port offen haben, und liest dort nur lesend die Register jedes unterstützten Gerätetyps. Ein Fund heißt: An diesen Registern stehen plausible Temperaturen. Steuerungen wie Robatherm TrueControl haben kein festes Erkennungsmerkmal — bitte jeden Vorschlag prüfen.'],
                ['type' => 'Label', 'caption' => 'Ein Gerät, das überall 0 liefert, oder eines, das auf die Register mit einer Modbus-Fehlermeldung antwortet, gilt nicht als Fund.'],
            ],
        ];

        $fach = [
            [
                'type'     => 'ExpansionPanel',
                'caption'  => '🔎 Suchbereich',
                'expanded' => true,
                'items'    => [
                    ['type' => 'ValidationTextBox', 'name' => 'ScanStartIP', 'caption' => 'Start-IP-Adresse', 'validate' => '^$|^\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}$'],
                    ['type' => 'ValidationTextBox', 'name' => 'ScanEndIP', 'caption' => 'End-IP-Adresse', 'validate' => '^$|^\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}$'],
                    ['type' => 'Label', 'caption' => 'ℹ️ ' . $hint],
                    ['type' => 'NumberSpinner', 'name' => 'ScanPort', 'caption' => 'TCP-Port', 'minimum' => 1, 'maximum' => 65535],
                    ['type' => 'NumberSpinner', 'name' => 'ScanUnitId', 'caption' => 'Unit ID', 'minimum' => 1, 'maximum' => 247],
                    // Schaltfläche zuerst, direkt darunter die eine Kopfzeile (SUITE.md).
                    [
                        'type'    => 'Button',
                        'caption' => '🔎  Netzwerk durchsuchen',
                        'onClick' => 'echo RLTD_Discover($id, $ScanStartIP, $ScanEndIP, $ScanPort, $ScanUnitId);',
                    ],
                    ['type' => 'Label', 'name' => 'ScanResult', 'caption' => $this->ScanSummaryLine()],
                    [
                        'type'     => 'ExpansionPanel',
                        'caption'  => 'Details der letzten Suche',
                        'expanded' => false,
                        'items'    => [
                            ['type' => 'Label', 'name' => 'ScanDetails', 'caption' => (string)$this->ReadAttributeString('ScanDetails') !== '' ? (string)$this->ReadAttributeString('ScanDetails') : 'Noch nicht gesucht.'],
                        ],
                    ],
                ],
            ],
            [
                'type'     => 'ExpansionPanel',
                'caption'  => '📋 Fundliste',
                'expanded' => true,
                'items'    => [
                    [
                        'type'     => 'Configurator',
                        'name'     => 'Configurator',
                        'caption'  => 'Gefundene Lüftungsanlagen',
                        'rowCount' => 6,
                        'delete'   => false,
                        'sort'     => ['column' => 'address', 'direction' => 'ascending'],
                        'columns'  => [
                            ['caption' => 'Adresse', 'name' => 'address', 'width' => '150px'],
                            ['caption' => 'Gerätetyp', 'name' => 'device', 'width' => '230px'],
                            ['caption' => 'Fund', 'name' => 'detail', 'width' => 'auto'],
                        ],
                        'values'   => $values,
                    ],
                ],
            ],
            [
                'type'     => 'ExpansionPanel',
                'caption'  => '🔌 RS485 / Modbus RTU (z. B. Proxon FWT)',
                'expanded' => false,
                'items'    => [
                    ['type' => 'Label', 'caption' => 'RTU-Geräte sind im Netzwerk nicht auffindbar. Anlegen: Instanz „RLTHubGateway" erstellen und dort oben unter „Gateway" das ModBus-Gateway wählen (Serial Port → ModBus Gateway, Modus RTU).'],
                    ['type' => 'Label', 'caption' => $this->gatewaySummary()],
                ],
            ],
        ];

        $form = [
            'elements' => array_merge($this->rltPanelsTop(), [$doku], $fach, $this->rltPanelsBottom()),
            'actions'  => [],
        ];
        return json_encode($form);
    }

    public function Discover(string $StartIP, string $EndIP, int $Port, int $UnitId): string
    {
        $StartIP = trim($StartIP);
        $EndIP   = trim($EndIP);
        $note    = '';
        if ($StartIP === '' || $EndIP === '') {
            $prefix = $this->guessLocalSubnetPrefix();
            if ($prefix === '') {
                return '❌ Start- und End-IP-Adresse eintragen (das eigene Netz ließ sich nicht ermitteln).';
            }
            $StartIP = $prefix . '.1';
            $EndIP   = $prefix . '.254';
            $note    = ' (Bereich aus dem eigenen Netz abgeleitet: ' . $StartIP . ' – ' . $EndIP . ')';
        }
        $ips = $this->expandRange($StartIP, $EndIP);
        if (count($ips) === 0) {
            return '❌ Ungültiger IP-Bereich.';
        }
        if (count($ips) > self::MAX_HOSTS) {
            $ips = array_slice($ips, 0, self::MAX_HOSTS);
            $note .= ' — auf ' . self::MAX_HOSTS . ' Adressen begrenzt';
        }

        $open = $this->scanPortOpen($ips, $Port, 4.0);
        $rows = [];
        foreach ($open as $host) {
            foreach (RLT_Drivers::DRIVERS as $key => $def) {
                if (!in_array('tcp', $def['transports'], true)) {
                    continue;
                }
                $mb = new RLT_ModbusTcpClient($host, $Port, $UnitId);
                $found = RLT_Drivers::create($key)->probe($mb);
                $mb->close();
                if ($found !== null) {
                    $rows[] = ['host' => $host, 'port' => $Port, 'unit' => $UnitId, 'device' => $key, 'detail' => $found];
                }
            }
        }
        $details = count($ips) . ' Adressen geprüft, ' . count($open) . ' mit offenem Port ' . $Port . ', ' . count($rows) . ' Lüftungsanlage(n) erkannt' . $note . '.';
        return $this->finish($details, $rows);
    }

    // Zeitstempel bei JEDER Suche fortschreiben (auch bei 0 Funden), Kopfzeile
    // und Fundliste gemeinsam auffrischen — erst speichern, dann anzeigen.
    private function finish(string $details, array $rows): string
    {
        $this->WriteAttributeString('ResultsJSON', json_encode($rows));
        $this->WriteAttributeString('ScanDetails', $details);
        $this->WriteAttributeInteger('LastScanTs', time());
        $summary = $this->ScanSummaryLine();
        $this->UpdateFormField('ScanResult', 'caption', $summary);
        $this->UpdateFormField('ScanDetails', 'caption', $details);
        $this->UpdateFormField('Configurator', 'values', json_encode($this->buildValues($rows)));
        return $summary . "\n" . $details;
    }

    private function buildValues(array $rows): array
    {
        $values = [];
        foreach ($rows as $r) {
            $caption = RLT_Drivers::DRIVERS[$r['device']]['caption'] ?? $r['device'];
            $row = [
                'address' => $r['host'],
                'device'  => $caption,
                'detail'  => $r['detail'],
                'create'  => [
                    'moduleID'      => self::HUB_GUID,
                    'configuration' => [
                        'Host'   => $r['host'],
                        'Port'   => (int)$r['port'],
                        'UnitId' => (int)$r['unit'],
                        'Device' => $r['device'],
                    ],
                    'name'          => $caption . ' (' . $r['host'] . ')',
                ],
            ];
            $existing = $this->findExistingInstance($r['host'], (int)$r['port'], (int)$r['unit']);
            if ($existing > 0) {
                $row['instanceID'] = $existing;
            }
            $values[] = $row;
        }
        return $values;
    }

    private function findExistingInstance(string $host, int $port, int $unit): int
    {
        foreach (IPS_GetInstanceListByModuleID(self::HUB_GUID) as $iid) {
            if (IPS_GetProperty($iid, 'Host') === $host
                && (int)IPS_GetProperty($iid, 'Port') === $port
                && (int)IPS_GetProperty($iid, 'UnitId') === $unit) {
                return $iid;
            }
        }
        return 0;
    }

    private function gatewaySummary(): string
    {
        $lines = [];
        foreach (IPS_GetInstanceListByModuleID(self::MODBUS_GATEWAY_GUID) as $iid) {
            $lines[] = '• ModBus-Gateway „' . IPS_GetName($iid) . '" (#' . $iid . ', Geräte-ID ' . @IPS_GetProperty($iid, 'DeviceID') . ')';
        }
        foreach (IPS_GetInstanceListByModuleID(self::GATEWAY_HUB_GUID) as $iid) {
            $conn = (int)(@IPS_GetInstance($iid)['ConnectionID'] ?? 0);
            $lines[] = '• RLTHubGateway „' . IPS_GetName($iid) . '" (#' . $iid . ') ' . ($conn > 0 ? 'verbunden mit #' . $conn : 'ohne Gateway');
        }
        return count($lines) > 0 ? implode("\n", $lines) : 'Keine ModBus-Gateways und keine RLTHubGateway-Instanzen gefunden.';
    }

    private function guessLocalSubnetPrefix(): string
    {
        $ip = @gethostbyname(gethostname());
        if ($ip === false || $ip === gethostname()) {
            return '';
        }
        $parts = explode('.', $ip);
        if (count($parts) !== 4) {
            return '';
        }
        $isPrivate = ($parts[0] === '10')
            || ($parts[0] === '192' && $parts[1] === '168')
            || ($parts[0] === '172' && (int)$parts[1] >= 16 && (int)$parts[1] <= 31);
        return $isPrivate ? $parts[0] . '.' . $parts[1] . '.' . $parts[2] : '';
    }

    private function expandRange(string $startIp, string $endIp): array
    {
        $start = ip2long($startIp);
        $end   = ip2long($endIp);
        if ($start === false || $end === false || $start > $end) {
            return [];
        }
        $ips = [];
        for ($i = $start; $i <= $end && count($ips) <= self::MAX_HOSTS; $i++) {
            $ips[] = long2ip($i);
        }
        return $ips;
    }

    // Nicht-blockierender Parallel-Portscan (Vorbild MeterHubDiscovery).
    private function scanPortOpen(array $ips, int $port, float $timeoutSec): array
    {
        $pending = [];
        foreach ($ips as $ip) {
            $s = @stream_socket_client("tcp://$ip:$port", $errno, $errstr, 0.01, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
            if ($s !== false) {
                stream_set_blocking($s, false);
                $pending[$ip] = $s;
            }
        }
        $open = [];
        $deadline = microtime(true) + $timeoutSec;
        while (count($pending) > 0 && microtime(true) < $deadline) {
            $write  = array_values($pending);
            $read   = [];
            $except = [];
            if (@stream_select($read, $write, $except, 0, 200000) === false) {
                break;
            }
            foreach ($pending as $ip => $sock) {
                if (in_array($sock, $write, true)) {
                    if (@stream_socket_get_name($sock, true) !== false) {
                        $open[] = $ip;
                    }
                    fclose($sock);
                    unset($pending[$ip]);
                }
            }
        }
        foreach ($pending as $sock) {
            @fclose($sock);
        }
        return $open;
    }
}
