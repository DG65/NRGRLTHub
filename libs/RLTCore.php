<?php

// ===========================================================================
// RLTHub — gemeinsamer Kern für RLTHub (Modbus TCP), RLTHubGateway (Symcons
// natives ModBus-Gateway, RS485/RTU) und RLTHubDiscovery.
//
//   RLT_ModbusTcpClient / RLT_ModbusGatewayClient — Verbindungsfassade
//     (SUITE.md 9j), beide implementieren RLT_ModbusClientInterface.
//   RLT_VentilationDriverInterface — Vertrag jedes Hersteller-Treibers.
//   RLT_RobathermTrueControlDriver, RLT_ProxonFwtDriver — Treiber.
//   RLT_Drivers — Treiberliste (Bezeichnung, Transport, Hinweis).
//   RLT_HubTrait — gemeinsame Instanzlogik von RLTHub und RLTHubGateway,
//     implementiert den `Type=>'ventilation'`-Vertrag (SUITE.md, 1.0).
//
// Alle Registerkarten sind bewusst mit ihrer Herkunft und ihrem
// Verifikationsstand markiert (SUITE.md „Registerkarten: erst messen, dann
// glauben").
// ===========================================================================

require_once __DIR__ . '/RLTPanels.php';

interface RLT_ModbusClientInterface
{
    /** Function 3. Liefert 0-indiziertes Array der 16-Bit-Rohwerte oder null. */
    public function readHolding($startReg, $count);

    /** Function 4. */
    public function readInput($startReg, $count);

    /** Function 1. Liefert 0-indiziertes Array 0/1 oder null. */
    public function readCoils($startReg, $count);

    public function close(): void;
}

class RLT_ModbusTcpClient implements RLT_ModbusClientInterface
{
    public $host;
    public $port;
    public $unitId;

    /** @var resource|null offene Verbindung für die Dauer eines Zyklus */
    private $sock = null;
    private $tid = 0;
    /** 'connect' | 'write' | 'eof' | 'timeout' | 'frame' | '' */
    public $lastError = '';

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

    public function readInput($startReg, $count)
    {
        return $this->modbusReadRegisters(0x04, $startReg, $count);
    }

    public function readCoils($startReg, $count)
    {
        $pdu = $this->transact(pack('Cnn', 0x01, $startReg, $count));
        if ($pdu === null || strlen($pdu) < 2) {
            return null;
        }
        $rfc = ord($pdu[0]);
        if ($rfc & 0x80 || $rfc !== 0x01) {
            return null;
        }
        return RLT_Decode::unpackBits(substr($pdu, 2, ord($pdu[1])), $count);
    }

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

    // Eine Verbindung je Lesezyklus, ein zweiter Versuch nur bei
    // weggebrochener, schon benutzter Verbindung — gleiche Begründung und
    // Logik wie MHUB_ModbusTcpClient (MeterHub).
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
        $data = substr($pdu, 2, ord($pdu[1]));
        $regs = [];
        for ($i = 0; $i < $count && ($i * 2 + 1) < strlen($data); $i++) {
            $regs[$i] = (ord($data[$i * 2]) << 8) | ord($data[$i * 2 + 1]);
        }
        return $regs;
    }
}

// ===========================================================================
// RLT_ModbusGatewayClient — Verbindung über Symcons NATIVES ModBus-Gateway
// (RS485/Modbus RTU an einem seriellen Anschluss, z. B. USB-Dongle am
// Symcon-Host). Nutzlastformat gegen den Rohcode von Symcons Referenzmodul
// (symcon/SymconBC, EM24-DIN) verifiziert und von WPModbusHubGateway am
// 19.09.2026 an echter Hardware (Proxon T300) bestätigt: Anfrage
// {DataID {E310B701-...}, Function, Address, Quantity, Data ""}, Antwort roh:
// 2 Kopfbyte (Function + Bytezahl), danach 16-Bit-Register big-endian.
//
// Funktioniert NUR in einer Instanz, die ein ModBus-Gateway als Parent hat
// (Kind-Modul RLTHubGateway mit parentRequirements) — SendDataToParent()
// ohne Parent liefert nichts. Die Slave-ID sitzt am Gateway ("DeviceID").
// Coils sind hier nicht hardwarebestätigt (das Referenzmodul liest keine).
// ===========================================================================
class RLT_ModbusGatewayClient implements RLT_ModbusClientInterface
{
    public const GATEWAY_GUID = '{A5F663AB-C400-4FE5-B207-4D67CC030564}';
    public const DATA_ID = '{E310B701-4AE7-458E-B618-EC13A1A6F6A8}';
    // Nach so vielen Anfragen in Folge ohne jede Antwort werden die übrigen
    // Anfragen des Zyklus übersprungen — das Gateway wartet je Anfrage
    // (Standard 5 s), ein stummer Regler würde sonst den ganzen Zyklus blockieren.
    private const MAX_CONSECUTIVE_NO_REPLY = 2;

    /** @var callable string(string $json) — liefert die rohe Antwort oder false */
    private $sendFn;
    private $noReplyStreak = 0;
    public $lastError = '';

    public function __construct(callable $sendFn)
    {
        $this->sendFn = $sendFn;
    }

    public function readHolding($startReg, $count)
    {
        $data = $this->request(3, (int)$startReg, (int)$count);
        return $data === null ? null : RLT_Decode::unpackRegisters($data, (int)$count);
    }

    public function readInput($startReg, $count)
    {
        $data = $this->request(4, (int)$startReg, (int)$count);
        return $data === null ? null : RLT_Decode::unpackRegisters($data, (int)$count);
    }

    public function readCoils($startReg, $count)
    {
        $data = $this->request(1, (int)$startReg, (int)$count);
        return $data === null ? null : RLT_Decode::unpackBits($data, (int)$count);
    }

    public function close(): void
    {
    }

