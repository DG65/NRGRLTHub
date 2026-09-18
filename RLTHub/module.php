<?php

// ===========================================================================
// RLTHub — generisches Modbus-TCP-Framework für Raumlufttechnische Anlagen
// (RLT/KWL), herstellerübergreifend, analog zu MeterHub/InverterHub/
// ChargerHub. Erster Treiber: Robatherm TrueControl.
//
// Aufbau:
//   RLT_ModbusTcpClient / RLT_ModbusGatewayClient — geteilte Verbindungs-
//     fassade, 1:1 aus MeterHub/InverterHub/ChargerHub übernommen (SUITE.md
//     9j, Dietmars Auftrag 18.09.2026) statt neu erforscht. Beide implemen-
//     tieren RLT_ModbusClientInterface, GetModbusClient() wählt je nach
//     Property 'ConnectionMode' die passende Klasse — Treibercode bleibt
//     unverändert.
//   RLT_VentilationDriverInterface — Vertrag, den jeder RLT-Treiber erfüllt.
//   RLT_RobathermTrueControlDriver — erster Treiber.
//   RLTHub — Hauptmodul, lädt den Treiber laut 'Device'-Property, implemen-
//     tiert den `Type=>'ventilation'`-Feldregister-Vertrag (SUITE.md,
//     19.09.2026, mit EMS abgestimmt) über GetFunctions().
//
// Kickoff-Stand (19.09.2026): Architektur-Skelett + Gateway-Fassade, noch
// OHNE echte Hardware verifiziert (siehe Warnhinweise im Doku-Panel unten
// und die Kommentare am Treiber). Formular-Konvention (News-/Forum-Panel,
// Kategorien) ist bewusst noch nicht ausgerollt — das ist Politur für eine
// spätere Runde, nicht Teil des Architektur-Skeletts.
// ===========================================================================

interface RLT_ModbusClientInterface
{
    public function readHolding($startReg, $count);
    public function readCoils($startReg, $count);
    public function close(): void;
    public function setWordSwap(bool $s);
}

class RLT_ModbusTcpClient implements RLT_ModbusClientInterface
{
    public $host;
    public $port;
    public $unitId;

    public function __construct($host, $port, $unitId)
    {
        $this->host   = $host;
        $this->port   = $port;
        $this->unitId = $unitId;
    }

    public function readHolding($startReg, $count)
    {
        return $this->modbusReadRegisters(0x03, $startReg, $count);
    }

    public function readCoils($startReg, $count)
    {
        return $this->modbusReadCoils($startReg, $count);
    }

    // -----------------------------------------------------------------------
    // Eine Verbindung je Lesezyklus, identisches Muster/Begründung wie
    // MHUB_ModbusTcpClient (MeterHub) — RLTHub ist ebenfalls ein reiner
    // Abfragetakt-Client, keine Einzelverbindung je Register.
    // -----------------------------------------------------------------------

    /** @var resource|null offene Verbindung für die Dauer eines Zyklus */
    private $sock = null;
    private $tid = 0;
    /** Grund des letzten Fehlschlags: 'connect' | 'write' | 'eof' | 'timeout' | 'frame' | '' */
    public $lastError = '';

    public function close(): void
    {
        if ($this->sock) {
            @fclose($this->sock);
        }
        $this->sock = null;
    }

    public function __destruct()
    {
        $this->close();
    }

    private function connect(): bool
    {
        if ($this->sock) {
            return true;
        }
        $s = @fsockopen($this->host, $this->port, $errno, $errstr, 3.0);
        if ($s === false) {
            $this->lastError = 'connect';
            return false;
        }
        stream_set_timeout($s, 3);
        $this->sock = $s;
        return true;
    }

