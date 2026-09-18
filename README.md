# NRG-Stack RLTHub

Generisches Modbus-TCP-Framework für Raumlufttechnische Anlagen (RLT/KWL),
herstellerübergreifend — analog zu [NRGMeterHub](https://github.com/DG65/NRGMeterHub),
[NRGInverterHub](https://github.com/DG65/NRGInverterHub) und
[NRGChargerHub](https://github.com/DG65/NRGChargerHub). Teil des
[NRG-Stack](https://github.com/DG65) für IP-Symcon.

## Status

**0.1.0 — Architektur-Skelett (Kickoff 19.09.2026), noch ohne echte Hardware
verifiziert.** Enthält:

- Geteilte Modbus-Verbindungsfassade (direkt per `fsockopen` ODER über
  Symcons natives Modbus-Gateway/eingebauter Symbox-RS485-Port), 1:1 aus
  MeterHub/InverterHub/ChargerHub übernommen statt neu erforscht.
- Ersten Treiber: **Robatherm TrueControl** — Registeradressen stammen aus
  einer einzelnen, konkreten Anlagenkonfiguration (siehe Warnhinweise im
  Konfigurationsformular und im Treiber-Quellcode). Vor Produktiveinsatz an
  einer anderen Anlage unbedingt gegen deren eigene Modbus-Datenpunktliste
  prüfen.
- Den mit dem EMS abgestimmten Cross-Modul-Vertrag `Type=>'ventilation'`
  (`RLT_GetFunctions`, contractVersion 1.0) — das EMS ist aktuell reine
  Konsument-Rolle, rein lesend.

Noch **nicht** umgesetzt: die verbundweite Formular-Konvention (News-/Doku-/
Forum-Panel, siehe `SUITE.md`), Schreibzugriff auf die Sollwerte, weitere
Hersteller-Treiber.

## Prüfstand

```
php .tools/test-robatherm-driver.php    # 0 = alle Prüfungen bestanden
```

Prüft die Modbus-Fassade (direkt + Gateway, echter TCP-Server in einem
eigenen Prozess) und den Robatherm-Treiber (Register-Dekodierung,
WRG-Wirkungsgrad-Berechnung, Fehlerfälle) ohne IP-Symcon.

## Lizenz

PolyForm Noncommercial License 1.0.0 — siehe [LICENSE](LICENSE). Private und
nicht-kommerzielle Nutzung frei, gewerbliche Nutzung erfordert eine
gesonderte Lizenz vom Rechteinhaber (DG65).