    /** Nutzdaten der Antwort (ohne die 2 Kopfbyte) oder null. */
    private function request(int $function, int $address, int $quantity): ?string
    {
        if ($this->noReplyStreak >= self::MAX_CONSECUTIVE_NO_REPLY) {
            $this->lastError = 'übersprungen (Regler antwortet nicht)';
            return null;
        }
        $json = json_encode([
            'DataID'   => self::DATA_ID,
            'Function' => $function,
            'Address'  => $address,
            'Quantity' => $quantity,
            'Data'     => '',
        ]);
        if ($json === false) {
            $this->lastError = 'Anfrage nicht kodierbar';
            return null;
        }
        $resp = ($this->sendFn)($json);
        if (!is_string($resp) || strlen($resp) < 2) {
            $this->noReplyStreak++;
            $this->lastError = 'keine Antwort';
            return null;
        }
        // Modbus-Exception (Function | 0x80): gültige Antwort, aber kein Wert.
        if ((ord($resp[0]) & 0x80) !== 0) {
            $this->noReplyStreak = 0;
            $this->lastError = 'Modbus-Exception ' . ord($resp[1]);
            return null;
        }
        $this->noReplyStreak = 0;
        $this->lastError = '';
        return substr($resp, 2);
    }
}

class RLT_Ui
{
    /** Farbe der „automatisch übernommen“-Zeilen (SUITE.md 21.09.2026), Label-Eigenschaft `color`. */
    public const AUTO_GREEN = 0x2E8B3D;

    /** Grün für 🔗-Zeilen, sonst -1 (= Standardfarbe). */
    public static function color(string $caption): int
    {
        return strpos($caption, '🔗') === 0 ? self::AUTO_GREEN : -1;
    }

    /** Label-Element; die Farbe nur setzen, wenn sie vom Standard abweicht. */
    public static function line(string $name, string $caption): array
    {
        $el = ['type' => 'Label', 'name' => $name, 'caption' => $caption];
        if (self::color($caption) !== -1) {
            $el['color'] = self::color($caption);
        }
        return $el;
    }
}

class RLT_Decode
{
    /** Rohdaten (big-endian 16 Bit) -> 0-indiziertes Registerarray. */
    public static function unpackRegisters(string $data, int $count): array
    {
        $regs = [];
        for ($i = 0; $i < $count && ($i * 2 + 1) < strlen($data); $i++) {
            $regs[$i] = (ord($data[$i * 2]) << 8) | ord($data[$i * 2 + 1]);
        }
        return $regs;
    }

    /** Bitgepackte Coil-Antwort (LSB = erster Coil) -> 0-indiziertes 0/1-Array. */
    public static function unpackBits(string $data, int $count): array
    {
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

    /** Rohregister (0..65535) -> signed 16 Bit. */
    public static function int16(int $raw): int
    {
        $v = $raw & 0xFFFF;
        return $v > 32767 ? $v - 65536 : $v;
    }

    /**
     * WRG-Wirkungsgrad in % aus Außen-/Zu-/Ablufttemperatur:
     * η = (Zuluft − Außenluft) / (Abluft − Außenluft), auf 0..100 begrenzt.
     * Bei kleinem Abstand Abluft/Außenluft numerisch instabil → 0.
     */
    public static function heatRecoveryEfficiency(float $outside, float $supply, float $extract): float
    {
        $denom = $extract - $outside;
        if (abs($denom) <= 0.5) {
            return 0.0;
        }
        return max(0.0, min(100.0, (($supply - $outside) / $denom) * 100.0));
    }
}

// ---------------------------------------------------------------------------
// RLT_VentilationDriverInterface — Vertrag jedes Hersteller-Treibers. Die
// Idents von getBaseVars() entsprechen dem mit EMS abgestimmten
// `Type=>'ventilation'`-Feldregister (SUITE.md); was die Datenquelle nicht
// hergibt, registriert der Treiber gar nicht (Vertragsfeld dann 0).
// ---------------------------------------------------------------------------
interface RLT_VentilationDriverInterface
{
    /** [ident, caption, type(F/I/B), profile, archive] je Basisvariable. */
    public function getBaseVars(): array;

    /** Liest einen Zyklus, schreibt über $hub->SetVar*(). Rückgabe: alle Pflichtwerte gelesen? */
    public function readValues($mb, $hub): bool;

    /** Zählt die Doku-Adresse ab 1 (Wire = Doku − 1)? Vorgabe des Treibers für AddressBase „automatisch". */
    public function addressOneBasedDefault(): bool;

    /** Für die Suche: null = nicht erkannt, sonst Kurzbeschreibung des Fundes. Rein lesend. */
    public function probe($mb): ?string;

    /** Hinweistexte für das Doku-Panel (Herkunft, Annahmen, Verifikationsstand). */
    public function getNotes(): array;
}

// ===========================================================================
// RLT_RobathermTrueControlDriver — Robatherm TrueControl DDC (Modbus TCP).
//
// ⚠️ Registeradressen stammen aus EINER konkreten Modbusdatenpunktliste
// (Auftragsnummer 102751.1, TrueControl Version 1.25, Januar 2019).
// TrueControl-DDCs werden je Projekt individuell parametriert — das ist KEIN
// fixer Firmware-Standard. ⚠️ Temperaturen: Faktor 10 angenommen (Liste
// nennt nur „INT"). ⚠️ Adress-Basis: Liste zählt ab 1 (Modicon), ob auf der
// Leitung ebenfalls, ist unbestätigt → Formularschalter AddressBase.
// Betriebsstunden-„Faktor"-Register (Bedeutung unerklärt) bleiben außen vor.
// ===========================================================================
class RLT_RobathermTrueControlDriver implements RLT_VentilationDriverInterface
{
    private const REG_OUTSIDE_TEMP   = 2001;
    private const REG_SUPPLY_TEMP    = 2019;
    private const REG_EXTRACT_TEMP   = 2049;
    private const REG_CO_PPM         = 2061;
    private const REG_FILTER1_HOURS  = 2737;
    private const REG_FAN1_FLOW      = 2352;
    private const REG_FAN2_FLOW      = 2354;
    private const REG_SYSTEM_SWITCH  = 1993;
    private const COIL_FAULT_SUMMARY = 6;
    private const COIL_WATCHDOG      = 1;

    public function addressOneBasedDefault(): bool
    {
        return true;
    }

