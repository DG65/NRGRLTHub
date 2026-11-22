<?php

require_once __DIR__ . '/../libs/RLTCore.php';

// NRG-Stack RLTHubGateway — Lüftungsanlagen über Symcons NATIVES ModBus-Gateway
// (Serial Port -> ModBus Gateway -> diese Instanz), damit auch RS485/Modbus
// RTU geht, z. B. Proxon FWT an einem USB-RS485-Dongle am Symcon-Host.
//
// Kind-Modul mit parentRequirements {E310B701-...} (Daten, die wir ans Gateway
// senden) und implemented {77B31ABB-...} (Daten, die das Gateway an Kinder
// sendet) — Muster und GUIDs von WPModbusHubGateway (19.09.2026 an einer
// echten Proxon T300 bestätigt). ConnectParent() wird bewusst NICHT
// aufgerufen: es würde beim Anlegen ungefragt ein Gateway erzeugen; der
// Nutzer wählt sein Gateway im Instanzformular. Die Slave-ID steht nicht in
// dieser Instanz, sondern am Gateway („DeviceID").
class RLTHubGateway extends IPSModule
{
    use RLT_HubTrait;

    public const PREFIX = 'RLTGW';
    public const MODULE_NAME = 'RLTHubGateway';
    public const MODULE_GUID = '{1AE15A54-8ECC-4599-961B-447BD8671715}';
    public const DEFAULT_DEVICE = 'proxon_fwt';
    public const TRANSPORT = 'rtu';

    public const NEWS_VERSION = '0.6.0';
    public const FORUM_THREAD_URL = 'https://community.symcon.de/t/modul-nrg-stack-rlthub-lueftungsanlagen-rlt-kwl-per-modbus-anbinden-robatherm-truecontrol-proxon-fwt/144442';
    public const LICENSE_URL = 'https://github.com/DG65/NRGRLTHub/blob/beta/LICENSE';
    public const PURPOSE = [
        'Reads a ventilation system via RS485/Modbus RTU — using Symcon\'s own ModBus gateway (Serial Port → ModBus Gateway → this instance), e.g. on a USB-RS485 dongle at the Symcon host or the RS485 port of a Symbox.',
        'The benefit: devices that only have a serial interface (e.g. Proxon FWT) can be integrated without this module having to open a serial interface itself. Values: outdoor, supply and extract air temperature, heat recovery efficiency (calculated), filter runtime, fault. You connect systems with Modbus TCP using RLTHub.',
        'The module only reads — it does not control the system.',
    ];
    public const NEWS = [
        '• 🆕 New device type: Helios KWL easyControls 3.0 via Modbus RTU (factory setting according to the register list: slave address 1, 19200 baud, even parity). Provides outdoor, supply, extract and exhaust air temperature, calculated heat recovery efficiency, fan speed in %, Home/Away profile, bypass and defrost state.',
        '• ⚠️ Not yet checked on any Helios device, and the register list comes from a third-party transcription of the Helios document. The source documents no fault or filter status, so those stay empty. The Helios network gateway (Modbus TCP) is not covered. Details in the panel "Documentation & help". Test reports are welcome.',
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
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->rltApply();
    }

    protected function rltConnectionItems(): array
    {
        // Die Slave-ID kommt vom ModBus-Gateway und ersetzt ein Eingabefeld (SUITE.md
        // „Wert kommt automatisch“): schreibgeschützte Zeile statt Feld.
        if ($this->hasGatewayParent()) {
            $parent = (int)(@IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
            $deviceId = @IPS_GetProperty($parent, 'DeviceID');
            $slave = ($deviceId !== false && $deviceId !== null)
                ? RLT_Text::t('🔗 Slave ID (device ID): %d (automatically from the ModBus gateway "%s", adjustable there)', (int)$deviceId, IPS_GetName($parent))
                : RLT_Text::t('ℹ️ Slave ID: set in the ModBus gateway "%s" (device ID); it cannot be read from there.', IPS_GetName($parent));
        } else {
            $slave = RLT_Text::t('ℹ️ Slave ID: set in the ModBus gateway (device ID) — no gateway connected yet.');
        }
        return [
            ['type' => 'Label', 'caption' => RLT_Text::t('How to connect: the connection runs via Symcon\'s ModBus gateway. Select the ModBus gateway under "Gateway" above (Symcon object tree: Serial Port → ModBus Gateway, gateway mode RTU for RS485).')],
            RLT_Ui::line('SlaveIdLine', $slave),
        ];
    }

    private function hasGatewayParent(): bool
    {
        $inst = @IPS_GetInstance($this->InstanceID);
        $parent = is_array($inst) ? (int)($inst['ConnectionID'] ?? 0) : 0;
        return $parent > 0 && @IPS_InstanceExists($parent);
    }

    protected function rltConnectionTarget(): string
    {
        if (!$this->hasGatewayParent()) {
            return '';
        }
        $parent = (int)(@IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        $deviceId = @IPS_GetProperty($parent, 'DeviceID');
        return $deviceId !== false && $deviceId !== null
            ? RLT_Text::t('ModBus gateway "%s" (#%d, device ID %d)', IPS_GetName($parent), $parent, (int)$deviceId)
            : RLT_Text::t('ModBus gateway "%s" (#%d)', IPS_GetName($parent), $parent);
    }

    protected function rltConnectionStatus(): int
    {
        return $this->hasGatewayParent() ? 102 : 201;
    }

    // SendDataToParent() ist protected — der Client bekommt deshalb eine
    // Closure. Wirft/warnt das Gateway (Timeout, inaktiv), liefert sie false
    // statt den Zyklus abzubrechen.
    protected function rltClient(): RLT_ModbusClientInterface
    {
        return new RLT_ModbusGatewayClient(function (string $json) {
            if (!$this->hasGatewayParent()) {
                return false;
            }
            try {
                return @$this->SendDataToParent($json);
            } catch (\Throwable $e) {
                return false;
            }
        });
    }
}
