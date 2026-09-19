# NRG-Stack RLTHub

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Modul Version](https://img.shields.io/badge/Modul_Version-0.3.0-blue)
![Symcon Version](https://img.shields.io/badge/Symcon_Version-9.0%2B-blue)
![License](https://img.shields.io/badge/License-PolyForm_Noncommercial_1.0.0-lightgrey)
[![Check Style](https://github.com/DG65/NRGRLTHub/actions/workflows/check-style.yml/badge.svg)](https://github.com/DG65/NRGRLTHub/actions/workflows/check-style.yml)
[![PayPal](https://img.shields.io/badge/PayPal-Me-blue?logo=paypal)](https://paypal.me/DietmarGureth)

IP-Symcon-Modul für Raumlufttechnische Anlagen (RLT/KWL), herstellerübergreifend — analog zu
[NRGMeterHub](https://github.com/DG65/NRGMeterHub), [NRGInverterHub](https://github.com/DG65/NRGInverterHub)
und [NRGChargerHub](https://github.com/DG65/NRGChargerHub).

**Teil des NRG-Stack** — dem Energie-Modulverbund von DG65.

## Status

**0.3.0 (Beta) — noch an keiner echten Anlage verifiziert.** Alle Prüfungen laufen gegen Test-Modbus-Server
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

## Werte

Außen-, Zu- und Ablufttemperatur, Wirkungsgrad der Wärmerückgewinnung (aus den drei Temperaturen berechnet,
nicht vom Gerät geliefert), Filterlaufzeit und Sammelstörung; je nach Gerät auch Luftqualität und
Ventilator-Volumenstrom. Rein lesend — das Modul steuert die Anlage nicht.

## Cross-Modul-Vertrag

`RLT_GetFunctions($id)` / `RLTGW_GetFunctions($id)` liefert `Type=>'ventilation'`, `contractVersion 1.0`
(mit dem EMS abgestimmt). Felder, die ein Gerät nicht liefert, stehen auf 0.

## Prüfstand

```
php .tools/test-rlthub.php    # 0 = alle Prüfungen bestanden
```

## Lizenz

PolyForm Noncommercial License 1.0.0 — siehe [LICENSE](LICENSE). Private und nicht-kommerzielle Nutzung
frei, gewerbliche Nutzung erfordert eine gesonderte Lizenz vom Rechteinhaber (DG65). Spenden sind
willkommen: [paypal.me/DietmarGureth](https://paypal.me/DietmarGureth).
