<?php
/**
 * Prüfstand RLTHub-Architektur-Skelett: Modbus-Fassade (direkt + Gateway,
 * 1:1 aus MeterHub übernommen, SUITE.md 9j) und der Robatherm-TrueControl-
 * Treiber (Register-Dekodierung, WRG-Wirkungsgrad-Berechnung).
 *
 *   php .tools/test-robatherm-driver.php    # 0 = alle Prüfungen bestanden
 *
 * Startet wie MeterHubs test-modbus-client.php einen echten kleinen
 * Modbus-TCP-Server in einem eigenen PHP-Prozess. Der Treiber selbst wird
 * gegen einen Fake-Hub geprüft (nur SetVar*()/WireAddress()) — kein
 * IP-Symcon nötig, RLT_VentilationDriverInterface kennt IPSModule nicht.
 */

if (!class_exists('IPSModule')) {
    class IPSModule
    {
        public $InstanceID = 0;
        public function __construct($id = 0) { $this->InstanceID = $id; }
    }
}
foreach (['VARIABLETYPE_BOOLEAN' => 0, 'VARIABLETYPE_INTEGER' => 1, 'VARIABLETYPE_FLOAT' => 2, 'VARIABLETYPE_STRING' => 3] as $c => $v) {
    if (!defined($c)) { define($c, $v); }
}
require_once dirname(__DIR__) . '/RLTHub/module.php';

$fails = 0;
function check($label, $cond, $detail = '')
{
    global $fails;
    if ($cond) { echo "  ok    $label\n"; }
    else { $fails++; echo "  FEHLT $label" . ($detail !== '' ? "  ($detail)" : '') . "\n"; }
}

/** Server-Prozess: FC03 (Register) liefert Wert = Adresse, FC01 (Coils) liefert ein festes Bitmuster. */
function startServer(): array
{
    $port = random_int(20000, 60000);
    $cnt  = tempnam(sys_get_temp_dir(), 'rltcnt');
    $code = <<<'PHP'
[$port, $cnt] = [(int)$argv[1], $argv[2]];
$srv = stream_socket_server("tcp://127.0.0.1:$port", $e, $es);
if (!$srv) { exit(1); }
file_put_contents($cnt, '0');
$conns = 0;
$rx = function ($c, $n) { $b = ''; while (strlen($b) < $n) { $x = fread($c, $n - strlen($b)); if ($x === false || $x === '') { return null; } $b .= $x; } return $b; };
while ($c = @stream_socket_accept($srv, 30)) {
    $conns++;
    file_put_contents($cnt, (string)$conns);
    while (true) {
        $head = $rx($c, 7);
        if ($head === null) { break; }
        $h = unpack('ntid/npid/nlen/Cunit', $head);
        $pdu = $rx($c, $h['len'] - 1);
        if ($pdu === null) { break; }
        $fc = ord($pdu[0]);
        $a = unpack('nstart/ncount', substr($pdu, 1, 4));
        if ($fc === 3) {
            // Register-Wert = angefragte Adresse (Negativtest: Adresse
            // 65500 -> als signed int16 -36, prueft regToInt16()).
            $data = '';
            for ($i = 0; $i < $a['count']; $i++) { $data .= pack('n', ($a['start'] + $i) & 0xFFFF); }
            $resp = chr($fc) . chr(strlen($data)) . $data;
        } elseif ($fc === 1) {
            // Coils: Bitmuster 0b00000101 -> Coil 0 (LSB) und Coil 2 gesetzt.
            $resp = chr($fc) . chr(1) . chr(0b00000101);
        } else {
            $resp = chr($fc | 0x80) . chr(1);
        }
        fwrite($c, pack('nnn', $h['tid'], 0, strlen($resp) + 1) . chr($h['unit']) . $resp);
    }
    fclose($c);
}
PHP;
    $proc = proc_open([PHP_BINARY, '-r', $code, (string)$port, $cnt], [], $pipes);
    for ($i = 0; $i < 50; $i++) {
        if (@file_get_contents($cnt) === '0') { break; }
        usleep(100000);
    }
    return [$proc, $port, $cnt];
}
function stopServer(array $srv): void
{
    proc_terminate($srv[0]);
    proc_close($srv[0]);
    @unlink($srv[2]);
}

echo "1) RLT_ModbusTcpClient: Register lesen (Wert = Adresse)\n";
$s = startServer();
$mb = new RLT_ModbusTcpClient('127.0.0.1', $s[1], 1);
check('implementiert RLT_ModbusClientInterface', $mb instanceof RLT_ModbusClientInterface);
$r = $mb->readHolding(2001, 1);
check('readHolding liefert erwarteten Wert', ($r[0] ?? null) === 2001, json_encode($r));
$mb->close();
stopServer($s);

