# Changelog

## 0.1.0 (19.09.2026)

- Architektur-Skelett: geteilte Modbus-Verbindungsfassade (direkt/Symbox-Gateway)
  1:1 aus MeterHub/InverterHub/ChargerHub übernommen (SUITE.md 9j).
- Erster Treiber: Robatherm TrueControl (Messwerte, Sammelstörung, Watchdog-
  Diagnose) — Registeradressen aus einer einzelnen Anlagenkonfiguration,
  noch ohne echte Hardware verifiziert.
- Cross-Modul-Vertrag `Type=>'ventilation'` (`RLT_GetFunctions`,
  contractVersion 1.0), mit dem EMS abgestimmt.
- Prüfstand `.tools/test-robatherm-driver.php`.