    private function transact(string $pdu): ?string
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $reused = $this->sock !== null;
            if (!$this->connect()) {
                return null;
            }
            $resp = $this->exchange($pdu);
            if ($resp !== null) {
                $this->lastError = '';
                return $resp;
            }
            $this->close();
            if (!$reused || !in_array($this->lastError, ['write', 'eof'], true)) {
                return null;
            }
        }
        return null;
    }

    private function exchange(string $pdu): ?string
    {
        $this->tid = ($this->tid % 65535) + 1;
        $tid = $this->tid;
        $frame = pack('nnn', $tid, 0, strlen($pdu) + 1) . chr($this->unitId) . $pdu;
        if (@fwrite($this->sock, $frame) !== strlen($frame)) {
            $this->lastError = 'write';
            return null;
        }
        $deadline = microtime(true) + 3.0;
        while (true) {
            $head = $this->readExact(7, $deadline);
            if ($head === null) {
                return null;
            }
            $h = unpack('ntid/npid/nlen', $head);
            if ($h['len'] < 2 || $h['len'] > 260) {
                $this->lastError = 'frame';
                return null;
            }
            $body = $this->readExact($h['len'] - 1, $deadline);
            if ($body === null) {
                return null;
            }
            if ($h['tid'] === $tid) {
                return $body;
            }
            // Verspätete Antwort einer früheren Anfrage — verwerfen, weiterlesen.
        }
    }

    private function readExact(int $n, float $deadline): ?string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            if (microtime(true) >= $deadline) {
                $this->lastError = 'timeout';
                return null;
            }
            $chunk = @fread($this->sock, $n - strlen($buf));
            if ($chunk === false || $chunk === '') {
                $meta = @stream_get_meta_data($this->sock);
                $this->lastError = !empty($meta['timed_out']) ? 'timeout' : 'eof';
                return null;
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    private function modbusReadRegisters($fc, $startReg, $count)
    {
        $pdu = $this->transact(pack('Cnn', $fc, $startReg, $count));
        if ($pdu === null || strlen($pdu) < 2) {
            return null;
        }
        $rfc = ord($pdu[0]);
        if ($rfc & 0x80 || $rfc !== $fc) {
            return null;
        }
        $byteCount = ord($pdu[1]);
        $data      = substr($pdu, 2, $byteCount);
        $regs = [];
        for ($i = 0; $i < $count && ($i * 2 + 1) < strlen($data); $i++) {
            $regs[$i] = (ord($data[$i * 2]) << 8) | ord($data[$i * 2 + 1]);
        }
        return $regs;
    }

    // Read Coils (FC 0x01) — Sammelstörung/Watchdog liegen bei Robatherm als
    // BOOL-Coils, nicht als Register. Antwort ist bitgepackt (LSB = erster
    // Coil), nicht wortweise wie bei Registern.
    private function modbusReadCoils($startReg, $count)
    {
        $pdu = $this->transact(pack('Cnn', 0x01, $startReg, $count));
        if ($pdu === null || strlen($pdu) < 2) {
            return null;
        }
        $rfc = ord($pdu[0]);
        if ($rfc & 0x80 || $rfc !== 0x01) {
            return null;
        }
        $byteCount = ord($pdu[1]);
        $data      = substr($pdu, 2, $byteCount);
        $bits = [];
        for ($i = 0; $i < $count; $i++) {
            $byteIdx = intdiv($i, 8);
            if ($byteIdx >= strlen($data)) {
                break;
            }
            $bits[$i] = (ord($data[$byteIdx]) >> ($i % 8)) & 1;
        }
        return $bits;
    }

    // Wortreihenfolge-Umschalter aus MHUB_ModbusTcpClient übernommen (für
    // künftige Treiber mit Float-Messwerten über 2 Register) — der
    // Robatherm-Treiber selbst braucht ihn nicht (reine 16-Bit-INT-Register).
    public $wordSwap = false;
    public function setWordSwap(bool $s) { $this->wordSwap = $s; }
}

// ===========================================================================
// RLT_ModbusGatewayClient — zweiter Verbindungsweg über Symcons natives
// Modbus-Gateway (eingebauter RS485-Port einer Symbox), zusätzlich zum
// direkten fsockopen-Weg (RLT_ModbusTcpClient), NICHT als Ersatz. 1:1
// übernommene, abgestimmte Fassade mit MeterHub/InverterHub/ChargerHub
// (SUITE.md 9j) — dieselbe öffentliche Schnittstelle
// (RLT_ModbusClientInterface) wie RLT_ModbusTcpClient, damit
// GetModbusClient() zwischen beiden Wegen umschalten kann, ohne dass
// Treiber-Code sich unterscheiden muss.
//
// Nutzlastformat gegen den ROHEN Quellcode von Symcons eigenem
// Referenzmodul verifiziert (github.com/symcon/SymconBC, EM24-DIN/
// module.php, siehe MeterHub-Kommentar 18.09.2026): Parent-GUID
// {A5F663AB-C400-4FE5-B207-4D67CC030564}, DataID {E310B701-4AE7-458E-B618-
// EC13A1A6F6A8}. Anfrage ["DataID"=>DataID, "Function"=>FC,
// "Address"=>Register, "Quantity"=>Anzahl, "Data"=>""], Antwort roh (kein
// JSON) — erste 2 Byte (Function+ByteCount) überspringen, Rest
// unpack('n*', ...) als big-endian 16-Bit-Werte (bei Coils bitgepackt
// interpretiert, siehe readCoils()).
//
// Wie eine Instanz ohne automatischen ConnectParent()-Aufruf trotzdem mit
// dem nativen Gateway verbunden wird, ist verbundweit noch offen (siehe
// MeterHub/InverterHub/ChargerHub, SUITE.md 9j) — RLTHub erbt diese offene
// Frage, statt sie hier gesondert zu lösen.
// ===========================================================================
class RLT_ModbusGatewayClient implements RLT_ModbusClientInterface
{
    public const GATEWAY_GUID = '{A5F663AB-C400-4FE5-B207-4D67CC030564}';
    private const DATA_ID = '{E310B701-4AE7-458E-B618-EC13A1A6F6A8}';

    public $unitId;
    public $wordSwap = false;

    /** @var callable string(string $json) — i. d. R. $rltHubInstanz->SendDataToParent(...) */
    private $sendFn;

    public function __construct($unitId, callable $sendFn)
    {
        $this->unitId = $unitId;
        $this->sendFn = $sendFn;
    }