echo "2) RLT_ModbusTcpClient: Coils lesen (Bitreihenfolge LSB zuerst)\n";
$s = startServer();
$mb = new RLT_ModbusTcpClient('127.0.0.1', $s[1], 1);
$bits = $mb->readCoils(6, 3);
check('Coil 0 gesetzt, Coil 1 nicht, Coil 2 gesetzt', $bits === [0 => 1, 1 => 0, 2 => 1], json_encode($bits));
$mb->close();
stopServer($s);

echo "3) RLT_ModbusGatewayClient: readHolding/readCoils über Fake-SendDataToParent\n";
$calls = [];
$fakeSend = function (string $json) use (&$calls) {
    $calls[] = json_decode($json, true);
    $req = end($calls);
    if ($req['Function'] === 1) {
        // Coils: 2-Byte-Header überspringen (wie bei Registern), dann rohe Bitbytes.
        return "\xFF\xFF" . chr(0b00000101);
    }
    $data = '';
    for ($i = 0; $i < $req['Quantity']; $i++) { $data .= pack('n', ($req['Address'] + $i) & 0xFFFF); }
    return "\xFF\xFF" . $data;
};
$gw = new RLT_ModbusGatewayClient(1, $fakeSend);
check('implementiert RLT_ModbusClientInterface', $gw instanceof RLT_ModbusClientInterface);
$r = $gw->readHolding(100, 2);
check('readHolding: DataID/Function/Address/Quantity korrekt gesendet', $calls[0] === ['DataID' => '{E310B701-4AE7-458E-B618-EC13A1A6F6A8}', 'Function' => 3, 'Address' => 100, 'Quantity' => 2, 'Data' => ''], json_encode($calls[0]));
check('readHolding: Werte korrekt dekodiert', $r === [100, 101], json_encode($r));
$bits = $gw->readCoils(6, 3);
check('readCoils: Function 1 gesendet', $calls[1]['Function'] === 1);
check('readCoils: Bitmuster korrekt dekodiert', $bits === [0 => 1, 1 => 0, 2 => 1], json_encode($bits));
check('close() ist gefahrlos aufrufbar (No-Op)', ($gw->close() ?? true) === true);

echo "4) Robatherm-Treiber: Register-Dekodierung + WRG-Wirkungsgrad, gegen Fake-Modbus-Client\n";

class FakeHub
{
    public $vars = [];
    public $oneBased = true;
    public function SetVarFloat(string $ident, float $value): void { $this->vars[$ident] = $value; }
    public function SetVarInteger(string $ident, int $value): void { $this->vars[$ident] = $value; }
    public function SetVarBoolean(string $ident, bool $value): void { $this->vars[$ident] = $value; }
    public function WireAddress(int $documented): int { return $this->oneBased ? $documented - 1 : $documented; }
}

class FakeModbus implements RLT_ModbusClientInterface
{
    /** @var array<int,array<int,int>> Wire-Adresse -> Register-Werte (ab Startadresse) */
    public $registers = [];
    /** @var array<int,array<int,int>> */
    public $coils = [];
    public $calls = [];
    public function readHolding($startReg, $count)
    {
        $this->calls[] = ['holding', $startReg, $count];
        return $this->registers[$startReg] ?? null;
    }
    public function readCoils($startReg, $count)
    {
        $this->calls[] = ['coils', $startReg, $count];
        return $this->coils[$startReg] ?? null;
    }
    public function close(): void {}
    public function setWordSwap(bool $s) {}
}

$driver = new RLT_RobathermTrueControlDriver();
$hub = new FakeHub();
$mb = new FakeModbus();
// Dokuadressen 2001/2019/2049/2061/2737/2352/2354/1993 -> Wire (AddressOneBased=true) minus 1.
$mb->registers[2000] = [235];   // Außenluft 23,5 °C
$mb->registers[2018] = [210];   // Zuluft 21,0 °C
$mb->registers[2048] = [220];   // Abluft 22,0 °C
$mb->registers[2060] = [420];   // CO 420 ppm (Rohwert, keine Skalierung)
$mb->registers[2736] = [1234];  // Filterlaufzeit 1234 h
$mb->registers[2351] = [80];    // Ventilator 1 Volumenstrom-Faktor
$mb->registers[2353] = [75];    // Ventilator 2 Volumenstrom-Faktor
$mb->registers[1992] = [1];     // Anlagenschalter an
$mb->coils[5]        = [1];     // Sammelstörung (Coil 6, wire 5) = true
$mb->coils[0]        = [0];     // Watchdog (Coil 1, wire 0) = false

