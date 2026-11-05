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

    public const NEWS_VERSION = '0.5.0';
    public const FORUM_THREAD_URL = 'https://community.symcon.de/t/modul-nrg-stack-rlthub-lueftungsanlagen-rlt-kwl-per-modbus-anbinden-robatherm-truecontrol-proxon-fwt/144442';
    public const LICENSE_URL = 'https://github.com/DG65/NRGRLTHub/blob/beta/LICENSE';
    public const PURPOSE = [
        'Reads a ventilation system (air handling unit / heat recovery ventilation) via Modbus TCP: outdoor, supply and extract air temperature, the calculated heat recovery efficiency, the filter runtime and the general fault — local, without cloud and without a manufacturer account.',
        'The benefit: values that are otherwise only shown in the plant\'s own interface become available in Symcon — for evaluations, notifications on faults or filter changes, and as a data basis for energy management and dashboard. You connect devices with RS485/Modbus RTU using RLTHubGateway; RLTHubDiscovery finds several plants on the network.',
        'The module only reads — it does not control the plant.',
    ];
    public const NEWS = [
        '• 🌐 New: bilingual (German/English). The form, messages, variable names and notes now follow the Symcon language. Existing variables keep their names; new ones are created in the current language. Identifiers, settings and the contract with other modules are unchanged.',
        '• 🔧 Archive: new option "Record measured values in the archive" (default off). Until now the module switched recording on by itself every time you applied changes. Now you decide; the module never switches a recording off. Existing recordings stay as they are.',
        '• 🆕 New device type: Pichler LG with ES2020 controller (e.g. LG 350) via Modbus TCP (from firmware 1.6, to be switched on at the device). Temperatures, calculated heat recovery efficiency, supply/extract air flow in m³/h, collective fault signal, filter alarm and time remaining until the filter change.',
        '• ⚠️ Not yet checked on any Pichler plant (address base, mapping of the fields). Details in the panel "Documentation & help". Test reports are welcome.',
    ];

    public function __construct($InstanceID)
    {
        parent::__construct($InstanceID);
        RLT_Text::setTranslator(RLT_Text::withMemo(function (string $text): string {
            return $this->Translate($text);
        }));
    }

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
            return RLT_Text::t('ℹ️ No IP address entered yet — needed for the connection. Plants found via "RLTHubDiscovery" can be taken over with one click.');
        }
        foreach (IPS_GetInstanceListByModuleID(self::DISCOVERY_GUID) as $iid) {
            try {
                $m = RLTD_GetLastMatch($iid, $host, $port, $unitId);
            } catch (\Throwable $e) {
                continue;
            }
            if (!empty($m['found'])) {
                $when = '';
                if (((int)($m['ts'] ?? 0)) > 0) {
                    $when = ($m['device'] ?? '') !== ''
                        ? RLT_Text::t(' (found %s as %s)', RLT_Text::time((int)$m['ts']), $m['device'])
                        : RLT_Text::t(' (found %s)', RLT_Text::time((int)$m['ts']));
                }
                return RLT_Text::t('🔗 IP address: %s:%d (unit ID %d), taken over automatically from RLTHubDiscovery%s.', $host, $port, $unitId, $when);
            }
        }
        return RLT_Text::t('✏️ Own setting: %s:%d (unit ID %d).', $host, $port, $unitId);
    }

    protected function rltConnectionItems(): array
    {
        $host = trim((string)$this->ReadPropertyString('Host'));
        $line = $this->rltAddressLine($host, (int)$this->ReadPropertyInteger('Port'), (int)$this->ReadPropertyInteger('UnitId'));
        $fields = [
            ['type' => 'ValidationTextBox', 'name' => 'Host', 'caption' => RLT_Text::t('IP address'), 'validate' => '^\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}$', 'onChange' => 'RLT_OnChangeHost($id, $Host);'],
            ['type' => 'NumberSpinner', 'name' => 'Port', 'caption' => RLT_Text::t('TCP port'), 'minimum' => 1, 'maximum' => 65535],
            ['type' => 'NumberSpinner', 'name' => 'UnitId', 'caption' => RLT_Text::t('Unit ID'), 'minimum' => 1, 'maximum' => 247],
        ];
        if ($host !== '' && strpos($line, '🔗') === 0) {
            return [RLT_Ui::line('AddressLine', $line), ['type' => 'ExpansionPanel', 'caption' => RLT_Text::t('Use own connection instead'), 'expanded' => false, 'items' => $fields]];
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
            ? RLT_Text::t('ℹ️ No IP address entered yet — needed for the connection. Plants found via "RLTHubDiscovery" can be taken over with one click.')
            : RLT_Text::t('✏️ Own setting: %s:%d (unit ID %d).', $host, (int)$this->ReadPropertyInteger('Port'), (int)$this->ReadPropertyInteger('UnitId'));
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
        return $host === '' ? '' : RLT_Text::t('%s:%d (unit ID %d)', $host, (int)$this->ReadPropertyInteger('Port'), (int)$this->ReadPropertyInteger('UnitId'));
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
