# Changelog

## 0.7.0 (22.11.2026)

- **Neuer Gerätetyp: Helios KWL easyControls über das Netzwerk-Gateway** (RLTHub, Modbus TCP). Das Gateway arbeitet nicht mit Registeradressen, sondern mit Variablen („vXXXXX“, Herstellerdokument „Modbus Gateway TCP/IP – easyControls“, ab Gerätesoftware 2.01): Der Name wird ab Registeradresse 1 geschrieben, danach liefert das Lesen ab Adresse 1 „vXXXXX=Wert“. Das ist die Anbindung, die vermutlich auch das nicht mehr gepflegte Helios-Modul der Community nutzte; kein Code daraus übernommen (GPL-3.0), Neubau nach dem Herstellerdokument. Port 502 und Unit-ID 180 sind laut Hersteller fest und werden für diesen Gerätetyp erzwungen (die Formularwerte gelten nicht).
- **Rein lesend:** `RLT_ModbusTcpClient::readVariable()` schreibt ausschließlich reine Namen (`^v\d{5}$`, nie ein „=“) zur Auswahl der Variable, nie einen Wert. Deshalb ist der Typ **nicht** über die Netzwerksuche auffindbar: die Suche schreibt auf unbekannten Geräten nie etwas, `probe()` liefert immer null. Die Antwort muss mit dem angefragten Namen beginnen, sonst zählt sie nicht.
- **Werte:** Außen-, Zu-, Ab- und Fortlufttemperatur (v00104–v00107, „-“ = kein Fühler), berechneter Wirkungsgrad der Wärmerückgewinnung, Lüfterdrehzahl in % (v00103, Vertragsfeld `fan1FlowID`, `fanFlowUnit` `'%'`), Lüfterstufe (v00102), Handbetrieb (v00101), Sammelstörung (Anzahl Fehler v01300 > 0) und Restlaufzeit bis zum Filterwechsel (v01033, min → h).
- **Bewusst nicht abgebildet:** v01031 „Filterwechsel“ (Lese-/Schreibwert Nein/Ja; das Dokument erklärt nicht, ob es Warnung oder Quittierung ist), deshalb bleibt `filterWarningID` leer; CO2, VOC und Feuchte der externen Fühler.
- **Grenzen:** An keinem Gerät geprüft (auch Zeitverhalten nicht: jede Variable braucht zwei Modbus-Zugriffe, ein Zyklus dauert einige Sekunden, Abfrageintervall mindestens 30 Sekunden empfohlen). Neu-Panel von RLTHub zeigt die Neuerung (`NEWS_VERSION` 0.7.0).
- Prüfstand: Block 4d mit einem echten Test-Server für das Variablenprotokoll (Schreibzugriffe protokolliert, nie ein Wert), Ausfälle, „-“-Werte, stummes Gerät, erzwungene Verbindung.

## 0.6.0 (22.11.2026)

- **Neuer Gerätetyp: Helios KWL easyControls 3.0 über Modbus RTU** (RLTHubGateway). Beauftragt, um die Nutzer des nicht mehr gepflegten Helios-Moduls aufzufangen; kein Code aus dem Altmodul (GPL-3.0), Neubau nach der Registerliste. Liefert Außen-, Zu-, Ab- und Fortlufttemperatur (Centi-Kelvin, Minusgrade möglich), berechneten Wirkungsgrad der Wärmerückgewinnung, Lüfterdrehzahl in % (Vertragsfeld `fan1FlowID`, `fanFlowUnit` `'%'`), Profil Zuhause/Abwesend sowie Bypass- und Abtaustatus. Alle Register sind Holding-Register (Funktion 3).
- **Quelle und Grenzen:** Die Registerliste stammt aus einer Abschrift des Helios-Dokuments „Modbus RTU Functional and Interface Description“ (25 483-001, V01/0521) durch einen Dritten (TapHome); das Herstellerdokument selbst lag nicht vor. Adress-Basis ungeprüft (Vorgabe: Listennummer = Adresse am Gateway). Die Quelle kennt kein Störungs- und kein Filterregister, deshalb bleiben `faultSummaryID` und `filterWarningID` leer; CO2 und Feuchte sind nicht abgebildet (0 heißt „kein Sensor“). Das Helios-Netzwerk-Gateway (Modbus TCP, Port 502, Unit-ID 180) arbeitet laut Herstellerdokument mit Variablennamen statt Registeradressen und ist nicht abgedeckt.
- Neu-Panel von RLTHubGateway zeigt die Neuerung (`NEWS_VERSION` 0.6.0). Prüfstand: neuer Block 4c (Treiber, Minusgrade, ungültige Rohwerte, Adress-Basis, Probe) und Integrationstest über das ModBus-Gateway.