$ok = $driver->readValues($mb, $hub);
check('Zyklus ohne Fehler', $ok === true);
check('outsideTemp = 23.5', abs($hub->vars['outsideTemp'] - 23.5) < 1e-9, (string)($hub->vars['outsideTemp'] ?? 'fehlt'));
check('supplyTemp = 21.0', abs($hub->vars['supplyTemp'] - 21.0) < 1e-9);
check('extractTemp = 22.0', abs($hub->vars['extractTemp'] - 22.0) < 1e-9);
// eta = (supply-outside)/(extract-outside) = (21.0-23.5)/(22.0-23.5) = (-2.5)/(-1.5) = 166.67% -> auf 100 gedeckelt
check('heatRecoveryEfficiency auf [0,100] gedeckelt', $hub->vars['heatRecoveryEfficiency'] === 100.0, (string)($hub->vars['heatRecoveryEfficiency'] ?? 'fehlt'));
check('co2 = 420 (roh, keine Skalierung)', $hub->vars['co2'] === 420.0);
check('filterRuntimeHours = 1234 (roh)', $hub->vars['filterRuntimeHours'] === 1234.0);
check('fan1Flow = 80', $hub->vars['fan1Flow'] === 80.0);
check('fan2Flow = 75', $hub->vars['fan2Flow'] === 75.0);
check('systemSwitch = true', $hub->vars['systemSwitch'] === true);
check('faultSummary = true (Coil 6 gesetzt)', $hub->vars['faultSummary'] === true);
check('watchdogOk = false (Coil 1 nicht gesetzt)', $hub->vars['watchdogOk'] === false);
check('WireAddress wurde für jeden Zugriff verwendet (Dokuadresse - 1)', in_array(['holding', 2000, 1], $mb->calls, true) && in_array(['coils', 5, 1], $mb->calls, true), json_encode($mb->calls));

echo "5) Robatherm-Treiber: negative Temperatur (signed 16-bit) korrekt dekodiert\n";
$hub2 = new FakeHub();
$mb2 = new FakeModbus();
$mb2->registers[2000] = [0xFF9C]; // 65436 als signed16 = -100 -> -10.0 °C
$mb2->registers[2018] = [0];
$mb2->registers[2048] = [0];
$mb2->registers[2060] = [0];
$mb2->registers[2736] = [0];
$mb2->registers[2351] = [0];
$mb2->registers[2353] = [0];
$mb2->registers[1992] = [0];
$mb2->coils[5] = [0];
$mb2->coils[0] = [0];
$driver->readValues($mb2, $hub2);
check('negativer Rohwert korrekt als -10.0 °C dekodiert', abs($hub2->vars['outsideTemp'] - (-10.0)) < 1e-9, (string)($hub2->vars['outsideTemp'] ?? 'fehlt'));

echo "6) Robatherm-Treiber: fehlende Antwort (Register nicht lesbar) -> Zyklus meldet Fehler, Rest wird trotzdem verarbeitet\n";
$hub3 = new FakeHub();
$mb3 = new FakeModbus();
// Außenluft-Register absichtlich NICHT im Fake-Server hinterlegt -> readHolding liefert null.
$mb3->registers[2018] = [200];
$mb3->registers[2048] = [200];
$mb3->registers[2060] = [0];
$mb3->registers[2736] = [0];
$mb3->registers[2351] = [0];
$mb3->registers[2353] = [0];
$mb3->registers[1992] = [0];
$mb3->coils[5] = [0];
$mb3->coils[0] = [0];
$ok3 = $driver->readValues($mb3, $hub3);
check('Zyklus meldet Fehler', $ok3 === false);
check('outsideTemp bleibt unangetastet (kein Wert geschrieben)', !array_key_exists('outsideTemp', $hub3->vars));
check('heatRecoveryEfficiency wird bei fehlender Temperatur nicht berechnet', !array_key_exists('heatRecoveryEfficiency', $hub3->vars));
check('faultSummary wird trotzdem gelesen (Coil-Zugriff unabhängig vom Register-Fehler)', array_key_exists('faultSummary', $hub3->vars));

echo "7) RLTHub-Hauptklasse: DRIVERS-Eintrag instanziierbar, implementiert RLT_VentilationDriverInterface\n";
$ref = new ReflectionClass('RLTHub');
$driversConst = $ref->getConstant('DRIVERS');
check('robatherm_truecontrol registriert', isset($driversConst['robatherm_truecontrol']));
$driverClass = $driversConst['robatherm_truecontrol']['class'] ?? '';
check('Treiberklasse existiert und implementiert das Interface', class_exists($driverClass) && (new $driverClass()) instanceof RLT_VentilationDriverInterface, $driverClass);
check('getBaseVars() liefert alle Vertragsfelder (mind. 9 Basisvariablen)', count((new $driverClass())->getBaseVars()) >= 9);

echo "\n" . ($fails === 0 ? "ALLE PRÜFUNGEN BESTANDEN\n" : "$fails PRÜFUNG(EN) FEHLGESCHLAGEN\n");
exit($fails === 0 ? 0 : 1);