    public function getBaseVars(): array
    {
        return [
            ['outsideTemp',            'Temperatur Außenluft',            'F', 'NRG.Celsius', true],
            ['supplyTemp',             'Temperatur Zuluft',               'F', 'NRG.Celsius', true],
            ['extractTemp',            'Temperatur Abluft',               'F', 'NRG.Celsius', true],
            ['co2',                    'Luftqualität (CO)',               'F', 'RLT.Ppm',     true],
            ['heatRecoveryEfficiency', 'WRG-Wirkungsgrad (berechnet)',    'F', 'NRG.Percent', true],
            ['filterRuntimeHours',     'Betriebsstunden Filter 1',        'F', 'RLT.Hours',   true],
            ['fan1Flow',               'Ventilator 1 Volumenstrom',       'F', 'NRG.Percent', true],
            ['fan2Flow',               'Ventilator 2 Volumenstrom',       'F', 'NRG.Percent', true],
            ['faultSummary',           'Sammelstörung',                   'B', '~Alert',      false],
            ['systemSwitch',           'Anlagenschalter',                 'B', '',            false],
            ['watchdogOk',             'TrueControl-Watchdog quittiert (Diagnose)', 'B', '',  false],
        ];
    }

    public function getNotes(): array
    {
        return [
            'Robatherm TrueControl: Registeradressen aus EINER konkreten Anlagenkonfiguration (Auftragsnummer 102751.1). TrueControl wird je Projekt individuell parametriert — bei anderen Anlagen können die Adressen abweichen. Vor Produktiveinsatz gegen die eigene Modbus-Datenpunktliste prüfen.',
            'Temperaturen: Register-Rohwert ÷ 10 = °C (verbreitete, hier nicht herstellerseitig dokumentierte Annahme, unverifiziert). Luftqualität (CO) und Ventilator-Volumenstrom-Faktor werden als Rohwert übernommen.',
            'Adress-Basis: Die Liste zählt ab 1. Ob die Leitung ebenfalls ab 1 zählt, ist unbestätigt — bei „automatisch" wird die Doku-Adresse um 1 verringert; bei Nullwerten/Fehlern auf „Wire-Adresse = Doku-Adresse" umstellen.',
            'Der WRG-Wirkungsgrad wird aus Außen-/Zu-/Ablufttemperatur berechnet, nicht vom Gerät geliefert.',
        ];
    }

    public function probe($mb): ?string
    {
        $t = $this->readTemps($mb, new RLT_ProbeAddress(true));
        if ($t === null) {
            return null;
        }
        return sprintf('Außenluft %.1f °C, Zuluft %.1f °C, Abluft %.1f °C', $t[0], $t[1], $t[2]);
    }

    /** @return array{0:float,1:float,2:float}|null plausible Temperaturen oder null */
    private function readTemps($mb, $addr): ?array
    {
        $raw = [
            $mb->readHolding($addr->wire(self::REG_OUTSIDE_TEMP), 1),
            $mb->readHolding($addr->wire(self::REG_SUPPLY_TEMP), 1),
            $mb->readHolding($addr->wire(self::REG_EXTRACT_TEMP), 1),
        ];
        $t = [];
        foreach ($raw as $r) {
            if ($r === null || !isset($r[0])) {
                return null;
            }
            $v = RLT_Decode::int16($r[0]) / 10.0;
            if ($v < -50.0 || $v > 70.0) {
                return null;
            }
            $t[] = $v;
        }
        // Ein Gerät, das überall 0 liefert, ist kein Fund.
        if ($t[0] == $t[1] && $t[1] == $t[2]) {
            return null;
        }
        return $t;
    }

    public function readValues($mb, $hub): bool
    {
        $ok = true;

        $outsideRaw = $mb->readHolding($hub->WireAddress(self::REG_OUTSIDE_TEMP), 1);
        $supplyRaw  = $mb->readHolding($hub->WireAddress(self::REG_SUPPLY_TEMP), 1);
        $extractRaw = $mb->readHolding($hub->WireAddress(self::REG_EXTRACT_TEMP), 1);

        $outside = $outsideRaw !== null ? RLT_Decode::int16($outsideRaw[0]) / 10.0 : null;
        $supply  = $supplyRaw  !== null ? RLT_Decode::int16($supplyRaw[0]) / 10.0  : null;
        $extract = $extractRaw !== null ? RLT_Decode::int16($extractRaw[0]) / 10.0 : null;

        foreach (['outsideTemp' => $outside, 'supplyTemp' => $supply, 'extractTemp' => $extract] as $ident => $v) {
            if ($v !== null) {
                $hub->SetVarFloat($ident, $v);
            } else {
                $ok = false;
            }
        }
        if ($outside !== null && $supply !== null && $extract !== null) {
            $hub->SetVarFloat('heatRecoveryEfficiency', RLT_Decode::heatRecoveryEfficiency($outside, $supply, $extract));
        }

        $rawFloats = [
            'co2'                => self::REG_CO_PPM,
            'filterRuntimeHours' => self::REG_FILTER1_HOURS,
            'fan1Flow'           => self::REG_FAN1_FLOW,
            'fan2Flow'           => self::REG_FAN2_FLOW,
        ];
        foreach ($rawFloats as $ident => $reg) {
            $r = $mb->readHolding($hub->WireAddress($reg), 1);
            if ($r !== null) {
                $hub->SetVarFloat($ident, (float)RLT_Decode::int16($r[0]));
            } else {
                $ok = false;
            }
        }

        // Anlagenschalter und Watchdog sind rein informativ (v0.x ist
        // Monitoring-only) — Fehler lassen $ok unangetastet.
        $sys = $mb->readHolding($hub->WireAddress(self::REG_SYSTEM_SWITCH), 1);
        if ($sys !== null) {
            $hub->SetVarBoolean('systemSwitch', RLT_Decode::int16($sys[0]) !== 0);
        }

        $fault = $mb->readCoils($hub->WireAddress(self::COIL_FAULT_SUMMARY), 1);
        if ($fault !== null) {
            $hub->SetVarBoolean('faultSummary', (bool)($fault[0] ?? 0));
        } else {
            $ok = false;
        }

        $wd = $mb->readCoils($hub->WireAddress(self::COIL_WATCHDOG), 1);
        if ($wd !== null) {
            $hub->SetVarBoolean('watchdogOk', (bool)($wd[0] ?? 0));
        }

        return $ok;
    }
}

/** Adressumrechnung für die Suche (kein Hub vorhanden). */
class RLT_ProbeAddress
{
    private $oneBased;

    public function __construct(bool $oneBased)
    {
        $this->oneBased = $oneBased;
    }

