<?php

require_once __DIR__ . '/../libs/RLTCore.php';

// NRG-Stack RLTHub — Raumlufttechnische Anlagen (RLT/KWL) über Modbus TCP.
// Geräte mit RS485/Modbus RTU (z. B. Proxon FWT) laufen über das Schwestermodul
// RLTHubGateway, das Symcons natives ModBus-Gateway als Parent nutzt.
// Gemeinsame Logik: libs/RLTCore.php (RLT_HubTrait).
class RLTHub extends IPSModule
{
    use RLT_HubTrait;

    public const PREFIX = 'RLT';
    public const MODULE_NAME = 'RLTHub';
    public const MODULE_GUID = '{19C33A5B-8C10-45F5-9A33-9928D9005B69}';
    public const DEFAULT_DEVICE = 'robatherm_truecontrol';
    public const TRANSPORT = 'tcp';

    public const NEWS_VERSION = '0.4.1';
    public const FORUM_THREAD_URL = 'https://community.symcon.de/t/modul-nrg-stack-rlthub-lueftungsanlagen-rlt-kwl-per-modbus-anbinden-robatherm-truecontrol-proxon-fwt/144442';
    public const LICENSE_URL = 'https://github.com/DG65/NRGRLTHub/blob/beta/LICENSE';
    public const PURPOSE = [
        'Liest eine Lüftungsanlage (RLT/KWL) per Modbus TCP aus: Außen-, Zu- und Ablufttemperatur, den berechneten Wirkungsgrad der Wärmerückgewinnung, die Filterlaufzeit und die Sammelstörung — lokal, ohne Cloud und ohne Herstellerkonto.',
        'Der Nutzen: Werte, die sonst nur in der Oberfläche der Anlage stehen, sind in Symcon verfügbar — für Auswertungen, Meldungen bei Störung oder Filterwechsel und als Datenbasis für Energiemanagement und Dashboard. Geräte mit RS485/Modbus RTU bindest du mit RLTHubGateway an, mehrere Anlagen im Netz findet RLTHubDiscovery.',
        'Das Modul liest nur — es steuert die Anlage nicht.',
    ];
    public const NEWS = [
        '• 🔧 Fix: Das Feld „IP-Adresse" zeigte nur einen festen Satz („trägt RLTHubDiscovery automatisch ein"), egal ob das tatsächlich stimmte. Jetzt eine echte Zeile: 🔗, wenn die Adresse wirklich aus dem letzten Suchlauf von RLTHubDiscovery stammt (mit Fund-Zeitpunkt und Gerätetyp), sonst ✏️ eigene Angabe bzw. ℹ️ wenn noch nichts eingetragen ist.',
        '• 🆕 Neuer Gerätetyp: Pichler LG mit ES2020-Steuerung (z. B. LG 350) per Modbus TCP (ab Firmware 1.6, am Gerät einzuschalten). Temperaturen, berechneter WRG-Wirkungsgrad, Zu-/Abluft-Volumenstrom in m³/h, Summenstörmeldung, Filtermeldung und Restzeit bis zum Filterwechsel.',
        '• ⚠️ Noch an keiner Pichler-Anlage geprüft (Adress-Basis, Zuordnung der Felder). Details im Panel „Dokumentation & Hilfe“. Testberichte sind willkommen.',
    ];