    private function request(int $function, int $address, int $quantity): ?array
    {
        $json = json_encode([
            'DataID'   => self::DATA_ID,
            'Function' => $function,
            'Address'  => $address,
            'Quantity' => $quantity,
            'Data'     => '',
        ]);
        if ($json === false) {
            return null;
        }
        $resp = ($this->sendFn)($json);
        if ($resp === false || $resp === '' || strlen($resp) < 2) {
            return null;
        }
        $raw = unpack('n*', substr($resp, 2));
        $regs = [];
        $i = 0;
        foreach ($raw as $v) {
            $regs[$i++] = $v;
        }
        return $regs;
    }

    public function readHolding($startReg, $count)
    {
        return $this->request(3, $startReg, $count);
    }

    // Coils kommen über denselben Rohkanal wie Register, aber bitgepackt in
    // der Antwort (siehe RLT_ModbusTcpClient::modbusReadCoils) statt
    // wortweise — die geerbte unpack('n*', ...)-Dekodierung aus request()
    // passt hier NICHT, deshalb eigener, einfacherer Weg: ein Byte je bis zu
    // 8 Coils, aus den rohen Antwortbytes direkt entpackt.
    public function readCoils($startReg, $count)
    {
        $json = json_encode([
            'DataID'   => self::DATA_ID,
            'Function' => 1,
            'Address'  => $startReg,
            'Quantity' => $count,
            'Data'     => '',
        ]);
        if ($json === false) {
            return null;
        }
        $resp = ($this->sendFn)($json);
        if ($resp === false || strlen($resp) < 2) {
            return null;
        }
        $data = substr($resp, 2);
        $bits = [];
        for ($i = 0; $i < $count; $i++) {
            $byteIdx = intdiv($i, 8);
            if ($byteIdx >= strlen($data)) {
                break;
            }
            $bits[$i] = (ord($data[$byteIdx]) >> ($i % 8)) & 1;
        }
        return $bits;
    }

    public function close(): void
    {
    }

    public function setWordSwap(bool $s)
    {
        $this->wordSwap = $s;
    }
}

// ---------------------------------------------------------------------------
// RLT_VentilationDriverInterface — Vertrag, den jeder RLT-Treiber erfüllt.
// Die Feldliste von getBaseVars() ist bewusst 1:1 an den mit EMS
// abgestimmten `Type=>'ventilation'`-Feldregister angelehnt (SUITE.md,
// 19.09.2026) — jeder künftige Zweit-/Dritthersteller-Treiber füllt
// dieselben Idents, lässt aber, was seine Datenquelle nicht hergibt,
// einfach ungeschrieben (Basiswert 0/false bleibt stehen).
// ---------------------------------------------------------------------------
interface RLT_VentilationDriverInterface
{
    /** [ident, caption, type(F/I/B), profile, archive] je Basisvariable. */
    public function getBaseVars(): array;

    /** Liest einen vollständigen Zyklus, schreibt über $hub->SetVar*(). Rückgabe: Zyklus ohne Fehler? */
    public function readValues($mb, $hub): bool;
}

// ===========================================================================
// RLT_RobathermTrueControlDriver — erster Treiber, Robatherm TrueControl DDC.
//
// ⚠️ Registeradressen stammen aus EINER konkreten Modbusdatenpunktliste
// (Auftragsnummer 102751.1, Kunde Otto Zepp, TrueControl Version 1.25,
// Januar 2019) — TrueControl-DDCs werden laut Robatherm je Projekt
// individuell parametriert, das ist KEIN fixer Firmware-Standard wie bei
// einem Shelly o. ä. Vor Produktiveinsatz an einer ANDEREN Anlage
// unbedingt gegen deren eigene Modbusdatenpunktliste prüfen (SUITE.md
// „Registerkarten: erst messen, dann glauben" — Shelly Pro 3EM dort ist
// die Referenzlehre für genau dieses Risiko).
//
// ⚠️ Skalierung unverifiziert: Temperaturen werden mit Faktor 10 gelesen
// (Rohwert ÷ 10 = °C, verbreitete DDC-Konvention) — die PDF-Datenpunktliste
// dokumentiert diesen Faktor selbst NICHT, nur den Registertyp „INT". CO-
// Luftqualität, Ventilator-Volumenstrom-Faktor und Filter-Betriebsstunden
// werden als Rohwert ohne Skalierung übernommen (bei den Betriebsstunden-
// Registern existiert laut Datenpunktliste ein zusätzliches „Faktor"-
// Register direkt daneben, dessen Bedeutung dort nicht erklärt wird — bis
// zur Klärung bewusst NICHT einbezogen). Alle drei Annahmen müssen an
// echter Hardware verifiziert werden.
//
// ⚠️ Adress-Basis unverifiziert: Die Datenpunktliste zählt Register/Coils
// ab 1 (Modicon-Konvention, wie „Register 1" für die PLC-Uhr). Ob die
// TrueControl-Modbus-Implementierung auf der Leitung ebenfalls ab 1 zählt
// oder die Dokuadresse um 1 zu verringern ist (Wire-Adresse = Dokuadresse
// − 1, der klassische Modicon-Stolperstein), ist unklar — deshalb als
// Formularschalter 'AddressOneBased' ausgelagert (Default: an, Dokuadresse
// wird intern −1 gerechnet), statt eine der beiden Annahmen fest zu
// verdrahten.
// ===========================================================================
class RLT_RobathermTrueControlDriver implements RLT_VentilationDriverInterface
{
    private const REG_OUTSIDE_TEMP   = 2001; // Messwert Temperatur Außenluft
    private const REG_SUPPLY_TEMP    = 2019; // Messwert Temperatur Zuluft
    private const REG_EXTRACT_TEMP   = 2049; // Messwert Temperatur Abluft
    private const REG_CO_PPM         = 2061; // Messwert Luftqualität CO
    private const REG_FILTER1_HOURS  = 2737; // Betriebsstundenzähler Filter 1
    private const REG_FAN1_FLOW      = 2352; // Messwert Ventilator 1 Volumenstrom Faktor
    private const REG_FAN2_FLOW      = 2354; // Messwert Ventilator 2 Volumenstrom Faktor
    private const REG_SYSTEM_SWITCH  = 1993; // Anlagenschalter RLT-Gerät (nur gelesen, kein Schreibzugriff in v0.1.0)
    private const COIL_FAULT_SUMMARY = 6;    // Sammelstörung
    private const COIL_WATCHDOG      = 1;    // TrueControl Watch Dog

