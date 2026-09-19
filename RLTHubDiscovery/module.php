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
        $this->RegisterAttributeString('ScanSummary', '');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
    }

    public function GetConfigurationForm()
    {
        $prefix = $this->guessLocalSubnetPrefix();
        $start = $this->ReadPropertyString('ScanStartIP');
        $end   = $this->ReadPropertyString('ScanEndIP');
        $hint  = $prefix !== ''
            ? 'Leer = ' . $prefix . '.1 bis ' . $prefix . '.254 (aus dem eigenen Netz abgeleitet).'
            : 'Start- und End-IP-Adresse eintragen.';

        $rows = json_decode($this->ReadAttributeString('ResultsJSON'), true);
        $values = $this->buildValues(is_array($rows) ? $rows : []);

        $form = [
            'elements' => [
                ['type' => 'Label', 'caption' => 'RLTHub Suche — Lüftungsanlagen (RLT/KWL) per Modbus TCP finden. Die Suche liest nur, sie schreibt nichts auf die Geräte.'],
                ['type' => 'Label', 'caption' => 'ℹ️ Ein Fund heißt: An den Registern des jeweiligen Gerätetyps stehen plausible Temperaturen. Steuerungen wie Robatherm TrueControl haben kein festes Erkennungsmerkmal — bitte jeden Vorschlag prüfen.'],
                ['type' => 'ValidationTextBox', 'name' => 'ScanStartIP', 'caption' => 'Start-IP-Adresse', 'validate' => '^$|^\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}$'],
                ['type' => 'ValidationTextBox', 'name' => 'ScanEndIP', 'caption' => 'End-IP-Adresse', 'validate' => '^$|^\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}$'],
                ['type' => 'Label', 'caption' => $hint],
                ['type' => 'NumberSpinner', 'name' => 'ScanPort', 'caption' => 'TCP-Port', 'minimum' => 1, 'maximum' => 65535],
                ['type' => 'NumberSpinner', 'name' => 'ScanUnitId', 'caption' => 'Unit ID', 'minimum' => 1, 'maximum' => 247],
                [
                    'type'    => 'Button',
                    'caption' => '🔎  Netzwerk durchsuchen',
                    'onClick' => 'echo RLTD_Discover($id, $ScanStartIP, $ScanEndIP, $ScanPort, $ScanUnitId);',
                ],
                ['type' => 'Label', 'name' => 'ScanResult', 'caption' => $this->ReadAttributeString('ScanSummary')],
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
                [
                    'type'     => 'ExpansionPanel',
                    'caption'  => '🔌 RS485 / Modbus RTU (z. B. Proxon FWT)',
                    'expanded' => false,
                    'items'    => [
                        ['type' => 'Label', 'caption' => 'RTU-Geräte sind im Netzwerk nicht auffindbar. Anlegen: Instanz „RLTHubGateway" erstellen und dort oben unter „Gateway" das ModBus-Gateway wählen (Serial Port → ModBus Gateway, Modus RTU).'],
                        ['type' => 'Label', 'caption' => $this->gatewaySummary()],
                    ],
                ],
            ],
            'actions' => [],
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
                return $this->finish('❌ Start- und End-IP-Adresse eintragen (das eigene Netz ließ sich nicht ermitteln).', []);
            }
            $StartIP = $prefix . '.1';
            $EndIP   = $prefix . '.254';
            $note    = ' (Bereich aus dem eigenen Netz abgeleitet: ' . $StartIP . ' – ' . $EndIP . ')';
        }
        $ips = $this->expandRange($StartIP, $EndIP);
        if (count($ips) === 0) {
            return $this->finish('❌ Ungültiger IP-Bereich.', []);
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
        $text = '✅ ' . count($ips) . ' Adressen geprüft, ' . count($open) . ' mit offenem Port ' . $Port . ', ' . count($rows) . ' Lüftungsanlage(n) erkannt' . $note . '.';
        return $this->finish($text, $rows);
    }

    private function finish(string $text, array $rows): string
    {
        $this->WriteAttributeString('ResultsJSON', json_encode($rows));
        $this->WriteAttributeString('ScanSummary', $text);
        $this->UpdateFormField('ScanResult', 'caption', $text);
        $this->UpdateFormField('Configurator', 'values', json_encode($this->buildValues($rows)));
        return $text;
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
