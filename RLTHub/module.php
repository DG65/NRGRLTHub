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
    public const DEFAULT_DEVICE = 'robatherm_truecontrol';
    public const TRANSPORT = 'tcp';

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
            ['type' => 'ValidationTextBox', 'name' => 'Host', 'caption' => 'IP-Adresse', 'validate' => '^\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}$'],
            ['type' => 'NumberSpinner', 'name' => 'Port', 'caption' => 'TCP-Port', 'minimum' => 1, 'maximum' => 65535],
            ['type' => 'NumberSpinner', 'name' => 'UnitId', 'caption' => 'Unit ID', 'minimum' => 1, 'maximum' => 247],
        ];
    }

    protected function rltConnectionStatus(): int
    {
        return trim($this->ReadPropertyString('Host')) === '' ? 104 : 102;
    }

    protected function rltClient(): RLT_ModbusClientInterface
    {
        return new RLT_ModbusTcpClient(
            $this->ReadPropertyString('Host'),
            $this->ReadPropertyInteger('Port'),
            $this->ReadPropertyInteger('UnitId')
        );
    }
}
