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
    public const DEFAULT_DEVICE = 'proxon_fwt';
    public const TRANSPORT = 'rtu';

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
        return [
            ['type' => 'Label', 'caption' => 'ℹ️ Der Anschluss läuft über Symcons ModBus-Gateway: oben unter „Gateway" das ModBus-Gateway wählen (Symcon-Objektbaum: Serial Port → ModBus Gateway, Gateway-Modus RTU für RS485). Die Slave-ID (Modbus-Adresse des Geräts) wird am Gateway eingestellt („Geräte-ID"), nicht hier.'],
        ];
    }

    private function hasGatewayParent(): bool
    {
        $inst = @IPS_GetInstance($this->InstanceID);
        $parent = is_array($inst) ? (int)($inst['ConnectionID'] ?? 0) : 0;
        return $parent > 0 && @IPS_InstanceExists($parent);
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