    public function Create()
    {
        parent::Create();
        $this->rltCreate();
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', 502);
        $this->RegisterPropertyInteger('UnitId', 1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->rltApply();
    }

    private const DISCOVERY_GUID = '{CC2C38CA-7675-4F27-B993-7B68187B8879}';

    /**
     * Echte Live-Prüfung statt Pauschalsatz (SUITE.md „Verbund-Verbindungen im
     * Formular sichtbar machen"): war die aktuell eingetragene Adresse Teil des
     * LETZTEN Suchergebnisses einer RLTHubDiscovery-Instanz? Nur dann 🔗
     * automatisch — RLTHubDiscovery schreibt die Adresse nur EINMALIG beim
     * Anlegen, danach ist Host eine normale Property wie jede andere; es gibt
     * keine laufende Verbindung zu ihr, die man sonst live abfragen könnte.
     */
    private function rltAddressLine(string $host, int $port, int $unitId): string
    {
        if ($host === '') {
            return 'ℹ️ Noch keine IP-Adresse eingetragen — wird für die Verbindung gebraucht. Über „RLTHubDiscovery" gefundene Anlagen lassen sich mit einem Klick übernehmen.';
        }
        foreach (IPS_GetInstanceListByModuleID(self::DISCOVERY_GUID) as $iid) {
            try {
                $m = RLTD_GetLastMatch($iid, $host, $port, $unitId);
            } catch (\Throwable $e) {
                continue;
            }
            if (!empty($m['found'])) {
                $when = ((int)($m['ts'] ?? 0)) > 0 ? ' (gefunden ' . date('d.m.Y H:i:s', (int)$m['ts']) . ' Uhr' . (($m['device'] ?? '') !== '' ? ' als ' . $m['device'] : '') . ')' : '';
                return '🔗 IP-Adresse: ' . $host . ':' . $port . ' (Unit-ID ' . $unitId . '), automatisch von RLTHubDiscovery übernommen' . $when . '.';
            }
        }
        return '✏️ Eigene Angabe: ' . $host . ':' . $port . ' (Unit-ID ' . $unitId . ').';
    }

    protected function rltConnectionItems(): array
    {
        $host = trim((string)$this->ReadPropertyString('Host'));
        $line = $this->rltAddressLine($host, (int)$this->ReadPropertyInteger('Port'), (int)$this->ReadPropertyInteger('UnitId'));
        $fields = [
            ['type' => 'ValidationTextBox', 'name' => 'Host', 'caption' => 'IP-Adresse', 'validate' => '^\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}$', 'onChange' => 'RLT_OnChangeHost($id, $Host);'],
            ['type' => 'NumberSpinner', 'name' => 'Port', 'caption' => 'TCP-Port', 'minimum' => 1, 'maximum' => 65535],
            ['type' => 'NumberSpinner', 'name' => 'UnitId', 'caption' => 'Unit ID', 'minimum' => 1, 'maximum' => 247],
        ];
        if ($host !== '' && strpos($line, '🔗') === 0) {
            return [RLT_Ui::line('AddressLine', $line), ['type' => 'ExpansionPanel', 'caption' => 'Eigene Verbindung stattdessen verwenden', 'expanded' => false, 'items' => $fields]];
        }
        return array_merge([RLT_Ui::line('AddressLine', $line)], $fields);
    }

    // Der Wert selbst wird nie live nachgeführt (SUITE.md „Wert kommt
    // automatisch: Eingabefeld ersetzen" — das Eingabefeld bleibt unangetastet).
    // Nur die Zeile folgt der Eingabe; ob die Adresse noch als „automatisch"
    // gilt, entscheidet der nächste Formularaufbau.
    public function OnChangeHost(string $host): void
    {
        $host = trim($host);
        $caption = $host === ''
            ? 'ℹ️ Noch keine IP-Adresse eingetragen — wird für die Verbindung gebraucht. Über „RLTHubDiscovery" gefundene Anlagen lassen sich mit einem Klick übernehmen.'
            : '✏️ Eigene Angabe: ' . $host . ':' . (int)$this->ReadPropertyInteger('Port') . ' (Unit-ID ' . (int)$this->ReadPropertyInteger('UnitId') . ').';
        $this->UpdateFormField('AddressLine', 'caption', $caption);
        $this->UpdateFormField('AddressLine', 'color', RLT_Ui::color($caption));
    }

    protected function rltConnectionStatus(): int
    {
        return trim((string)$this->ReadPropertyString('Host')) === '' ? 104 : 102;
    }

    protected function rltConnectionTarget(): string
    {
        $host = trim((string)$this->ReadPropertyString('Host'));
        return $host === '' ? '' : $host . ':' . (int)$this->ReadPropertyInteger('Port') . ' (Unit-ID ' . (int)$this->ReadPropertyInteger('UnitId') . ')';
    }

    protected function rltClient(): RLT_ModbusClientInterface
    {
        return new RLT_ModbusTcpClient(
            (string)$this->ReadPropertyString('Host'),
            (int)$this->ReadPropertyInteger('Port'),
            (int)$this->ReadPropertyInteger('UnitId')
        );
    }
}
