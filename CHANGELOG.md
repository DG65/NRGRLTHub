# Changelog

## 0.3.2 (21.09.2026)

- Verbund-Konvention „Verbindungen im Formular sichtbar machen" (SUITE.md, 21.09.2026): Im Panel „Verbindung"
  von RLTHub und RLTHubGateway steht jetzt eine live berechnete Statuszeile — ℹ️ nichts eingetragen bzw.
  kein ModBus-Gateway (mit Folge), ⚠️ verbunden ohne gültige Antwort bzw. letzte Abfrage fehlgeschlagen,
  ✅ verbunden mit Ziel (Adresse oder Gateway mit Instanz-ID und Geräte-ID), Zeitpunkt der letzten
  gültigen Antwort und den zuletzt gelesenen Werten samt Quelle. „Verbindung jetzt testen" frischt sie auf.
- Die Gateway-Übersicht der Suche zeigt ihren Zustand (ℹ️ nichts gefunden, ✅ Zahlen, ⚠️ RLTHubGateway ohne Gateway).
- Prüfstand: jeder Zustand wird im ausgelieferten Formular gefunden, ein statischer Ersatzsatz lässt ihn
  rot werden.
- Verbund-Konvention „Wert kommt automatisch: Eingabefeld ersetzen“ (SUITE.md, 21.09.2026): Die Adress-Basis
  („automatisch“) und der Suchbereich der Suche (leer = eigenes Netz) zeigen den geltenden Wert samt Quelle als
  schreibgeschützte Zeile (🔗); das Feld steckt dann in einem eingeklappten Panel „… stattdessen verwenden“.
  Eigene Angaben bleiben sichtbar (✏️), ohne erkennbaren Automatikwert werden die Felder gebraucht (ℹ️).
  Im Gateway-Modul zeigt eine Zeile die Slave-ID, die vom ModBus-Gateway kommt. Die Zeilen folgen der
  Auswahl im offenen Formular. Der automatische Wert wird nie ins Eingabefeld geschrieben.

## 0.3.1 (21.09.2026)

- Proxon FWT 2.0 an einer echten Anlage geprüft (Rohwert gegen Display, über das ModBus-Gateway):
  Adressen und Function Codes, Adress-Basis (Excel-Nummer = Adresse am Gateway), Temperaturen
  (Rohwert ÷ 100), Störung 0 = keine Störung, Filterstunden und Betriebsart stimmen. Hinweistexte,
  Vertrauensangabe des Gerätetyps und Treiberkommentar entsprechend angepasst.
- Weiter offen: Temperaturen unter 0 °C und die Bedeutung der Störungscodes (Werte ≠ 0).
- Der Prüfstand enthält die echten Messwerte als Regressionsfall.

## 0.3.0 (19.09.2026)

- Verbundweite Formular-Konvention (SUITE.md „Einheitliche Formular-Optik") in allen drei Modulen:
  „Wozu dieses Modul?", „Neu in Version", „Dokumentation & Hilfe" mit Versionsnummer, Forum-Hinweis
  und „Über dieses Modul" (Variante A). Ausblenden gilt zwischen Instanzen desselben Modultyps und
  wird von neu angelegten Instanzen übernommen.
- Netzwerksuche: Verbund-Status-Kopfzeile („✅ N … gefunden (zuletzt HH:MM:SS Uhr)."), Schaltfläche zuerst,
  Details in eingeklapptem Unter-Panel, Zeitstempel bei jeder Suche.
- Hilfe-Popup zur Adress-Basis, Hinweis zur Vorbelegung des Gerätetyps und wann die Adresse von Hand
  nötig ist; Abgrenzung Symbox-RS485-Port / externer RTU→TCP-Konverter im Doku-Panel.
- Store-Review: Werte aus `ReadProperty*`/`ReadAttribute*` gecastet, Abfragezyklus nur bei `KR_READY`,
  Ausfall einmalig im Symcon-Protokoll vermerkt.
- README mit Badges, CI-Workflow „Check Style" (Syntaxprüfung und Prüfstand).
- Der Forum-Thread existiert noch nicht: der Hinweis zeigt bis dahin nur Text, keine Verknüpfung.
- Build 4: Fehlt das ModBus-Gateway, nennt der Verbindungstest und das Symcon-Protokoll jetzt den wahren Grund (statt „Regler antwortet nicht"). Gefunden bei der Prüfung am Live-IPS.
- Build 5: Forum-Thread eingetragen, der Forum-Hinweis im Formular verlinkt jetzt auf den Beitrag.

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