## 0.5.3 (22.11.2026)

- **Vertrag `ventilation` 1.1, neue Felder (SUITE.md, Feldregister vom 22.11.2026):** `filterWarningID` zeigt auf die Filterwechsel-Warnung (Pichler: `filterAlarm`; Robatherm und Proxon liefern keine, dort 0). `fanFlowUnit` nennt die Einheit der Volumenströme: `'%'` bei Robatherm, `'m3/h'` bei Pichler, leer bei Geräten ohne Volumenstrom (Proxon). Rein additiv, keine sichtbare Änderung für Nutzer, deshalb kein Eintrag im Neu-Panel.

## 0.5.2 (22.11.2026)

- **Vertrag `ventilation` 1.1 (SUITE.md, Fremdanbindung Punkt 1):** `RLT_GetFunctions`/`RLTGW_GetFunctions` melden sich jetzt selbst als `contractType` `'ventilation'` und tragen zusätzlich `Type` `'ventilation'` als Alias; `contractVersion` ist `'1.1'`. Bisher fehlten beide Felder, obwohl das README `Type=>'ventilation'` nannte. Rein additiv, alle bisherigen Felder und Variablen bleiben unverändert. Keine sichtbare Änderung für Nutzer, deshalb kein Eintrag im Neu-Panel.

## 0.5.1 (06.11.2026)

- **Store-Review-Punkte 27 und 5 (SUITE.md):** Der Abgleich des Ausblende-Stands mit Geschwister-Instanzen läuft nicht mehr in `ApplyChanges()` (beim Neuladen von Modulen können die anderen Instanzen noch fehlen), sondern beim Öffnen des Formulars. Die eigenen Variablenprofile (`RLT.Ppm`, `RLT.Hours`, `RLT.Flow`) werden nur noch beim Anlegen eingestellt, nicht bei jedem Übernehmen. Keine sichtbare Änderung für Nutzer, deshalb kein Eintrag im Neu-Panel.

## 0.5.0 (05.11.2026)

- **Zweisprachig (Deutsch/Englisch).** Formular, Statusmeldungen, Hinweise, Log- und Verbindungstesttexte, Herstellerhinweise, Variablennamen und das Neu-Panel folgen jetzt der Symcon-Sprache. Quelltexte sind englisch, die deutsche Fassung steht in `libs/locale.json`; `php .tools/locale-bauen.php` kopiert sie in die drei Modulordner (Symcon liest `locale.json` nur dort). Englische Dokumentation: `README.en.md`.
- **Übersetzung ohne den Automat-Fehler** (SUITE.md Stolperstein 24): Neue Klasse `RLT_Text` fragt Symcons `Translate()` je Text höchstens einmal je Prozess und merkt sich die Antwort. Formular, Lesezyklus, Vertragsabfrage und Verbindungstest kosten danach keinen Symcon-Aufruf mehr. Treiber und Modbus-Clients haben kein `$this->Translate()`; die Module setzen im Konstruktor den Übersetzer.
- Bestehende Variablen behalten ihren Namen, neue Variablen werden in der Symcon-Sprache angelegt. Idents, Properties, Funktionen und der Vertrag `Type=>'ventilation'` 1.0 bleiben unverändert.
- Deutsch zeigt Dezimalkomma und TT.MM.JJJJ (auch in den Suchtexten der Treiber, vorher mit Punkt), Englisch Dezimalpunkt und ISO-Datum.
- Prüfstand Block 16: jeder Text im Code (auch Treiberdaten, Neu- und Zweck-Texte) steht in `locale.json`, keine verwaisten Einträge, Platzhalter in beiden Sprachen gleich, kein deutscher Text mehr im Code, englisches Formular aller drei Module in mehreren Zuständen ohne deutsche Reste, kein Symcon-Aufruf mehr nach dem ersten Mal, Kennungen unverändert.
- Enthält 0.4.4 („Formular: Aufbau“ in RLTHubDiscovery).

## 0.4.4 (29.10.2026)