    public function wire(int $documented): int
    {
        return $this->oneBased ? $documented - 1 : $documented;
    }
}

// ===========================================================================
// RLT_ProxonFwtDriver — Proxon FWT 2.0 (Zimmermann), Zu-/Abluft-Wärmepumpe
// mit Lüftungsfunktion. NUR Modbus RTU (RS485), Werks-Slave-ID 41, 19200
// Baud, 8E1 — also über RLTHubGateway (Symcons ModBus-Gateway).
//
// Quelle: „Modbus Liste FWT2.0 ver2" (Zimmermann-Kundendoku, nicht
// öffentlich, nicht Teil dieses Repos — hier nur die benutzten Adressen).
// Input-Register (Function 4): T1 Zuluft 195, T7 Abluft 196, T3 Frischluft
// 198, Störung 47. Holding-Register (Function 3): Betriebsart 16 (0 = Aus),
// Stunden Gerätefilter 469.
//
// Verifikation (21.09.2026, an einer echten FWT über das ModBus-Gateway, je Rohwert
// gegen das Display): Adressen und Function Codes stimmen, Adress-Basis =
// Excel-Nummer = Wire-Adresse, Temperaturen = Roh/100 (T3 1420 = 14,2 °C,
// T1 1825 = 18,25 °C, T7 2420 = 24,2 °C), Störung 0 = keine Störung,
// Filterstunden 469 = 1069 h, Betriebsart 16 = 1 (EcoSommer).
// ⚠️ Weiter offen: Temperaturen unter 0 °C. Die Liste nennt „uint16, *100" ohne
// Offset, Winterwerte sind damit nicht darstellbar (die T300 nutzt
// °C = Roh/10 − 100). Bis ein Roh-/Display-Paar bei Minusgraden vorliegt,
// bleibt „Wert ≥ 32768 = negativ (Zweierkomplement)" eine Annahme.
// ⚠️ „Störung" (Register 47) ist ein Zahlenwert: 0 = keine Störung ist bestätigt;
// jeder Wert ≠ 0 gilt als Störung, die Bedeutung der Codes ist nicht dokumentiert.
// ===========================================================================
class RLT_ProxonFwtDriver implements RLT_VentilationDriverInterface
{
    private const IN_FAULT       = 47;
    private const IN_T1_SUPPLY   = 195;
    private const IN_T7_EXTRACT  = 196;
    private const IN_T3_OUTSIDE  = 198;
    private const HR_MODE        = 16;
    private const HR_FILTER_HRS  = 469;

    public function addressOneBasedDefault(): bool
    {
        return false;
    }

    public function getBaseVars(): array
    {
        return [
            ['outsideTemp',            'Temperatur Frischluft (T3)',      'F', 'NRG.Celsius', true],
            ['supplyTemp',             'Temperatur Zuluft (T1)',          'F', 'NRG.Celsius', true],
            ['extractTemp',            'Temperatur Abluft (T7)',          'F', 'NRG.Celsius', true],
            ['heatRecoveryEfficiency', 'WRG-Wirkungsgrad (berechnet)',    'F', 'NRG.Percent', true],
            ['filterRuntimeHours',     'Betriebsstunden Gerätefilter',    'F', 'RLT.Hours',   true],
            ['faultSummary',           'Störung',                         'B', '~Alert',      false],
            ['systemSwitch',           'Betriebsart nicht „Aus"',         'B', '',            false],
        ];
    }

    public function getNotes(): array
    {
        return [
            'Proxon FWT 2.0 (Zimmermann): nur Modbus RTU über RS485 (Werks-Slave-ID 41, 19200 Baud, 8E1) — über Symcons ModBus-Gateway. Die Registerliste ist eine nicht öffentliche Kundendoku; hier sind nur die benutzten Adressen hinterlegt.',
            'Am 21.09.2026 an einer echten FWT geprüft (Rohwert gegen Display): Adressen, Function Codes, Temperaturen (Rohwert ÷ 100), Störung 0 = keine Störung, Filterstunden und Betriebsart stimmen. Die Excel-Nummer ist direkt die Adresse am Gateway.',
            'Noch offen: Temperaturen unter 0 °C. Die Liste nennt keinen Offset (die Schwestereinheit T300 nutzt eine Vorspannung). Bis ein Roh-/Display-Paar bei Minusgraden vorliegt, werden Werte ab 32768 als negativ gelesen — das ist eine Annahme.',
            'Störung: 0 = keine Störung ist bestätigt. Jeder andere Wert im Register „Stoerung" gilt als Sammelstörung, die Bedeutung der Codes ist nicht dokumentiert. Ventilator-Volumenstrom und Luftqualität liefert die FWT nicht (Vertragsfelder bleiben leer).',
        ];
    }

    public function probe($mb): ?string
    {
        $vals = [];
        foreach ([self::IN_T3_OUTSIDE, self::IN_T1_SUPPLY, self::IN_T7_EXTRACT] as $reg) {
            $r = $mb->readInput($reg, 1);
            if ($r === null || !isset($r[0])) {
                return null;
            }
            $v = self::regToTemp($r[0]);
            if ($v < -50.0 || $v > 80.0) {
                return null;
            }
            $vals[] = $v;
        }
        if ($vals[0] == $vals[1] && $vals[1] == $vals[2]) {
            return null;
        }
        return sprintf('Frischluft %.1f °C, Zuluft %.1f °C, Abluft %.1f °C', $vals[0], $vals[1], $vals[2]);
    }

    public function readValues($mb, $hub): bool
    {
        $ok = true;
        $t = [];
        foreach (['outsideTemp' => self::IN_T3_OUTSIDE, 'supplyTemp' => self::IN_T1_SUPPLY, 'extractTemp' => self::IN_T7_EXTRACT] as $ident => $reg) {
            $r = $mb->readInput($hub->WireAddress($reg), 1);
            if ($r !== null && isset($r[0])) {
                $t[$ident] = self::regToTemp($r[0]);
                $hub->SetVarFloat($ident, $t[$ident]);
            } else {
                $ok = false;
            }
        }
        if (count($t) === 3) {
            $hub->SetVarFloat('heatRecoveryEfficiency', RLT_Decode::heatRecoveryEfficiency($t['outsideTemp'], $t['supplyTemp'], $t['extractTemp']));
        }

        $filter = $mb->readHolding($hub->WireAddress(self::HR_FILTER_HRS), 1);
        if ($filter !== null && isset($filter[0])) {
            $hub->SetVarFloat('filterRuntimeHours', (float)($filter[0] & 0xFFFF));
        } else {
            $ok = false;
        }

        $fault = $mb->readInput($hub->WireAddress(self::IN_FAULT), 1);
        if ($fault !== null && isset($fault[0])) {
            $hub->SetVarBoolean('faultSummary', RLT_Decode::int16($fault[0]) !== 0);
        } else {
            $ok = false;
        }

        // Betriebsart ist rein informativ — Fehler lässt $ok unangetastet.
        $mode = $mb->readHolding($hub->WireAddress(self::HR_MODE), 1);
        if ($mode !== null && isset($mode[0])) {
            $hub->SetVarBoolean('systemSwitch', ($mode[0] & 0xFFFF) !== 0);
        }

        return $ok;
    }

