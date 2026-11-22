# NRG-Stack RLTHub

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Module version](https://img.shields.io/badge/Modul_Version-0.7.0-blue)
![Symcon version](https://img.shields.io/badge/Symcon_Version-9.0%2B-blue)
![License](https://img.shields.io/badge/License-PolyForm_Noncommercial_1.0.0-lightgrey)
[![Check Style](https://github.com/DG65/NRGRLTHub/actions/workflows/check-style.yml/badge.svg)](https://github.com/DG65/NRGRLTHub/actions/workflows/check-style.yml)
[![PayPal](https://img.shields.io/badge/PayPal-Me-blue?logo=paypal)](https://paypal.me/DietmarGureth)

Symcon module for ventilation systems (air handling units / heat recovery ventilation), independent of the
manufacturer — in the same spirit as [NRGMeterHub](https://github.com/DG65/NRGMeterHub),
[NRGInverterHub](https://github.com/DG65/NRGInverterHub) and [NRGChargerHub](https://github.com/DG65/NRGChargerHub).

**Part of the NRG-Stack** — the energy module family by DG65. · [Deutsche Version](README.md)

## Status

**0.7.0 (beta).** Proxon FWT 2.0 has been checked on a real plant (temperatures above 0 °C, fault 0, filter
hours, operating mode); Robatherm TrueControl has not been checked on any plant yet. The tests additionally run
against test Modbus servers and a replica of IPSModule; the verification status per device type is shown in the
form and in the source code. The module is bilingual (German/English) and follows the Symcon language.

## Modules

| Module | Purpose |
|---|---|
| `RLTHub` | Modbus TCP (own connection). |
| `RLTHubGateway` | RS485/Modbus RTU via Symcon's native ModBus gateway (child module with `parentRequirements`, pattern taken from WPModbusHubGateway, confirmed there on real hardware). The slave ID is set at the gateway. |
| `RLTHubDiscovery` | Searches the network for Modbus TCP plants (read-only) and creates `RLTHub` instances. RTU devices cannot be searched; the search lists the existing gateways. |

## Device types

| Device type | Transport | Status |
|---|---|---|
| Robatherm TrueControl | TCP | Register list of a single plant (TrueControl is parameterised per project); scaling and address base unverified |
| Pichler LG (ES2020, e.g. LG 350) | TCP (from FW 1.6) and RTU | Public manufacturer list, not yet checked on any plant (address base, mapping of filter and air flow) |
| Proxon FWT 2.0 (Zimmermann) | RTU | Checked on a real FWT (21.09.2026); open: temperatures below 0 °C, fault codes |
| Helios KWL easyControls 3.0 | RTU | Register list from a third-party transcription of the Helios document, not yet checked on any device or against the manufacturer document (address base). No fault and no filter status (the source has no registers for them). The Helios network gateway (Modbus TCP) is not covered |
| Helios KWL easyControls (network gateway) | TCP | Based on the Helios document "Modbus Gateway TCP/IP – easyControls" (fixed port 502 and unit ID 180, variable protocol), not yet checked on any device. Read-only (only the variable name is written). Not found by the network search, enter the IP by hand. No filter warning, no CO2/humidity |

## Values

Outdoor, supply and extract air temperature, heat recovery efficiency (calculated from the three temperatures,
not delivered by the device), filter runtime and general fault; depending on the device also air quality and
fan air flow. Read-only — the module does not control the plant.

## Cross-module contract

`RLT_GetFunctions($id)` / `RLTGW_GetFunctions($id)` returns `contractType=>'ventilation'` (and `Type` as an alias), `contractVersion 1.1`
(agreed with EMS). Fields that a device does not provide are 0.
New in 1.1: `filterWarningID` (filter change warning, Pichler) and `fanFlowUnit` (unit of the air flows: `'%'` Robatherm, `'m3/h'` Pichler, empty = no air flows).

## Test suite

```
php .tools/test-rlthub.php    # 0 = all checks passed
```

The German texts live in `libs/locale.json` (source language in the code is English); `php .tools/locale-bauen.php`
copies it into the three module folders.

## License

PolyForm Noncommercial License 1.0.0 — see [LICENSE](LICENSE). Private and non-commercial use is free;
commercial use requires a separate license from the rights holder (DG65). Donations are welcome:
[paypal.me/DietmarGureth](https://paypal.me/DietmarGureth).
