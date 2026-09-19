# NRG-Stack RLTHub

Raumlufttechnische Anlagen (RLT/KWL) für IP-Symcon, herstellerübergreifend — analog zu
[NRGMeterHub](https://github.com/DG65/NRGMeterHub), [NRGInverterHub](https://github.com/DG65/NRGInverterHub)
und [NRGChargerHub](https://github.com/DG65/NRGChargerHub). Teil des NRG-Stack.

## Status

**0.2.0 — noch an keiner echten Anlage verifiziert.** Alle Prüfungen laufen gegen Test-Modbus-Server
und einen IPSModule-Nachbau; die Registerkarten und Skalierungen sind im Formular und im Quellcode
mit ihrem Verifikationsstand markiert.

## Module

| Modul | Zweck |
|---|---|
| `RLTHub` | Modbus TCP (eigene Verbindung). |
| `RLTHubGateway` | RS485/Modbus RTU über Symcons natives ModBus-Gateway (Kind-Modul mit `parentRequirements`, Muster von WPModbusHubGateway, dort an echter Hardware bestätigt). Die Slave-ID steht am Gateway. |
| `RLTHubDiscovery` | Sucht Modbus-TCP-Anlagen im Netz (rein lesend) und legt `RLTHub`-Instanzen an. RTU-Geräte sind nicht suchbar; die Suche listet vorhandene Gateways auf. |

## Gerätetypen

| Gerätetyp | Transport | Stand |
|---|---|---|
| Robatherm TrueControl | TCP | Registerliste einer einzelnen Anlage (TrueControl wird je Projekt parametriert), Skalierung und Adress-Basis unverifiziert |
| Proxon FWT 2.0 (Zimmermann) | RTU | Registerliste der Herstellerdoku, Temperaturskalierung unter 0 °C offen |

## Cross-Modul-Vertrag

`RLT_GetFunctions($id)` / `RLTGW_GetFunctions($id)` liefert `Type=>'ventilation'`, `contractVersion 1.0`
(mit dem EMS abgestimmt, siehe SUITE.md). Felder, die ein Gerät nicht liefert, stehen auf 0. Rein
lesend — keine aktive Steuerung.

## Prüfstand

```
php .tools/test-rlthub.php    # 0 = alle Prüfungen bestanden
```

## Lizenz

PolyForm Noncommercial License 1.0.0 — siehe [LICENSE](LICENSE). Private und nicht-kommerzielle Nutzung
frei, gewerbliche Nutzung erfordert eine gesonderte Lizenz vom Rechteinhaber (DG65).