    /** uint16 „*100"; Werte ab 32768 als negativ (Zweierkomplement) — Annahme, siehe Klassenkopf. */
    private static function regToTemp(int $raw): float
    {
        return RLT_Decode::int16($raw) / 100.0;
    }
}

class RLT_Drivers
{
    public const DRIVERS = [
        'robatherm_truecontrol' => [
            'class'      => 'RLT_RobathermTrueControlDriver',
            'caption'    => 'Robatherm TrueControl',
            'transports' => ['tcp'],
            'confidence' => 'Registerliste einer einzelnen Anlage, noch nicht an Hardware verifiziert.',
        ],
        'proxon_fwt' => [
            'class'      => 'RLT_ProxonFwtDriver',
            'caption'    => 'Proxon FWT 2.0 (Zimmermann)',
            'transports' => ['rtu'],
            'confidence' => 'An einer echten FWT geprüft (21.09.2026): Adressen, Temperaturen über 0 °C, Störung 0, Filterstunden, Betriebsart. Offen: Temperaturen unter 0 °C und die Störungscodes.',
        ],
    ];

    public static function create(string $key): RLT_VentilationDriverInterface
    {
        $class = (self::DRIVERS[$key] ?? self::DRIVERS['robatherm_truecontrol'])['class'];
        return new $class();
    }
}

// ===========================================================================
// RLT_HubTrait — gemeinsame Instanzlogik von RLTHub (TCP) und RLTHubGateway
// (RTU über ModBus-Gateway). Die verwendende Klasse liefert die Konstanten
// PREFIX, DEFAULT_DEVICE, TRANSPORT ('tcp'|'rtu') und die drei abstrakten
// Methoden.
// ===========================================================================
trait RLT_HubTrait
{
    use RLT_PanelTrait;

    private $rltDriver = null;

    abstract protected function rltClient(): RLT_ModbusClientInterface;

    /** Formularelemente der Verbindung (Host/Port/Unit-ID bzw. Gateway-Hinweis). */
    abstract protected function rltConnectionItems(): array;

    /** 104 = Verbindung unvollständig, 201 = Gateway/Partner fehlt, 102 = bereit. */
    abstract protected function rltConnectionStatus(): int;

    /** Ziel der Verbindung als Anzeigetext („192.0.2.10:502 (Unit-ID 1)“ bzw. Gateway mit ID/Geräte-ID), leer = keines. */
    abstract protected function rltConnectionTarget(): string;

    protected function rltCreate(): void
    {
        $this->RegisterPropertyBoolean('Active', true);
        $this->RegisterPropertyString('Location', '');
        $this->RegisterPropertyString('Device', static::DEFAULT_DEVICE);
        // 'auto' = Vorgabe des Treibers, 'one' = Wire = Doku − 1, 'zero' = Wire = Doku
        $this->RegisterPropertyString('AddressBase', 'auto');
        $this->RegisterPropertyInteger('PollInterval', 60);

        $this->RegisterAttributeInteger('LastSeenAt', 0);
        $this->rltPanelCreate();

        $this->RegisterTimer('ReadValuesTimer', 0, static::PREFIX . '_ReadValues($_IPS[\'TARGET\']);');
    }

    protected function rltApply(): void
    {
        $this->rltAdoptDismissFromSibling();
        $this->rltCreateProfiles();

        $pos = 0;
        foreach ($this->rltDriver()->getBaseVars() as $def) {
            $this->rltRegisterVar($def, $pos++);
        }
        $this->rltRegisterVar(['lastSeenAt', 'Zuletzt aktualisiert', 'I', '~UnixTimestamp', false], $pos);

        if (!(bool)$this->ReadPropertyBoolean('Active')) {
            $this->SetTimerInterval('ReadValuesTimer', 0);
            $this->SetStatus(104);
            return;
        }
        $status = $this->rltConnectionStatus();
        // Ein fehlendes Gateway meldet ReadValues() je Zyklus selbst (201),
        // der Timer muss dafür weiterlaufen; nur eine unvollständige
        // Verbindung (104) hält ihn an.
        $this->SetTimerInterval('ReadValuesTimer', $status === 104 ? 0 : max(5, (int)$this->ReadPropertyInteger('PollInterval')) * 1000);
        $this->SetStatus($status);
        if ($status === 201) {
            IPS_LogMessage(static::MODULE_NAME, 'Instanz #' . $this->InstanceID . ': Kein ModBus-Gateway verbunden — im Instanzformular oben unter „Gateway" eines wählen.');
        }
    }