- **Verbund-Konvention „Formular: Aufbau“ (SUITE.md 3a):** RLTHubDiscovery bekommt das Panel
  „🪟 Formular: Aufbau“. Je Fachpanel (Suchbereich, Fundliste, RS485) wählt der Nutzer „Aufgeklappt“,
  „Zugeklappt“ oder „🙈 Ausgeblendet“ und eine Position 1–99; kein Haken = Standard, bei Widerspruch gilt
  ausgeblendet vor zugeklappt vor aufgeklappt. Ausgeblendet heißt `visible: false`, die Felder bleiben im
  Formular. Unbekannte Schlüssel fallen auf Standard zurück, der Knopf „Auf Standard zurücksetzen“ gibt
  Klartext zurück und speichert nichts selbst. RLTHub und RLTHubGateway haben nur ein Fachpanel und
  brauchen das Panel nicht.

## 0.4.3 (27.10.2026)

- **Store-Review (SUITE.md 9k, Prüfung vor Stable):** Das Modul schaltete die Archiv-Aufzeichnung der
  Messwerte bei jedem Übernehmen von selbst ein, ohne Option und auch gegen eine vom Nutzer abgeschaltete
  Aufzeichnung. Neu: Option „Messwerte im Archiv aufzeichnen“ (Standard aus) in RLTHub und RLTHubGateway.
  Nur bei gesetztem Haken wird die Aufzeichnung eingeschaltet; das Modul schaltet nie etwas aus. Bestehende
  Aufzeichnungen bleiben unverändert, werden aber nicht mehr von selbst nachgezogen.

## 0.4.2 (17.10.2026)

- **Store-Review (SUITE.md Checkliste Punkt 15, EMS-Fund):** Logging in den Modulklassen lief an drei
  Stellen über das globale `IPS_LogMessage($sender, $text)` (fehlende Verbindung zum ModBus-Gateway,
  nicht erreichbare Anlage). Jetzt `$this->LogMessage($text, KL_WARNING)` — die Instanz wird dabei
  selbst zum Absender, wie es Symcon vorsieht. Keine sichtbare Änderung für Nutzer, nur der Eintrag im
  Symcon-Protokoll ist jetzt korrekt der Instanz zugeordnet. Der Prüfstand lehnt `IPS_LogMessage(` in
  `module.php`/`libs/*.php` jetzt grundsätzlich ab.

## 0.4.1 (28.09.2026)

- **Fix (Fund EMS, Prüfung gegen SUITE.md „Verbund-Verbindungen im Formular sichtbar machen"):** Das
  IP-Adressfeld in RLTHub zeigte nur einen festen Satz ("trägt RLTHubDiscovery automatisch ein"), ohne zu
  prüfen, ob das stimmt. Jetzt eine echte Live-Zeile: `RLTHubDiscovery` bekommt eine neue Abfrage
  `GetLastMatch()`, die genau feststellt, ob die eingetragene Adresse tatsächlich Teil des letzten
  Suchergebnisses war. Nur dann 🔗 (mit Fund-Zeitpunkt und erkanntem Gerätetyp), sonst ✏️ eigene Angabe oder
  ℹ️ wenn noch nichts eingetragen ist — bei automatischer Herkunft steckt das Feld im eingeklappten
  Überschreib-Panel, wie bei Adress-Basis und Suchbereich. Die Zeile folgt sofort, wenn die Adresse von Hand
  geändert wird; RLTHubGateway und RLTHubDiscovery waren von diesem Fund nicht betroffen.

## 0.4.0 (21.09.2026)

- Neuer Gerätetyp **Pichler LG (ES2020-Steuerung, z. B. LG 350)**, Modbus RTU über RLTHubGateway und Modbus TCP
  (ab Firmware 1.6) über RLTHub. Quelle ist die öffentliche Registerliste des Herstellers (Modbus-Liste ES2020,
  Firmware v2.1.0); noch an keiner Anlage geprüft. Temperaturen laut Liste °C = (Rohwert − 1000) ÷ 10, also auch
  unter 0 °C.
- Zuordnungen: Außen-, Zu-, Ablufttemperatur, berechneter Wirkungsgrad, Zu-/Abluft-Volumenstrom (m³/h), Summenstörmeldung.
  Die Filter-Restzeit ist keine Betriebsstundenzahl: `filterRuntimeHoursID` bleibt leer, Restzeit und Filtermeldung sind
  eigene Variablen. CO2 fehlt vorerst (Sensor nicht erkennbar).
- Die Suche erkennt Pichler-Anlagen über das Modellregister (LG 350/450/740) und plausible Temperaturen.
- Neues Variablenprofil `RLT.Flow` (m³/h).

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
- Die 🔗-Zeilen (automatisch übernommen) werden grün dargestellt (`color` = 0x2E8B3D, per
  `UpdateFormField` mitgesetzt), alle anderen Zeilen behalten die Standardfarbe.

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
