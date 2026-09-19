# Changelog

## 0.2.0 (19.09.2026)

- **Fix:** `IPS_GetObjectIDByIdent()` wurde mit vertauschten Argumenten aufgerufen — die Variablen
  wären nie gefunden und nie beschrieben worden (0.1.0 war damit nicht lauffähig).
- Neu: `RLTHubGateway` — Kind-Modul für Symcons natives ModBus-Gateway (RS485/RTU). Der in 0.1.0
  angebotene Verbindungsweg „Symbox-Gateway" im Hauptmodul funktionierte mangels Parent nicht und
  entfällt; `RLTHub` ist reines Modbus TCP.
- Neu: Treiber Proxon FWT 2.0 (nur RTU, unverifiziert).
- Neu: `RLTHubDiscovery` — Netzwerksuche nach Modbus-TCP-Anlagen, legt `RLTHub`-Instanzen an.
- Adress-Basis je Gerätetyp („automatisch") statt fester Annahme, Formularschalter bleibt.
- Gemeinsamer Kern in `libs/RLTCore.php`; Gateway-Client überspringt den Rest des Zyklus, wenn das
  Gerät zweimal in Folge nicht antwortet.
- Prüfstand `.tools/test-rlthub.php` (gegen IPSModule-Nachbau mit den echten Signaturen).

## 0.1.0 (19.09.2026)

- Architektur-Skelett, Treiber Robatherm TrueControl, Vertrag `Type=>'ventilation'` 1.0.