    // -----------------------------------------------------------------------
    // Verbund-Konvention „Verbindungen im Formular sichtbar machen“ (SUITE.md,
    // 21.09.2026): eine live berechnete Statuszeile je Verbindung mit den
    // tatsächlich gelesenen Werten und ihrer Quelle — nie ein statischer Satz.
    // -----------------------------------------------------------------------
    protected function rltStatusLine(): string
    {
        if (!(bool)$this->ReadPropertyBoolean('Active')) {
            return 'ℹ️ Kommunikation ist ausgeschaltet — es wird nichts gelesen.';
        }
        $target = $this->rltConnectionTarget();
        if ($target === '') {
            return static::TRANSPORT === 'rtu'
                ? 'ℹ️ Kein ModBus-Gateway verbunden — es wird nichts gelesen. Oben unter „Gateway" eines wählen.'
                : 'ℹ️ Noch keine IP-Adresse eingetragen — es wird nichts gelesen.';
        }
        $seen = (int)$this->ReadAttributeInteger('LastSeenAt');
        if ($seen === 0) {
            return '⚠️ Verbunden mit ' . $target . ', aber noch keine gültige Antwort erhalten. „Verbindung jetzt testen" nennt den Grund.';
        }
        $when = date('d.m.Y H:i:s', $seen) . ' Uhr';
        if ($this->GetStatus() === 201) {
            return '⚠️ Verbunden mit ' . $target . ', die letzte Abfrage ist aber fehlgeschlagen (letzte gültige Antwort ' . $when . '). „Verbindung jetzt testen" nennt den Grund.';
        }
        $provided = array_column($this->rltDriver()->getBaseVars(), 0);
        $parts = [];
        foreach (['outsideTemp' => 'Außenluft', 'supplyTemp' => 'Zuluft', 'extractTemp' => 'Abluft'] as $ident => $label) {
            $vid = in_array($ident, $provided, true) ? $this->VarID($ident) : 0;
            if ($vid) {
                $parts[] = $label . ' ' . number_format((float)GetValue($vid), 1, ',', '.') . ' °C';
            }
        }
        $vid = in_array('faultSummary', $provided, true) ? $this->VarID('faultSummary') : 0;
        if ($vid) {
            $parts[] = 'Störung: ' . (GetValue($vid) ? 'ja' : 'nein');
        }
        return '✅ Verbunden mit ' . $target . ' — letzte gültige Antwort ' . $when . '. Zuletzt gelesen (Quelle: die Anlage): ' . ($parts ? implode(', ', $parts) : 'keine Werte') . '.';
    }

    private function rltRefreshStatusLine(): void
    {
        $this->UpdateFormField('ConnectionStatusLine', 'caption', $this->rltStatusLine());
    }


    // -----------------------------------------------------------------------
    // Adress-Basis nach der Verbund-Konvention „Wert kommt automatisch:
    // Eingabefeld ersetzen“ (SUITE.md, 21.09.2026): Bei „automatisch“ zeigt das
    // Formular eine schreibgeschützte Zeile mit dem geltenden Wert und seiner
    // Quelle (🔗); das Auswahlfeld steckt in einem eingeklappten Panel für
    // bewusstes Überschreiben. Bei eigener Angabe bleibt es sichtbar (✏️).
    // Der automatische Wert wird NIE per UpdateFormField('value') ins Feld
    // geschrieben, sonst würde „Übernehmen“ ihn als eigene Angabe speichern.
    // -----------------------------------------------------------------------
    private function rltAddressBaseLine(string $device, string $base): string
    {
        $driverDefault = RLT_Drivers::create($device)->addressOneBasedDefault();
        $describe = function (bool $oneBased): string {
            return $oneBased ? 'Doku-Adresse − 1 = Wire-Adresse' : 'Doku-Adresse = Wire-Adresse';
        };
        if ($base === 'one' || $base === 'zero') {
            return '✏️ Adress-Basis: ' . $describe($base === 'one') . ' (eigene Angabe; die Vorgabe des Gerätetyps wäre: ' . $describe($driverDefault) . ')';
        }
        $caption = RLT_Drivers::DRIVERS[$device]['caption'] ?? $device;
        return '🔗 Adress-Basis: ' . $describe($driverDefault) . ' (automatisch, Vorgabe des Gerätetyps ' . $caption . ')';
    }

    private function rltAddressBaseItems(string $device, string $base): array
    {
        $select = [
            'type'     => 'Select',
            'name'     => 'AddressBase',
            'caption'  => 'Adress-Basis der Registerliste',
            'options'  => [
                ['caption' => 'automatisch (Vorgabe des Gerätetyps)', 'value' => 'auto'],
                ['caption' => 'Doku-Adresse − 1 = Wire-Adresse (1-basierte Liste)', 'value' => 'one'],
                ['caption' => 'Doku-Adresse = Wire-Adresse', 'value' => 'zero'],
            ],
            'onChange' => static::PREFIX . '_OnChangeAddressBase($id, $Device, $AddressBase);',
        ];
        $help = [
            'type'    => 'PopupButton',
            'caption' => 'Was bedeutet die Adress-Basis der Registerliste?',
            'width'   => '480px',
            'popup'   => [
                'caption' => 'Adress-Basis der Registerliste',
                'items'   => [
                    ['type' => 'Label', 'caption' => 'Herstellerlisten zählen Register oft ab 1 (Modicon-Konvention), auf der Leitung zählt Modbus aber ab 0. Ob die Adresse aus der Liste also noch um 1 verringert werden muss, hängt vom Gerät ab.'],
                    ['type' => 'Label', 'caption' => 'Die Vorgabe des gewählten Gerätetyps gilt automatisch. Liefert die Anlage Nullwerte, Fehler oder erkennbar falsche Werte, kannst du unter „Eigene Adress-Basis stattdessen verwenden" testweise die andere Auswahl einstellen und „Verbindung jetzt testen" klicken.'],
                    ['type' => 'Label', 'caption' => 'Ob die Vorgabe eines Gerätetyps stimmt, steht im Panel „Dokumentation & Hilfe".'],
                ],
            ],
        ];
        $line = RLT_Ui::line('AddressBaseLine', $this->rltAddressBaseLine($device, $base));
        if ($base === 'one' || $base === 'zero') {
            return [$line, $help, $select];
        }
        return [
            $line,
            $help,
            ['type' => 'ExpansionPanel', 'caption' => 'Eigene Adress-Basis stattdessen verwenden', 'expanded' => false, 'items' => [$select]],
        ];
    }

    private function rltUpdateAddressBaseLine(string $device, string $addressBase): void
    {
        $caption = $this->rltAddressBaseLine($device, $addressBase);
        $this->UpdateFormField('AddressBaseLine', 'caption', $caption);
        $this->UpdateFormField('AddressBaseLine', 'color', RLT_Ui::color($caption));
    }

    public function OnChangeAddressBase(string $device, string $addressBase): void
    {
        $this->rltUpdateAddressBaseLine($device, $addressBase);
    }

    public function OnChangeDevice(string $device, string $addressBase): void
    {
        $this->UpdateFormField('DeviceConfidence', 'caption', 'ℹ️ ' . (RLT_Drivers::DRIVERS[$device]['confidence'] ?? ''));
        // Die Adress-Basis-Zeile folgt der Auswahl im offenen Formular, nicht dem Speicherstand.
        $this->rltUpdateAddressBaseLine($device, $addressBase);
    }