    public function getBaseVars(): array
    {
        return [
            // ident                     caption                                                    type profile        archive
            ['outsideTemp',              'Temperatur Außenluft',                                     'F', 'NRG.Celsius', true],
            ['supplyTemp',               'Temperatur Zuluft',                                        'F', 'NRG.Celsius', true],
            ['extractTemp',              'Temperatur Abluft',                                        'F', 'NRG.Celsius', true],
            ['co2',                      'Luftqualität (CO)',                                        'F', 'RLT.Ppm',     true],
            ['heatRecoveryEfficiency',   'WRG-Wirkungsgrad (berechnet)',                              'F', 'NRG.Percent', true],
            ['filterRuntimeHours',       'Betriebsstunden Filter 1',                                 'F', 'RLT.Hours',   true],
            ['fan1Flow',                 'Ventilator 1 Volumenstrom',                                'F', 'NRG.Percent', true],
            ['fan2Flow',                 'Ventilator 2 Volumenstrom',                                'F', 'NRG.Percent', true],
            ['faultSummary',             'Sammelstörung',                                            'B', '~Alert',      false],
            ['systemSwitch',             'Anlagenschalter',                                          'B', '',            false],
            ['watchdogOk',               'TrueControl-Watchdog quittiert (kein Vertragsfeld, Diagnose)', 'B', '',        false],
            ['lastSeenAt',               'Zuletzt aktualisiert',                                     'I', '~UnixTimestamp', false],
        ];
    }

    public function readValues($mb, $hub): bool
    {
        $ok = true;

        $outsideRaw = $mb->readHolding($hub->WireAddress(self::REG_OUTSIDE_TEMP), 1);
        $supplyRaw  = $mb->readHolding($hub->WireAddress(self::REG_SUPPLY_TEMP), 1);
        $extractRaw = $mb->readHolding($hub->WireAddress(self::REG_EXTRACT_TEMP), 1);

        $outsideTemp = $outsideRaw !== null ? self::regToTemp($outsideRaw[0]) : null;
        $supplyTemp  = $supplyRaw  !== null ? self::regToTemp($supplyRaw[0])  : null;
        $extractTemp = $extractRaw !== null ? self::regToTemp($extractRaw[0]) : null;

        if ($outsideTemp !== null) {
            $hub->SetVarFloat('outsideTemp', $outsideTemp);
        } else {
            $ok = false;
        }
        if ($supplyTemp !== null) {
            $hub->SetVarFloat('supplyTemp', $supplyTemp);
        } else {
            $ok = false;
        }
        if ($extractTemp !== null) {
            $hub->SetVarFloat('extractTemp', $extractTemp);
        } else {
            $ok = false;
        }

        // WRG-Wirkungsgrad: Standardformel η = (Zuluft − Außenluft) /
        // (Abluft − Außenluft) — nicht vom Gerät geliefert, sondern aus den
        // drei ohnehin gelesenen Temperaturen berechnet (kickoff-Notiz,
        // SUITE.md-Vertragsfeld `heatRecoveryEfficiencyID`). Nur bei
        // hinreichendem Abstand Abluft/Außenluft auswertbar (sonst
        // numerisch instabil, z. B. bei stehender Anlage) — dann 0.
        if ($outsideTemp !== null && $supplyTemp !== null && $extractTemp !== null) {
            $denom = $extractTemp - $outsideTemp;
            $eff = (abs($denom) > 0.5) ? (($supplyTemp - $outsideTemp) / $denom) * 100.0 : 0.0;
            $hub->SetVarFloat('heatRecoveryEfficiency', max(0.0, min(100.0, $eff)));
        }

        $co = $mb->readHolding($hub->WireAddress(self::REG_CO_PPM), 1);
        if ($co !== null) {
            $hub->SetVarFloat('co2', (float) self::regToInt16($co[0]));
        } else {
            $ok = false;
        }

        $filter1 = $mb->readHolding($hub->WireAddress(self::REG_FILTER1_HOURS), 1);
        if ($filter1 !== null) {
            $hub->SetVarFloat('filterRuntimeHours', (float) self::regToInt16($filter1[0]));
        } else {
            $ok = false;
        }

        $fan1 = $mb->readHolding($hub->WireAddress(self::REG_FAN1_FLOW), 1);
        if ($fan1 !== null) {
            $hub->SetVarFloat('fan1Flow', (float) self::regToInt16($fan1[0]));
        } else {
            $ok = false;
        }

        $fan2 = $mb->readHolding($hub->WireAddress(self::REG_FAN2_FLOW), 1);
        if ($fan2 !== null) {
            $hub->SetVarFloat('fan2Flow', (float) self::regToInt16($fan2[0]));
        } else {
            $ok = false;
        }

        // Anlagenschalter ist rein informativ (v0.1.0 ist Monitoring-only,
        // siehe Kickoff-Notiz „EMS ist reine Konsument-Rolle... zunächst
        // nur lesend") — Fehler hier lassen $ok bewusst unangetastet.
        $sys = $mb->readHolding($hub->WireAddress(self::REG_SYSTEM_SWITCH), 1);
        if ($sys !== null) {
            $hub->SetVarBoolean('systemSwitch', self::regToInt16($sys[0]) !== 0);
        }

        $fault = $mb->readCoils($hub->WireAddress(self::COIL_FAULT_SUMMARY), 1);
        if ($fault !== null) {
            $hub->SetVarBoolean('faultSummary', (bool) ($fault[0] ?? 0));
        } else {
            $ok = false;
        }

        // Watchdog ebenfalls informativ, kein Vertragsfeld (SUITE.md-
        // Entscheidung 19.09.2026: erst mit zweitem Hersteller-Treiber
        // aufnehmen).
        $wd = $mb->readCoils($hub->WireAddress(self::COIL_WATCHDOG), 1);
        if ($wd !== null) {
            $hub->SetVarBoolean('watchdogOk', (bool) ($wd[0] ?? 0));
        }

        return $ok;
    }

