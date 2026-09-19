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

    public const NEWS_VERSION = '0.3.0';
    // Der Forum-Thread existiert noch nicht — solange leer, zeigt der Hinweis nur Text.
    public const FORUM_THREAD_URL = '';
    public const LICENSE_URL = 'https://github.com/DG65/NRGRLTHub/blob/beta/LICENSE';
    public const PURPOSE = [
        'Liest eine Lüftungsanlage über RS485/Modbus RTU aus — mit Symcons eigenem ModBus-Gateway (Serial Port → ModBus Gateway → diese Instanz), z. B. an einem USB-RS485-Dongle am Symcon-Host oder am RS485-Anschluss einer Symbox.',
        'Der Nutzen: Geräte, die nur eine serielle Schnittstelle haben (z. B. Proxon FWT), lassen sich einbinden, ohne dass dieses Modul selbst eine serielle Schnittstelle öffnen muss. Werte: Außen-, Zu- und Ablufttemperatur, Wirkungsgrad der Wärmerückgewinnung (berechnet), Filterlaufzeit, Störung. Anlagen mit Modbus TCP bindest du mit RLTHub an.',
        'Das Modul liest nur — es steuert die Anlage nicht.',
    ];
    public const NEWS = [
        '• 🆕 Erste Version: Lüftungsanlagen über Symcons ModBus-Gateway auslesen (RS485/Modbus RTU). Gerätetyp: Proxon FWT 2.0.',
        '• ⚠️ Noch an keiner echten Anlage verifiziert: Die Temperaturskalierung unter 0 °C ist offen, Details im Panel „Dokumentation & Hilfe". Der Weg über das ModBus-Gateway selbst ist an einer Schwestereinheit bestätigt (Lesen).',
    ];

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