    public function GetConfigurationForm()
    {
        $options = [];
        foreach (RLT_Drivers::DRIVERS as $key => $def) {
            if (in_array(static::TRANSPORT, $def['transports'], true)) {
                $options[] = ['caption' => $def['caption'], 'value' => $key];
            }
        }
        $device = (string)$this->ReadPropertyString('Device');
        $notes = [];
        foreach ($this->rltDriver()->getNotes() as $line) {
            $notes[] = ['type' => 'Label', 'caption' => $line];
        }

        $libraryInfo = @json_decode((string)@file_get_contents(__DIR__ . '/../library.json'), true);
        $version = (is_array($libraryInfo) && isset($libraryInfo['version'])) ? (string)$libraryInfo['version'] : '?';

        $transportNote = static::TRANSPORT === 'rtu'
            ? 'RS485/Modbus RTU läuft über Symcons ModBus-Gateway (Serial Port → ModBus Gateway → diese Instanz). Das gilt auch für den eingebauten RS485-Port einer Symbox — der ist kein externes Gateway und nur so erreichbar. Anlagen mit Modbus TCP bindest du mit dem Modul RLTHub an.'
            : 'Diese Instanz spricht Modbus TCP. Ein externer RTU→TCP-Konverter (z. B. für RS485-Geräte) geht damit ebenfalls. Der eingebaute RS485-Port einer Symbox ist dagegen kein externes Gateway — dafür gibt es das Modul RLTHubGateway.';

        $fach = [
            ['type' => 'CheckBox', 'name' => 'Active', 'caption' => 'Kommunikation aktiv'],
            ['type' => 'ValidationTextBox', 'name' => 'Location', 'caption' => 'Bezeichnung / Standort (optional)'],
            ['type' => 'Label', 'caption' => 'ℹ️ Die Vorbelegung des Gerätetyps ist nur der erste Eintrag der Liste — bitte den zur eigenen Anlage passenden wählen.'],
            [
                'type'     => 'Select',
                'name'     => 'Device',
                'caption'  => 'Gerätetyp',
                'options'  => $options,
                'onChange' => static::PREFIX . '_OnChangeDevice($id, $Device, $AddressBase);',
            ],
            [
                'type'    => 'Label',
                'name'    => 'DeviceConfidence',
                'caption' => 'ℹ️ ' . (RLT_Drivers::DRIVERS[$device]['confidence'] ?? ''),
            ],
            [
                'type'     => 'ExpansionPanel',
                'caption'  => '🔌 Verbindung',
                'expanded' => true,
                'items'    => array_merge([['type' => 'Label', 'name' => 'ConnectionStatusLine', 'caption' => $this->rltStatusLine()]], $this->rltConnectionItems(), [
                    ...$this->rltAddressBaseItems($device, (string)$this->ReadPropertyString('AddressBase')),
                    ['type' => 'NumberSpinner', 'name' => 'PollInterval', 'caption' => 'Abfragetakt', 'minimum' => 5, 'maximum' => 3600, 'suffix' => ' s'],
                    ['type' => 'Button', 'caption' => '🔎  Verbindung jetzt testen', 'onClick' => 'echo ' . static::PREFIX . '_TestConnection($id);'],
                ]),
            ],
        ];

        $doku = [
            'type'     => 'ExpansionPanel',
            'caption'  => '📖 Dokumentation & Hilfe',
            'expanded' => false,
            'items'    => array_merge([
                ['type' => 'Label', 'caption' => static::MODULE_NAME . ' Version ' . $version . ' — Stand der Prüfung je Gerätetyp: siehe unten.'],
                ['type' => 'Label', 'caption' => 'Liefert den NRG-Stack-Vertrag Type=>\'ventilation\' (contractVersion 1.0, mit dem EMS abgestimmt). Rein lesend — das Modul steuert die Anlage nicht.'],
                ['type' => 'Label', 'caption' => $transportNote],
                ['type' => 'Label', 'caption' => 'Schlägt eine Abfrage fehl, steht der Instanzstatus auf „Verbindungsfehler" und einmalig eine Meldung im Symcon-Protokoll. „Verbindung jetzt testen" zeigt den Grund.'],
            ], $notes),
        ];

        $form = [
            'elements' => array_merge($this->rltPanelsTop(), [$doku], $fach, $this->rltPanelsBottom()),
            'status'   => [
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'Inaktiv bzw. Verbindung unvollständig.'],
                ['code' => 102, 'icon' => 'active',   'caption' => 'Aktiv.'],
                ['code' => 201, 'icon' => 'error',    'caption' => 'Verbindungsfehler — Gerät bzw. ModBus-Gateway nicht erreichbar.'],
            ],
        ];
        return json_encode($form);
    }

    // -----------------------------------------------------------------------
    // Lesezyklus
    // -----------------------------------------------------------------------

    /** @return array{0:bool,1:RLT_ModbusClientInterface} */
    private function rltReadCycle(): array
    {
        $mb = $this->rltClient();
        $ok = $this->rltDriver()->readValues($mb, $this);
        $mb->close();
        return [$ok, $mb];
    }

    private function rltMarkSeen(): void
    {
        $now = time();
        $this->WriteAttributeInteger('LastSeenAt', $now);
        $this->SetVarInteger('lastSeenAt', $now);
    }

    private function rltFailureReason($mb): string
    {
        $reason = (is_object($mb) && property_exists($mb, 'lastError')) ? (string)$mb->lastError : '';
        return $reason !== '' ? ' (' . $reason . ')' : '';
    }

    public function ReadValues()
    {
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }
        if (!(bool)$this->ReadPropertyBoolean('Active') || $this->rltConnectionStatus() === 104) {
            return;
        }
        $previous = $this->GetStatus();
        // Kein ModBus-Gateway verbunden: gar nicht erst abfragen, sondern den
        // wahren Grund melden (sonst steht „Regler antwortet nicht“ im Protokoll).
        if ($this->rltConnectionStatus() === 201) {
            $this->SetStatus(201);
            if ($previous !== 201) {
                IPS_LogMessage(static::MODULE_NAME, 'Instanz #' . $this->InstanceID . ': Kein ModBus-Gateway verbunden — im Instanzformular oben unter „Gateway" eines wählen.');
            }
            return;
        }
        [$ok, $mb] = $this->rltReadCycle();