    /** Rohregister (0..65535) -> signed 16-bit, wie es die Datenpunktliste als „INT" bezeichnet. */
    private static function regToInt16(int $raw): int
    {
        $v = $raw & 0xFFFF;
        return $v > 32767 ? $v - 65536 : $v;
    }

    /** Signed-16-Bit-Rohwert -> °C, Faktor 10 (unverifiziert, siehe Klassenkopf). */
    private static function regToTemp(int $raw): float
    {
        return self::regToInt16($raw) / 10.0;
    }
}

// ===========================================================================
// RLTHub — Hauptmodul.
// ===========================================================================
class RLTHub extends IPSModule
{
    private const DRIVERS = [
        'robatherm_truecontrol' => ['class' => 'RLT_RobathermTrueControlDriver', 'label' => 'Robatherm TrueControl'],
    ];

    private const CONTRACT_VERSION = '1.0';

    private $driver = null;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyBoolean('Active', true);
        $this->RegisterPropertyString('Location', '');
        $this->RegisterPropertyString('Device', 'robatherm_truecontrol');

        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', 502);
        $this->RegisterPropertyInteger('UnitId', 1);
        // Verbindungsweg (SUITE.md 9j) — direkt (fsockopen) oder über
        // Symcons natives Modbus-Gateway (eingebauter Symbox-RS485-Port).
        // ConnectParent() hier bewusst NICHT aufgerufen (siehe
        // RLT_ModbusGatewayClient-Klassenkopf) — derselbe Grund wie bei
        // MeterHub/InverterHub/ChargerHub: würde laut SDK-Doku bei Bedarf
        // selbst einen Parent anlegen und könnte so ungefragt jede
        // bestehende Direktverbindungs-Instanz umhängen.
        $this->RegisterPropertyString('ConnectionMode', 'direct');
        $this->RegisterPropertyBoolean('WordSwap', false);
        $this->RegisterPropertyBoolean('AddressOneBased', true);

        $this->RegisterPropertyInteger('PollInterval', 60);

        $this->RegisterAttributeInteger('LastSeenAt', 0);

        $this->RegisterTimer('ReadValuesTimer', 0, 'RLT_ReadValues($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->CreateProfiles();

        $driver = $this->GetDriver();
        $pos = 0;
        foreach ($driver->getBaseVars() as $def) {
            $this->RegisterVar($def, $pos++);
        }

        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetTimerInterval('ReadValuesTimer', 0);
            $this->SetStatus(104);
            return;
        }

