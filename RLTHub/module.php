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

    public const NEWS_VERSION = '0.3.0';
    public const FORUM_THREAD_URL = 'https://community.symcon.de/t/modul-nrg-stack-rlthub-lueftungsanlagen-rlt-kwl-per-modbus-anbinden-robatherm-truecontrol-proxon-fwt/144442';
    public const LICENSE_URL = 'https://github.com/DG65/NRGRLTHub/blob/beta/LICENSE';
    public const PURPOSE = [
        'Liest eine Lüftungsanlage (RLT/KWL) per Modbus TCP aus: Außen-, Zu- und Ablufttemperatur, den berechneten Wirkungsgrad der Wärmerückgewinnung, die Filterlaufzeit und die Sammelstörung — lokal, ohne Cloud und ohne Herstellerkonto.',
        'Der Nutzen: Werte, die sonst nur in der Oberfläche der Anlage stehen, sind in Symcon verfügbar — für Auswertungen, Meldungen bei Störung oder Filterwechsel und als Datenbasis für Energiemanagement und Dashboard. Geräte mit RS485/Modbus RTU bindest du mit RLTHubGateway an, mehrere Anlagen im Netz findet RLTHubDiscovery.',
        'Das Modul liest nur — es steuert die Anlage nicht.',
    ];
    public const NEWS = [
        '• 🆕 Erste Version: Lüftungsanlagen per Modbus TCP auslesen — Außen-/Zu-/Ablufttemperatur, Wirkungsgrad der Wärmerückgewinnung (berechnet), Filterlaufzeit und Sammelstörung. Gerätetyp: Robatherm TrueControl.',
        '• ⚠️ Noch an keiner echten Anlage verifiziert: Registeradressen, Temperatur-Skalierung und Adress-Basis sind im Panel „Dokumentation & Hilfe" mit ihrem Stand beschrieben. Rückmeldungen sind ausdrücklich willkommen.',
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

    protected function rltConnectionItems(): array
    {
        return [
            ['type' => 'Label', 'caption' => 'ℹ️ Die Adresse trägt RLTHubDiscovery automatisch ein. Von Hand nötig nur, wenn die Anlage dort nicht gefunden wird (z. B. anderer Netzbereich).'],
            ['type' => 'ValidationTextBox', 'name' => 'Host', 'caption' => 'IP-Adresse', 'validate' => '^\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}$'],
            ['type' => 'NumberSpinner', 'name' => 'Port', 'caption' => 'TCP-Port', 'minimum' => 1, 'maximum' => 65535],
            ['type' => 'NumberSpinner', 'name' => 'UnitId', 'caption' => 'Unit ID', 'minimum' => 1, 'maximum' => 247],
        ];
    }

    protected function rltConnectionStatus(): int
    {
        return trim((string)$this->ReadPropertyString('Host')) === '' ? 104 : 102;
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