        if ($ok) {
            $this->rltMarkSeen();
            $this->SetStatus(102);
            return;
        }
        $this->SetStatus(201);
        // Dauerhaft loggen, aber nur beim Übergang — nicht bei jedem Takt.
        if ($previous !== 201) {
            IPS_LogMessage(static::MODULE_NAME, 'Instanz #' . $this->InstanceID . ': Anlage nicht erreichbar oder keine gültige Antwort' . $this->rltFailureReason($mb) . '.');
        }
    }

    public function TestConnection(): string
    {
        $result = $this->rltTestConnection();
        // Kopfzeile nach der Aktion auffrischen (erst speichern, dann anzeigen).
        $this->rltRefreshStatusLine();
        return $result;
    }

    private function rltTestConnection(): string
    {
        if ($this->rltConnectionStatus() === 104) {
            return '❌ Verbindung unvollständig — Host/Port/Unit-ID prüfen.';
        }
        if ($this->rltConnectionStatus() === 201) {
            $this->SetStatus(201);
            return '❌ Kein ModBus-Gateway verbunden — oben unter „Gateway" ein ModBus-Gateway wählen (Serial Port → ModBus Gateway).';
        }
        [$ok, $mb] = $this->rltReadCycle();

        if ($ok) {
            $this->rltMarkSeen();
            $this->SetStatus(102);
            return '✅ Verbindung erfolgreich, Werte aktualisiert.';
        }
        $this->SetStatus(201);
        return '❌ Verbindung fehlgeschlagen oder keine gültige Antwort' . $this->rltFailureReason($mb) . ' — Verbindung, Adress-Basis und Gerätetyp prüfen.';
    }

    // -----------------------------------------------------------------------
    // Cross-Modul-Vertrag `Type=>'ventilation'` (SUITE.md, 1.0). Liste, damit
    // eine spätere Aufteilung die Signatur nicht bricht. Felder, die der
    // gewählte Treiber nicht liefert, stehen auf 0 (= „Datenquelle liefert das
    // nicht").
    // -----------------------------------------------------------------------

    public function GetFunctions(): array
    {
        $provided = array_column($this->rltDriver()->getBaseVars(), 0);
        $id = function (string $ident) use ($provided): int {
            return in_array($ident, $provided, true) ? $this->VarID($ident) : 0;
        };
        $location = (string)$this->ReadPropertyString('Location');
        return [[
            'contractVersion'          => '1.0',
            'instanceID'               => $this->InstanceID,
            'Caption'                  => $location !== '' ? $location : IPS_GetName($this->InstanceID),
            'Measured'                 => true,
            'unit'                     => in_array('co2', $provided, true) ? 'ppm' : '',
            'reachable'                => $this->GetStatus() === 102,
            'outsideTempID'            => $id('outsideTemp'),
            'supplyTempID'             => $id('supplyTemp'),
            'extractTempID'            => $id('extractTemp'),
            'co2ID'                    => $id('co2'),
            'heatRecoveryEfficiencyID' => $id('heatRecoveryEfficiency'),
            'filterRuntimeHoursID'     => $id('filterRuntimeHours'),
            'fan1FlowID'               => $id('fan1Flow'),
            'fan2FlowID'               => $id('fan2Flow'),
            'faultSummaryID'           => $id('faultSummary'),
            'lastSeenAt'               => (int)$this->ReadAttributeInteger('LastSeenAt'),
            'pollInterval'             => (int)$this->ReadPropertyInteger('PollInterval'),
        ]];
    }

    // -----------------------------------------------------------------------
    // Für die Treiber (public, damit sie über $hub->… erreichbar sind)
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

    /** Doku-Adresse -> Wire-Adresse laut Property 'AddressBase' bzw. Treiber-Vorgabe. */
    public function WireAddress(int $documented): int
    {
        $base = (string)$this->ReadPropertyString('AddressBase');
        $oneBased = $base === 'one' || ($base !== 'zero' && $this->rltDriver()->addressOneBasedDefault());
        return $oneBased ? $documented - 1 : $documented;
    }

    private function VarID(string $ident): int
    {
        // Achtung Reihenfolge: (Ident, Eltern-ID).
        $vid = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        return $vid ? (int)$vid : 0;
    }

    private function rltDriver(): RLT_VentilationDriverInterface
    {
        if ($this->rltDriver === null) {
            $this->rltDriver = RLT_Drivers::create((string)$this->ReadPropertyString('Device'));
        }
        return $this->rltDriver;
    }

    // -----------------------------------------------------------------------
    // Variablen-/Profilverwaltung
    // -----------------------------------------------------------------------

    private function rltRegisterVar(array $def, int $pos): void
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
                return;
        }
        $vid = $this->VarID($ident);
        if ($archive && $vid) {
            $archiveIDs = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
            if (count($archiveIDs) > 0) {
                AC_SetLoggingStatus($archiveIDs[0], $vid, true);
                AC_SetAggregationType($archiveIDs[0], $vid, 0);
            }
        }
    }

    private function rltCreateProfiles(): void
    {
        // Gemeinsame NRG-Stack-Profile: nur anlegen, wenn sie fehlen (RLTHub
        // ist nicht Eigentümer, Vorbild MeterHub ensureSharedProfile()).
        foreach ([['NRG.Celsius', ' °C', 1, 'Temperature'], ['NRG.Percent', ' %', 1, '']] as [$name, $suffix, $digits, $icon]) {
            if (!IPS_VariableProfileExists($name)) {
                IPS_CreateVariableProfile($name, VARIABLETYPE_FLOAT);
                IPS_SetVariableProfileDigits($name, $digits);
                IPS_SetVariableProfileText($name, '', $suffix);
                if ($icon !== '') {
                    IPS_SetVariableProfileIcon($name, $icon);
                }
            }
        }
        // Modulspezifisch (RLTHub bleibt Eigentümer).
        foreach ([['RLT.Ppm', ' ppm', 0], ['RLT.Hours', ' h', 0]] as [$name, $suffix, $digits]) {
            if (!IPS_VariableProfileExists($name)) {
                IPS_CreateVariableProfile($name, VARIABLETYPE_FLOAT);
            }
            IPS_SetVariableProfileDigits($name, $digits);
            IPS_SetVariableProfileText($name, '', $suffix);
        }
    }
}