        $interval = max(5, $this->ReadPropertyInteger('PollInterval'));
        $this->SetTimerInterval('ReadValuesTimer', $interval * 1000);
        $this->SetStatus(102);
    }

    public function GetConfigurationForm()
    {
        $connectionMode = $this->ReadPropertyString('ConnectionMode');
        $isGateway = $connectionMode === 'gateway';

        $deviceOptions = [];
        foreach (self::DRIVERS as $key => $def) {
            $deviceOptions[] = ['caption' => $def['label'], 'value' => $key];
        }

        $form = [
            'elements' => [
                [
                    'type'    => 'Label',
                    'caption' => 'RLTHub — Raumlufttechnische Anlagen (RLT/KWL), herstellerübergreifend über Modbus TCP.',
                ],
                ['type' => 'CheckBox', 'name' => 'Active', 'caption' => 'Kommunikation aktiv'],
                ['type' => 'ValidationTextBox', 'name' => 'Location', 'caption' => 'Bezeichnung / Standort (optional)'],
                ['type' => 'Select', 'name' => 'Device', 'caption' => 'Gerätetyp', 'options' => $deviceOptions],
                [
                    'type'     => 'ExpansionPanel',
                    'caption'  => '🔌 Verbindung',
                    'expanded' => true,
                    'items'    => [
                        [
                            'type'     => 'Select',
                            'name'     => 'ConnectionMode',
                            'caption'  => 'Verbindungsweg',
                            'options'  => [
                                ['caption' => 'Direkt (eigene Verbindung)', 'value' => 'direct'],
                                ['caption' => 'Symbox-Gateway (eingebauter RS485-Port)', 'value' => 'gateway'],
                            ],
                            'onChange' => 'RLT_OnChangeConnectionMode($id, $ConnectionMode);',
                        ],
                        [
                            'type' => 'Label', 'name' => 'ConnectionModeGatewayWarning', 'visible' => $isGateway,
                            'caption' => '⚠️ Dieser Verbindungsweg ist neu und ungetestet (SUITE.md 9j, Fassade aus MeterHub/InverterHub/ChargerHub übernommen) — lesend am Rohcode von Symcons eigenem Referenzmodul verifiziert, aber noch ohne echte Symbox-Hardware geprüft. Wie diese Instanz mit einer nativen Modbus-Gateway-Instanz verbunden wird, ist noch offen — ohne Verbindung liefert dieser Modus keine Werte.',
                        ],
                        [
                            'type' => 'Label', 'name' => 'ConnectionModeGatewayUnitIdHint', 'visible' => $isGateway,
                            'caption' => 'ℹ️ Host/Port/Unit-ID entfallen in diesem Modus — die Unit-ID wird stattdessen an der übergeordneten Modbus-Gateway-Instanz eingestellt (deren Property „DeviceID"), an die diese Instanz im Objektbaum gehängt wird.',
                        ],
                        [
                            'type' => 'ValidationTextBox', 'name' => 'Host', 'visible' => !$isGateway, 'caption' => 'IP-Adresse',
                            'validate' => $isGateway ? '' : '^\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}$',
                        ],
                        ['type' => 'NumberSpinner', 'name' => 'Port', 'visible' => !$isGateway, 'caption' => 'TCP-Port', 'minimum' => 1, 'maximum' => 65535],
                        ['type' => 'NumberSpinner', 'name' => 'UnitId', 'visible' => !$isGateway, 'caption' => 'Unit ID', 'minimum' => 1, 'maximum' => 247],
                        ['type' => 'CheckBox', 'name' => 'WordSwap', 'caption' => 'Wortreihenfolge tauschen (CDAB) — nur bei Bedarf, aktueller Treiber nutzt reine 16-Bit-Register'],
                        [
                            'type' => 'CheckBox', 'name' => 'AddressOneBased',
                            'caption' => 'Registeradressen aus der Herstellerdoku sind 1-basiert (Modicon-Konvention) — unverifiziert, siehe „Dokumentation & Hilfe"',
                        ],
                        ['type' => 'NumberSpinner', 'name' => 'PollInterval', 'caption' => 'Abfragetakt', 'minimum' => 5, 'maximum' => 3600, 'suffix' => ' s'],
                        ['type' => 'Button', 'name' => 'TestConnectionButton', 'caption' => '🔎  Verbindung jetzt testen', 'onClick' => 'echo RLT_TestConnection($id);'],
                        ['type' => 'Label', 'name' => 'TestConnectionResult', 'caption' => ''],
                    ],
                ],
                [
                    'type'     => 'ExpansionPanel',
                    'caption'  => '📖 Dokumentation & Hilfe',
                    'expanded' => false,
                    'items'    => [
                        ['type' => 'Label', 'caption' => 'RLTHub 0.1.0 — Architektur-Skelett aus dem Kickoff (19.09.2026), noch ohne echte Hardware verifiziert.'],
                        [
                            'type' => 'Label',
                            'caption' => 'Der Robatherm-TrueControl-Treiber übernimmt Registeradressen aus EINER konkreten Anlagenkonfiguration (Auftragsnummer 102751.1). TrueControl-Register werden je Projekt individuell in der DDC parametriert und können bei anderen Anlagen abweichen. Vor Produktiveinsatz unbedingt gegen die eigene Modbus-Datenpunktliste des Anlagenherstellers prüfen.',
                        ],
                        [
                            'type' => 'Label',
                            'caption' => 'Temperaturen werden mit Faktor 10 interpretiert (Register-Rohwert ÷ 10 = °C) — verbreitete, aber hier nicht herstellerseitig dokumentierte DDC-Konvention. Luftqualität (CO) und Ventilator-Volumenstrom-Faktor werden als Rohwert ohne Skalierung übernommen. Alle drei Annahmen sind unverifiziert.',
                        ],
                        [
                            'type' => 'Label',
                            'caption' => 'WRG-Wirkungsgrad wird aus Außenluft-/Zuluft-/Ablufttemperatur berechnet (η = (Zuluft − Außenluft) / (Abluft − Außenluft)), nicht vom Gerät geliefert.',
                        ],
                        [
                            'type' => 'Label',
                            'caption' => 'Cross-Modul-Vertrag: liefert Type=>\'ventilation\' (RLT_GetFunctions, contractVersion 1.0, mit EMS abgestimmt, siehe SUITE.md). EMS ist reine Konsument-Rolle, zunächst nur lesend — keine aktive Steuerung in dieser Version.',
                        ],
                    ],
                ],
            ],
            'status' => [
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'Inaktiv (Kommunikation deaktiviert).'],
                ['code' => 102, 'icon' => 'active',   'caption' => 'Aktiv.'],
                ['code' => 201, 'icon' => 'error',    'caption' => 'Verbindungsfehler – Anlage nicht erreichbar.'],
            ],
        ];

        return json_encode($form);
    }

    // Verbindungsweg-Feldsichtbarkeit live umschalten — dasselbe onChange+
    // UpdateFormField-Muster wie bei MeterHub/InverterHub/ChargerHub
    // (PropertyCondition kennt laut Doku nur einen einzelnen Wert, keinen
    // Negations-/Array-Fall).
    public function OnChangeConnectionMode(string $connectionMode)
    {
        $isGateway = $connectionMode === 'gateway';
        $this->UpdateFormField('ConnectionModeGatewayWarning', 'visible', $isGateway);
        $this->UpdateFormField('ConnectionModeGatewayUnitIdHint', 'visible', $isGateway);
        $this->UpdateFormField('Host', 'visible', !$isGateway);
        $this->UpdateFormField('Host', 'validate', $isGateway ? '' : '^\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}$');
        $this->UpdateFormField('Port', 'visible', !$isGateway);
        $this->UpdateFormField('UnitId', 'visible', !$isGateway);
    }

    // -----------------------------------------------------------------------
    // Lesezyklus
    // -----------------------------------------------------------------------

    public function ReadValues()
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return;
        }
        $driver = $this->GetDriver();
        $mb = $this->GetModbusClient();
        $ok = $driver->readValues($mb, $this);
        $mb->close();

        if ($ok) {
            $now = time();
            $this->WriteAttributeInteger('LastSeenAt', $now);
            $this->SetVarInteger('lastSeenAt', $now);
            $this->SetStatus(102);
        } else {
            $this->SetStatus(201);
        }
    }

    public function TestConnection(): string
    {
        $driver = $this->GetDriver();
        $mb = $this->GetModbusClient();
        $ok = $driver->readValues($mb, $this);
        $mb->close();

        if ($ok) {
            $now = time();
            $this->WriteAttributeInteger('LastSeenAt', $now);
            $this->SetVarInteger('lastSeenAt', $now);
            $this->SetStatus(102);
            return '✅ Verbindung erfolgreich, Werte aktualisiert.';
        }
        $this->SetStatus(201);
        return '❌ Verbindung fehlgeschlagen oder keine gültige Antwort — Host/Port/Unit-ID/Verbindungsweg/Adress-Basis prüfen.';
    }

    // -----------------------------------------------------------------------
    // Cross-Modul-Vertrag `Type=>'ventilation'` (SUITE.md, mit EMS
    // abgestimmt 19.09.2026). Ein Eintrag je Instanz — Liste, damit eine
    // spätere Aufteilung (z. B. mehrere RLT-Geräte je Instanz) die Signatur
    // nicht bricht, analog zum Muster bei MHUB_GetFunctions/HEISHA_-Get-
    // Functions.
    // -----------------------------------------------------------------------

    public function GetFunctions(): array
    {
        return [[
            'contractVersion'          => self::CONTRACT_VERSION,
            'instanceID'                => $this->InstanceID,
            'Caption'                   => $this->ReadPropertyString('Location') !== '' ? $this->ReadPropertyString('Location') : IPS_GetName($this->InstanceID),
            'Measured'                  => true,
            'unit'                      => 'ppm (CO)',
            'reachable'                 => $this->GetStatus() === 102,
            'outsideTempID'             => $this->VarID('outsideTemp'),
            'supplyTempID'              => $this->VarID('supplyTemp'),
            'extractTempID'             => $this->VarID('extractTemp'),
            'co2ID'                     => $this->VarID('co2'),
            'heatRecoveryEfficiencyID'  => $this->VarID('heatRecoveryEfficiency'),
            'filterRuntimeHoursID'      => $this->VarID('filterRuntimeHours'),
            'fan1FlowID'                => $this->VarID('fan1Flow'),
            'fan2FlowID'                => $this->VarID('fan2Flow'),
            'faultSummaryID'            => $this->VarID('faultSummary'),
            'lastSeenAt'                => $this->ReadAttributeInteger('LastSeenAt'),
            'pollInterval'              => $this->ReadPropertyInteger('PollInterval'),
        ]];
    }

    // -----------------------------------------------------------------------
    // Variable setzen (public, damit Treiber sie via $hub->... aufrufen können)
    // -----------------------------------------------------------------------

    public function SetVarFloat(string $ident, float $value): void
    {
        $vid = $this->VarID($ident);
        if ($vid) {
            SetValueFloat($vid, is_finite($value) ? $value : 0.0);
        }
    }

    public function SetVarInteger(string $ident, int $value): void
    {
        $vid = $this->VarID($ident);
        if ($vid) {
            SetValueInteger($vid, $value);
        }
    }

    public function SetVarBoolean(string $ident, bool $value): void
    {
        $vid = $this->VarID($ident);
        if ($vid) {
            SetValueBoolean($vid, $value);
        }
    }

    /** Dokuadresse -> Wire-Adresse, siehe Property 'AddressOneBased' (Klassenkopf RLT_RobathermTrueControlDriver). */
    public function WireAddress(int $documented): int
    {
        return $this->ReadPropertyBoolean('AddressOneBased') ? $documented - 1 : $documented;
    }

    private function VarID(string $ident): int
    {
        $vid = @IPS_GetObjectIDByIdent($this->InstanceID, $ident);
        return $vid !== false ? $vid : 0;
    }

    // -----------------------------------------------------------------------
    // Treiber-/Transport-Auswahl
    // -----------------------------------------------------------------------

    private function GetDriver(): RLT_VentilationDriverInterface
    {
        if ($this->driver !== null) {
            return $this->driver;
        }
        $key = $this->ReadPropertyString('Device');
        $class = self::DRIVERS[$key]['class'] ?? self::DRIVERS['robatherm_truecontrol']['class'];
        $this->driver = new $class();
        return $this->driver;
    }

    private function GetModbusClient(): RLT_ModbusClientInterface
    {
        if ($this->ReadPropertyString('ConnectionMode') === 'gateway') {
            $mb = new RLT_ModbusGatewayClient(
                $this->ReadPropertyInteger('UnitId'),
                function (string $json): string { return $this->SendDataToParent($json); }
            );
        } else {
            $mb = new RLT_ModbusTcpClient(
                $this->ReadPropertyString('Host'),
                $this->ReadPropertyInteger('Port'),
                $this->ReadPropertyInteger('UnitId')
            );
        }
        $mb->setWordSwap($this->ReadPropertyBoolean('WordSwap'));
        return $mb;
    }

    // -----------------------------------------------------------------------
    // Variablen-/Profilverwaltung
    // -----------------------------------------------------------------------

    private function RegisterVar(array $def, int $pos): int
    {
        [$ident, $caption, $type, $profile, $archive] = $def;
        switch ($type) {
            case 'F':
                $this->RegisterVariableFloat($ident, $caption, $profile, $pos);
                break;
            case 'I':
                $this->RegisterVariableInteger($ident, $caption, $profile, $pos);
                break;
            case 'B':
                $this->RegisterVariableBoolean($ident, $caption, $profile, $pos);
                break;
            default:
                return 0;
        }
        $vid = $this->VarID($ident);
        if ($archive && $vid) {
            $this->SetArchive($vid);
        }
        return $vid;
    }

    private function SetArchive(int $vid): void
    {
        $archiveIDs = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        if (count($archiveIDs) > 0) {
            AC_SetLoggingStatus($archiveIDs[0], $vid, true);
            AC_SetAggregationType($archiveIDs[0], $vid, 0);
        }
    }

    private function CreateProfiles(): void
    {
        // Gemeinsame NRG-Stack-Profile (Verbund-Konvention) — nur anlegen,
        // wenn sie fehlen, RLTHub ist nicht Eigentümer (siehe MeterHub-
        // Vorbild ensureSharedProfile()).
        $this->ensureSharedProfile('NRG.Celsius', ' °C', 1, 'Temperature');
        $this->ensureSharedProfile('NRG.Percent', ' %', 1, '');

        // Modulspezifische Profile (RLTHub bleibt Eigentümer).
        $this->ensureProfile('RLT.Ppm', ' ppm', 0, '');
        $this->ensureProfile('RLT.Hours', ' h', 0, '');
    }

    private function ensureProfile(string $name, string $suffix, int $digits, string $icon): void
    {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, VARIABLETYPE_FLOAT);
        }
        IPS_SetVariableProfileDigits($name, $digits);
        IPS_SetVariableProfileText($name, '', $suffix);
        if ($icon !== '') {
            IPS_SetVariableProfileIcon($name, $icon);
        }
    }

    private function ensureSharedProfile(string $name, string $suffix, int $digits, string $icon): void
    {
        if (IPS_VariableProfileExists($name)) {
            return;
        }
        IPS_CreateVariableProfile($name, VARIABLETYPE_FLOAT);
        IPS_SetVariableProfileDigits($name, $digits);
        IPS_SetVariableProfileText($name, '', $suffix);
        if ($icon !== '') {
            IPS_SetVariableProfileIcon($name, $icon);
        }
    }
}
