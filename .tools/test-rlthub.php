<?php
/**
 * Prüfstand RLTHub: Modbus-Fassade (TCP + Gateway), beide Treiber (Robatherm
 * TrueControl, Proxon FWT), die Module RLTHub/RLTHubGateway/RLTHubDiscovery
 * gegen einen IPSModule-Nachbau mit den ECHTEN Signaturen (Argumentreihenfolge
 * z. B. bei IPS_GetObjectIDByIdent) und die Netzwerksuche gegen echte
 * Test-Modbus-Server.
 *
 *   php .tools/test-rlthub.php    # 0 = alle Prüfungen bestanden
 *
 * Anlass der Modul-Nachbildung: In 0.1.0 waren die Argumente von
 * IPS_GetObjectIDByIdent() vertauscht — die damaligen Prüfungen nutzten einen
 * Fake-Hub und konnten das nicht sehen.
 */

foreach (['VARIABLETYPE_BOOLEAN' => 0, 'VARIABLETYPE_INTEGER' => 1, 'VARIABLETYPE_FLOAT' => 2, 'VARIABLETYPE_STRING' => 3, 'KR_READY' => 10103] as $c => $v) {
    if (!defined($c)) { define($c, $v); }
}

// ---------------------------------------------------------------------------
// IP-Symcon-Nachbau (nur, was RLTHub braucht; Signaturen wie im echten IPS)
// ---------------------------------------------------------------------------
$GLOBALS['RLT_OBJ'] = [];        // objectID => ['ident','parent','type','value','profile','name']
$GLOBALS['RLT_NEXT'] = 1000;
$GLOBALS['RLT_PROFILES'] = [];
$GLOBALS['RLT_INSTANCES'] = [];  // instanceID => ['ConnectionID'=>int, 'module'=>guid, 'props'=>[]]

function IPS_GetObjectIDByIdent($ident, $parentId)
{
    foreach ($GLOBALS['RLT_OBJ'] as $id => $o) {
        if ($o['parent'] === $parentId && $o['ident'] === $ident) { return $id; }
    }
    trigger_error('Objekt #' . $parentId . '/' . $ident . ' nicht gefunden', E_USER_WARNING);
    return false;
}
function GetValue($id) { return $GLOBALS['RLT_OBJ'][$id]['value']; }
function SetValueFloat($id, $v) { $GLOBALS['RLT_OBJ'][$id]['value'] = (float)$v; return true; }
function SetValueInteger($id, $v) { $GLOBALS['RLT_OBJ'][$id]['value'] = (int)$v; return true; }
function SetValueBoolean($id, $v) { $GLOBALS['RLT_OBJ'][$id]['value'] = (bool)$v; return true; }
function IPS_VariableProfileExists($n) { return isset($GLOBALS['RLT_PROFILES'][$n]); }
function IPS_CreateVariableProfile($n, $t) { $GLOBALS['RLT_PROFILES'][$n] = ['type' => $t]; }
function IPS_SetVariableProfileDigits($n, $d) { $GLOBALS['RLT_PROFILES'][$n]['digits'] = $d; }
function IPS_SetVariableProfileText($n, $p, $s) { $GLOBALS['RLT_PROFILES'][$n]['suffix'] = $s; }
function IPS_SetVariableProfileIcon($n, $i) { $GLOBALS['RLT_PROFILES'][$n]['icon'] = $i; }
function IPS_GetInstanceListByModuleID($guid)
{
    $out = [];
    foreach ($GLOBALS['RLT_INSTANCES'] as $id => $i) { if (($i['module'] ?? '') === $guid) { $out[] = $id; } }
    return $out;
}
function IPS_GetName($id) { return 'Instanz ' . $id; }
function IPS_GetInstance($id) { return ['ConnectionID' => $GLOBALS['RLT_INSTANCES'][$id]['ConnectionID'] ?? 0]; }
function IPS_InstanceExists($id) { return isset($GLOBALS['RLT_INSTANCES'][$id]); }
function IPS_GetProperty($id, $name) { return $GLOBALS['RLT_INSTANCES'][$id]['props'][$name] ?? null; }
$GLOBALS['RLT_RUNLEVEL'] = KR_READY;
$GLOBALS['RLT_LOG'] = [];
$GLOBALS['RLT_MODOBJ'] = [];
function IPS_GetKernelRunlevel() { return $GLOBALS['RLT_RUNLEVEL']; }
function IPS_LogMessage($sender, $msg) { $GLOBALS['RLT_LOG'][] = [$sender, $msg]; }
// PREFIX_-Wrapper, wie sie Symcon aus den öffentlichen Methoden erzeugt.
foreach (['RLT', 'RLTGW', 'RLTD'] as $pre) {
    eval("function {$pre}_AdoptDismissState(\$id, \$what, \$value) { return \$GLOBALS['RLT_MODOBJ'][\$id]->AdoptDismissState(\$what, \$value); }");
    eval("function {$pre}_GetDismissState(\$id) { return \$GLOBALS['RLT_MODOBJ'][\$id]->GetDismissState(); }");
}

class IPSModule
{
    public $InstanceID;
    public $props = [];
    public $attrs = [];
    public $status = 0;
    public $timers = [];
    public $formUpdates = [];
    public $statusSeen = [];
    /** @var callable|null */
    public $parentFn = null;
    public function __construct($id = 0) { $this->InstanceID = $id; $GLOBALS['RLT_MODOBJ'][$id] = $this; }
    public function Create() {}
    public function ApplyChanges() {}
    public function RegisterPropertyString($n, $v) { $this->props[$n] = $v; }
    public function RegisterPropertyBoolean($n, $v) { $this->props[$n] = $v; }
    public function RegisterPropertyInteger($n, $v) { $this->props[$n] = $v; }
    public function ReadPropertyString($n) { return (string)$this->props[$n]; }
    public function ReadPropertyBoolean($n) { return (bool)$this->props[$n]; }
    public function ReadPropertyInteger($n) { return (int)$this->props[$n]; }
    public function RegisterAttributeInteger($n, $v) { $this->attrs[$n] = $v; }
    public function RegisterAttributeString($n, $v) { $this->attrs[$n] = $v; }
    public function RegisterAttributeBoolean($n, $v) { $this->attrs[$n] = $v; }
    public function ReadAttributeBoolean($n) { return (bool)$this->attrs[$n]; }
    public function WriteAttributeBoolean($n, $v) { $this->attrs[$n] = $v; }
    public function ReadAttributeInteger($n) { return (int)$this->attrs[$n]; }
    public function ReadAttributeString($n) { return (string)$this->attrs[$n]; }
    public function WriteAttributeInteger($n, $v) { $this->attrs[$n] = $v; }
    public function WriteAttributeString($n, $v) { $this->attrs[$n] = $v; }
    public function RegisterTimer($n, $i, $s) { $this->timers[$n] = ['interval' => $i, 'script' => $s]; }
    public function SetTimerInterval($n, $i) { $this->timers[$n]['interval'] = $i; }
    public function SetStatus($s) { $this->status = $s; $this->statusSeen[$s] = true; }
    public function GetStatus() { return $this->status; }
    public function UpdateFormField($f, $k, $v) { $this->formUpdates[] = [$f, $k, $v]; }
    public function SendDebug($a, $b, $c) {}
    protected function SendDataToParent($json) { return $this->parentFn ? ($this->parentFn)($json) : false; }
    private function reg($ident, $name, $profile, $type)
    {
        if (!$this->hasVar($ident)) {
            $GLOBALS['RLT_OBJ'][$GLOBALS['RLT_NEXT']++] = ['ident' => $ident, 'parent' => $this->InstanceID, 'type' => $type, 'value' => null, 'profile' => $profile, 'name' => $name];
        }
    }
    public function hasVar($ident)
    {
        foreach ($GLOBALS['RLT_OBJ'] as $o) { if ($o['parent'] === $this->InstanceID && $o['ident'] === $ident) { return true; } }
        return false;
    }
    public function varValue($ident)
    {
        foreach ($GLOBALS['RLT_OBJ'] as $o) { if ($o['parent'] === $this->InstanceID && $o['ident'] === $ident) { return $o['value']; } }
        return null;
    }
    public function RegisterVariableFloat($i, $n, $p = '', $pos = 0) { $this->reg($i, $n, $p, 2); }
    public function RegisterVariableInteger($i, $n, $p = '', $pos = 0) { $this->reg($i, $n, $p, 1); }
    public function RegisterVariableBoolean($i, $n, $p = '', $pos = 0) { $this->reg($i, $n, $p, 0); }
}
function AC_SetLoggingStatus() {}
function AC_SetAggregationType() {}

require_once dirname(__DIR__) . '/RLTHub/module.php';
require_once dirname(__DIR__) . '/RLTHubGateway/module.php';
require_once dirname(__DIR__) . '/RLTHubDiscovery/module.php';

$fails = 0;
function check($label, $cond, $detail = '')
{
    global $fails;
    if ($cond) { echo "  ok    $label\n"; }
    else { $fails++; echo "  FEHLT $label" . ($detail !== '' ? "  ($detail)" : '') . "\n"; }
}

/** Alle Formularelemente rekursiv (Panels, Popups) als flache Liste. */
function walkForm(array $elements): array
{
    $out = [];
    foreach ($elements as $e) {
        $out[] = $e;
        foreach (['items'] as $k) { if (isset($e[$k])) { $out = array_merge($out, walkForm($e[$k])); } }
        if (isset($e['popup']['items'])) { $out = array_merge($out, walkForm($e['popup']['items'])); }
    }
    return $out;
}

/** Test-Modbus-Server. Modi: echo (FC3/FC4 Wert = Adresse, FC1 festes Bitmuster) | map (Register aus JSON-Karte, sonst Exception) | zeros */
function startServer(string $mode, array $map = []): array
{
    $port = random_int(20000, 60000);
    $cnt  = tempnam(sys_get_temp_dir(), 'rltcnt');
    $code = <<<'PHP'
[$port, $cnt, $mode, $map] = [(int)$argv[1], $argv[2], $argv[3], json_decode($argv[4], true)];
$srv = stream_socket_server("tcp://127.0.0.1:$port", $e, $es);
if (!$srv) { exit(1); }
file_put_contents($cnt, '0');
$rx = function ($c, $n) { $b = ''; while (strlen($b) < $n) { $x = fread($c, $n - strlen($b)); if ($x === false || $x === '') { return null; } $b .= $x; } return $b; };
while ($c = @stream_socket_accept($srv, 30)) {
    while (true) {
        $head = $rx($c, 7);
        if ($head === null) { break; }
        $h = unpack('ntid/npid/nlen/Cunit', $head);
        $pdu = $rx($c, $h['len'] - 1);
        if ($pdu === null) { break; }
        $fc = ord($pdu[0]);
        $a = unpack('nstart/ncount', substr($pdu, 1, 4));
        if (($fc === 3 || $fc === 4) && $mode === 'echo') {
            $data = '';
            for ($i = 0; $i < $a['count']; $i++) { $data .= pack('n', ($a['start'] + $i) & 0xFFFF); }
            $resp = chr($fc) . chr(strlen($data)) . $data;
        } elseif (($fc === 3 || $fc === 4) && $mode === 'map') {
            $data = '';
            $okAll = true;
            for ($i = 0; $i < $a['count']; $i++) {
                $key = $fc . ':' . ($a['start'] + $i);
                if (!isset($map[$key])) { $okAll = false; break; }
                $data .= pack('n', $map[$key] & 0xFFFF);
            }
            $resp = $okAll ? chr($fc) . chr(strlen($data)) . $data : chr($fc | 0x80) . chr(2);
        } elseif (($fc === 3 || $fc === 4) && $mode === 'zeros') {
            $data = str_repeat("\x00\x00", $a['count']);
            $resp = chr($fc) . chr(strlen($data)) . $data;
        } elseif ($fc === 1) {
            $resp = chr($fc) . chr(1) . chr(0b00000101);
        } else {
            $resp = chr($fc | 0x80) . chr(1);
        }
        fwrite($c, pack('nnn', $h['tid'], 0, strlen($resp) + 1) . chr($h['unit']) . $resp);
    }
    fclose($c);
}
PHP;
    $proc = proc_open([PHP_BINARY, '-r', $code, (string)$port, $cnt, $mode, json_encode($map)], [], $pipes);
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

class FakeModbus implements RLT_ModbusClientInterface
{
    public $holding = [];
    public $input = [];
    public $coils = [];
    public $calls = [];
    public function readHolding($startReg, $count) { $this->calls[] = ['holding', $startReg]; return $this->holding[$startReg] ?? null; }
    public function readInput($startReg, $count) { $this->calls[] = ['input', $startReg]; return $this->input[$startReg] ?? null; }
    public function readCoils($startReg, $count) { $this->calls[] = ['coils', $startReg]; return $this->coils[$startReg] ?? null; }
    public function close(): void {}
}
class FakeHub
{
    public $vars = [];
    public $oneBased;
    public function __construct(bool $oneBased) { $this->oneBased = $oneBased; }
    public function SetVarFloat(string $i, float $v): void { $this->vars[$i] = $v; }
    public function SetVarInteger(string $i, int $v): void { $this->vars[$i] = $v; }
    public function SetVarBoolean(string $i, bool $v): void { $this->vars[$i] = $v; }
    public function WireAddress(int $d): int { return $this->oneBased ? $d - 1 : $d; }
}

// ===========================================================================
echo "1) RLT_ModbusTcpClient gegen echten Test-Server: FC3, FC4, Coils\n";
$s = startServer('echo');
$mb = new RLT_ModbusTcpClient('127.0.0.1', $s[1], 1);
check('implementiert Interface', $mb instanceof RLT_ModbusClientInterface);
check('readHolding (FC3)', ($mb->readHolding(2001, 1)[0] ?? null) === 2001);
check('readInput (FC4)', ($mb->readInput(195, 2) ?? null) === [195, 196]);
check('readCoils: LSB zuerst', $mb->readCoils(6, 3) === [0 => 1, 1 => 0, 2 => 1]);
$mb->close();
stopServer($s);

echo "2) RLT_ModbusGatewayClient: Schema, Dekodierung, Fehlerfälle\n";
$calls = [];
$send = function (string $json) use (&$calls) {
    $calls[] = json_decode($json, true);
    $req = end($calls);
    if ($req['Function'] === 1) { return "\x01\x01" . chr(0b00000101); }
    $data = '';
    for ($i = 0; $i < $req['Quantity']; $i++) { $data .= pack('n', ($req['Address'] + $i) & 0xFFFF); }
    return chr($req['Function']) . chr(strlen($data)) . $data;
};
$gw = new RLT_ModbusGatewayClient($send);
check('implementiert Interface', $gw instanceof RLT_ModbusClientInterface);
check('readHolding: Schema korrekt', ($gw->readHolding(100, 2)) === [100, 101] && $calls[0] === ['DataID' => '{E310B701-4AE7-458E-B618-EC13A1A6F6A8}', 'Function' => 3, 'Address' => 100, 'Quantity' => 2, 'Data' => ''], json_encode($calls[0]));
check('readInput: Function 4', $gw->readInput(195, 1) === [195] && $calls[1]['Function'] === 4);
check('readCoils: Function 1, Bits', $gw->readCoils(6, 3) === [0 => 1, 1 => 0, 2 => 1] && $calls[2]['Function'] === 1);
$gwEx = new RLT_ModbusGatewayClient(function ($j) { return "\x83\x02"; });
check('Modbus-Exception -> null, Grund benannt', $gwEx->readHolding(1, 1) === null && strpos($gwEx->lastError, 'Exception') !== false, $gwEx->lastError);
$n = 0;
$gwSilent = new RLT_ModbusGatewayClient(function ($j) use (&$n) { $n++; return false; });
$gwSilent->readInput(1, 1); $gwSilent->readInput(2, 1); $gwSilent->readInput(3, 1); $gwSilent->readInput(4, 1);
check('stummes Gerät: nach 2 Fehlversuchen wird der Rest des Zyklus übersprungen', $n === 2, (string)$n);

echo "3) Robatherm-Treiber gegen Fake-Modbus (Dokuadresse − 1)\n";
$drv = new RLT_RobathermTrueControlDriver();
$hub = new FakeHub(true);
$mb = new FakeModbus();
foreach ([2000 => 235, 2018 => 210, 2048 => 220, 2060 => 420, 2736 => 1234, 2351 => 80, 2353 => 75, 1992 => 1] as $a => $v) { $mb->holding[$a] = [$v]; }
$mb->coils[5] = [1];
$mb->coils[0] = [0];
check('Zyklus ok', $drv->readValues($mb, $hub) === true);
check('Temperaturen ÷10', abs($hub->vars['outsideTemp'] - 23.5) < 1e-9 && abs($hub->vars['supplyTemp'] - 21.0) < 1e-9);
check('WRG auf 100 begrenzt', $hub->vars['heatRecoveryEfficiency'] === 100.0);
check('Rohwerte co2/filter/fan', $hub->vars['co2'] === 420.0 && $hub->vars['filterRuntimeHours'] === 1234.0 && $hub->vars['fan1Flow'] === 80.0 && $hub->vars['fan2Flow'] === 75.0);
check('Störung/Schalter/Watchdog', $hub->vars['faultSummary'] === true && $hub->vars['systemSwitch'] === true && $hub->vars['watchdogOk'] === false);
$mb2 = new FakeModbus();
foreach ([2000 => 0xFF9C, 2018 => 0, 2048 => 0, 2060 => 0, 2736 => 0, 2351 => 0, 2353 => 0, 1992 => 0] as $a => $v) { $mb2->holding[$a] = [$v]; }
$mb2->coils[5] = [0]; $mb2->coils[0] = [0];
$h2 = new FakeHub(true);
$drv->readValues($mb2, $h2);
check('negative Temperatur (signed16)', abs($h2->vars['outsideTemp'] + 10.0) < 1e-9);
$mb3 = new FakeModbus();
$h3 = new FakeHub(true);
check('fehlende Register -> Zyklus meldet Fehler, nichts geschrieben', $drv->readValues($mb3, $h3) === false && !isset($h3->vars['outsideTemp']));
check('probe: plausible Temperaturen erkannt', $drv->probe((function () { $m = new FakeModbus(); foreach ([2000 => 235, 2018 => 210, 2048 => 220] as $a => $v) { $m->holding[$a] = [$v]; } return $m; })()) !== null);
check('probe: nur Nullen ist kein Fund', $drv->probe((function () { $m = new FakeModbus(); foreach ([2000, 2018, 2048] as $a) { $m->holding[$a] = [0]; } return $m; })()) === null);

echo "4) Proxon-FWT-Treiber (Excel-Nummer = Wire-Adresse, FC4 für Temperaturen/Störung, FC3 für Filter/Betriebsart)\n";
$px = new RLT_ProxonFwtDriver();
$hp = new FakeHub(false);
$m = new FakeModbus();
$m->input[198] = [500];    // T3 Frischluft 5,00 °C
$m->input[195] = [2100];   // T1 Zuluft 21,00 °C
$m->input[196] = [2200];   // T7 Abluft 22,00 °C
$m->input[47]  = [0];      // keine Störung
$m->holding[469] = [321];  // Gerätefilter Stunden
$m->holding[16]  = [2];    // Betriebsart EcoWinter
check('Zyklus ok', $px->readValues($m, $hp) === true);
check('T3/T1/T7 -> outside/supply/extract (Roh/100)', abs($hp->vars['outsideTemp'] - 5.0) < 1e-9 && abs($hp->vars['supplyTemp'] - 21.0) < 1e-9 && abs($hp->vars['extractTemp'] - 22.0) < 1e-9);
// eta = (21-5)/(22-5) = 94,1 %
check('WRG berechnet (94,1 %)', abs($hp->vars['heatRecoveryEfficiency'] - 94.1176) < 0.01, (string)$hp->vars['heatRecoveryEfficiency']);
check('Filterstunden, keine Störung, Betriebsart an', $hp->vars['filterRuntimeHours'] === 321.0 && $hp->vars['faultSummary'] === false && $hp->vars['systemSwitch'] === true);
check('Adressen ohne Umrechnung: 198/195/196/47 (FC4) und 469/16 (FC3)', in_array(['input', 198], $m->calls, true) && in_array(['input', 47], $m->calls, true) && in_array(['holding', 469], $m->calls, true) && in_array(['holding', 16], $m->calls, true), json_encode($m->calls));
$m->input[47] = [5];
$hp2 = new FakeHub(false);
$px->readValues($m, $hp2);
check('Störung ≠ 0 -> faultSummary true', $hp2->vars['faultSummary'] === true);
$mw = new FakeModbus();
$mw->input[198] = [0xFF38]; $mw->input[195] = [2100]; $mw->input[196] = [2200]; $mw->input[47] = [0]; $mw->holding[469] = [1]; $mw->holding[16] = [0];
$hw = new FakeHub(false);
$px->readValues($mw, $hw);
check('Wert ≥ 32768 wird als negativ gelesen (-2,00 °C), Betriebsart Aus -> false', abs($hw->vars['outsideTemp'] + 2.0) < 1e-9 && $hw->vars['systemSwitch'] === false);
check('Proxon liefert kein co2/fan (nicht in getBaseVars)', !in_array('co2', array_column($px->getBaseVars(), 0), true) && !in_array('fan1Flow', array_column($px->getBaseVars(), 0), true));

echo "4b) Proxon FWT: echte Messwerte einer FWT (21.09.2026, Rohwert gegen Display) als Regressionsfall\n";
$real = new FakeModbus();
$real->input[198] = [1420];  // T3 Frischluft, Display 14,2 °C
$real->input[195] = [1825];  // T1 Zuluft, Display 18,2 °C (Excel-Wert 18,25)
$real->input[196] = [2420];  // T7 Abluft, Display 24,2 °C
$real->input[47]  = [0];     // keine Störung
$real->holding[469] = [1069];// Filterstunden Gerät, Display 1069
$real->holding[16]  = [1];   // Betriebsart 1 = EcoSommer
$hr = new FakeHub(false);
check("echte FWT-Messwerte: Zyklus ok", (new RLT_ProxonFwtDriver())->readValues($real, $hr) === true);
check("echte FWT-Messwerte: 14,2 / 18,25 / 24,2 °C", abs($hr->vars['outsideTemp'] - 14.2) < 1e-9 && abs($hr->vars['supplyTemp'] - 18.25) < 1e-9 && abs($hr->vars['extractTemp'] - 24.2) < 1e-9, json_encode([$hr->vars['outsideTemp'], $hr->vars['supplyTemp'], $hr->vars['extractTemp']]));
check("echte FWT-Messwerte: Wirkungsgrad (18,25-14,2)/(24,2-14,2) = 40,5 %", abs($hr->vars['heatRecoveryEfficiency'] - 40.5) < 1e-6, (string)$hr->vars['heatRecoveryEfficiency']);
check("echte FWT-Messwerte: Filterstunden 1069, keine Störung, Betriebsart an", $hr->vars['filterRuntimeHours'] === 1069.0 && $hr->vars['faultSummary'] === false && $hr->vars['systemSwitch'] === true);
$conf = RLT_Drivers::DRIVERS['proxon_fwt']['confidence'];
$notes = implode(' ', (new RLT_ProxonFwtDriver())->getNotes());
check("Vertrauensangabe FWT nennt die echte Prüfung UND die offenen Punkte (Minusgrade, Störungscodes)", strpos($conf, '21.09.2026') !== false && strpos($conf, 'unter 0 °C') !== false && strpos($conf, 'Störungscodes') !== false && strpos($notes, 'Annahme') !== false);
check("Robatherm bleibt ehrlich als ungeprüft gekennzeichnet", strpos(RLT_Drivers::DRIVERS['robatherm_truecontrol']['confidence'], 'noch nicht an Hardware verifiziert') !== false);

echo "5) RLTHub (TCP) gegen den IPSModule-Nachbau: Variablen, Zyklus, Vertrag, Formular\n";
class TestHub extends RLTHub
{
    public $mb;
    protected function rltClient(): RLT_ModbusClientInterface { return $this->mb; }
}
$hubm = new TestHub(500);
$hubm->Create();
check('Timer mit Modul-Präfix registriert', $hubm->timers['ReadValuesTimer']['script'] === 'RLT_ReadValues($_IPS[\'TARGET\']);', $hubm->timers['ReadValuesTimer']['script']);
$hubm->ApplyChanges();
check('ohne Host: Status 104, Timer aus', $hubm->status === 104 && $hubm->timers['ReadValuesTimer']['interval'] === 0);
$hubm->props['Host'] = '192.0.2.10';
$hubm->ApplyChanges();
check('mit Host: Status 102, Timer 60 s', $hubm->status === 102 && $hubm->timers['ReadValuesTimer']['interval'] === 60000);
check('Variablen wirklich unter der Instanz angelegt', $hubm->hasVar('outsideTemp') && $hubm->hasVar('faultSummary') && $hubm->hasVar('lastSeenAt'));
$hubm->mb = new FakeModbus();
foreach ([2000 => 235, 2018 => 210, 2048 => 220, 2060 => 420, 2736 => 1234, 2351 => 80, 2353 => 75, 1992 => 1] as $a => $v) { $hubm->mb->holding[$a] = [$v]; }
$hubm->mb->coils[5] = [1]; $hubm->mb->coils[0] = [1];
$hubm->ReadValues();
check('ReadValues schreibt in die echten Variablen (Reihenfolge Ident/Eltern-ID stimmt)', abs($hubm->varValue('outsideTemp') - 23.5) < 1e-9 && $hubm->varValue('faultSummary') === true && $hubm->varValue('co2') === 420.0);
check('Status 102, lastSeenAt gesetzt', $hubm->status === 102 && $hubm->varValue('lastSeenAt') > 0 && $hubm->ReadAttributeInteger('LastSeenAt') > 0);
$f = $hubm->GetFunctions()[0];
check('Vertrag: contractVersion 1.0, alle Felder da', $f['contractVersion'] === '1.0' && count(array_intersect(['outsideTempID', 'supplyTempID', 'extractTempID', 'co2ID', 'heatRecoveryEfficiencyID', 'filterRuntimeHoursID', 'fan1FlowID', 'fan2FlowID', 'faultSummaryID', 'lastSeenAt', 'pollInterval', 'reachable', 'Caption', 'Measured', 'unit'], array_keys($f))) === 15);
check('Vertrag: IDs zeigen auf reale Variablen', $f['outsideTempID'] > 0 && $f['faultSummaryID'] > 0 && $f['co2ID'] > 0 && $f['reachable'] === true && $f['pollInterval'] === 60);
check('Vertrag: kein watchdogOkID (SUITE.md)', !array_key_exists('watchdogOkID', $f) && !array_key_exists('faultUrgentID', $f));
$hubm->mb = new FakeModbus();
$hubm->ReadValues();
check('fehlgeschlagener Zyklus: Status 201, reachable false', $hubm->status === 201 && $hubm->GetFunctions()[0]['reachable'] === false);
$hubm->props['AddressBase'] = 'auto';
check('WireAddress auto (Robatherm): Doku − 1', $hubm->WireAddress(2001) === 2000);
$hubm->props['AddressBase'] = 'zero';
check('WireAddress zero: unverändert', $hubm->WireAddress(2001) === 2001);
$hubm->props['AddressBase'] = 'one';
check('WireAddress one: Doku − 1', $hubm->WireAddress(2001) === 2000);
$form = json_decode($hubm->GetConfigurationForm(), true);
$deviceOpts = null;
foreach ($form['elements'] as $e) { if (($e['name'] ?? '') === 'Device') { $deviceOpts = array_column($e['options'], 'value'); } }
check('Formular: TCP-Modul bietet nur TCP-Treiber an', $deviceOpts === ['robatherm_truecontrol'], json_encode($deviceOpts));
check('Formular: onChange nutzt das Modulpräfix', strpos($hubm->GetConfigurationForm(), 'RLT_OnChangeDevice') !== false);

echo "6) RLTHubGateway: Parent-Erkennung, Zyklus über das Gateway\n";
class TestGw extends RLTHubGateway {}
$gwm = new TestGw(600);
$gwm->Create();
$GLOBALS['RLT_LOG'] = [];
$gwm->ApplyChanges();
check('ohne Gateway: Status 201, Timer läuft trotzdem (Gateway kann später kommen)', $gwm->status === 201 && $gwm->timers['ReadValuesTimer']['interval'] === 60000);
check('Timer mit Gateway-Präfix', $gwm->timers['ReadValuesTimer']['script'] === 'RLTGW_ReadValues($_IPS[\'TARGET\']);');
check('Standardgerät Proxon FWT', $gwm->ReadPropertyString('Device') === 'proxon_fwt');
$gwm->ReadValues(); $gwm->ReadValues();
check('ohne Parent kein Absturz, Status bleibt 201', $gwm->status === 201);
check('ohne Parent: wahrer Grund im Protokoll (einmalig), nicht „Regler antwortet nicht“', count($GLOBALS['RLT_LOG']) === 1 && strpos($GLOBALS['RLT_LOG'][0][1], 'Kein ModBus-Gateway verbunden') !== false, json_encode($GLOBALS['RLT_LOG'], JSON_UNESCAPED_UNICODE));
check('ohne Parent: Verbindungstest nennt den wahren Grund', strpos($gwm->TestConnection(), 'Kein ModBus-Gateway verbunden') !== false && strpos($gwm->TestConnection(), 'antwortet nicht') === false);
$GLOBALS['RLT_INSTANCES'][600] = ['ConnectionID' => 601];
$GLOBALS['RLT_INSTANCES'][601] = ['module' => '{A5F663AB-C400-4FE5-B207-4D67CC030564}', 'props' => ['DeviceID' => 41]];
$gwm->parentFn = function (string $json) {
    $r = json_decode($json, true);
    $vals = [198 => 500, 195 => 2100, 196 => 2200, 47 => 0, 469 => 321, 16 => 2];
    if (!isset($vals[$r['Address']])) { return false; }
    return chr($r['Function']) . "\x02" . pack('n', $vals[$r['Address']]);
};
$gwm->ApplyChanges();
check('mit Gateway: Status 102', $gwm->status === 102);
$gwm->ReadValues();
check('Zyklus über das Gateway liefert Werte (Funktionscodes 4/3, Adressen unverändert)', abs($gwm->varValue('outsideTemp') - 5.0) < 1e-9 && $gwm->varValue('filterRuntimeHours') === 321.0 && $gwm->status === 102);
$gf = $gwm->GetFunctions()[0];
check('Vertrag Proxon: co2ID/fan1FlowID/fan2FlowID = 0, Rest belegt', $gf['co2ID'] === 0 && $gf['fan1FlowID'] === 0 && $gf['fan2FlowID'] === 0 && $gf['outsideTempID'] > 0 && $gf['heatRecoveryEfficiencyID'] > 0 && $gf['faultSummaryID'] > 0);
check('WireAddress auto (Proxon): unverändert', $gwm->WireAddress(195) === 195);
$gform = json_decode($gwm->GetConfigurationForm(), true);
$gdev = null;
foreach ($gform['elements'] as $e) { if (($e['name'] ?? '') === 'Device') { $gdev = array_column($e['options'], 'value'); } }
check('Formular: Gateway-Modul bietet nur RTU-Treiber an', $gdev === ['proxon_fwt'], json_encode($gdev));
check('module.json: Parent- und Kind-GUID wie im Vorbild (WPModbusHubGateway)', (function () {
    $j = json_decode(file_get_contents(dirname(__DIR__) . '/RLTHubGateway/module.json'), true);
    return $j['parentRequirements'] === ['{E310B701-4AE7-458E-B618-EC13A1A6F6A8}'] && $j['implemented'] === ['{77B31ABB-18FA-4B91-BB63-E5B2AB5588F4}'] && $j['prefix'] === 'RLTGW';
})());

echo "7) RLTHubDiscovery: echte Netzwerksuche gegen Test-Server\n";
$good = startServer('map', ['3:2000' => 235, '3:2018' => 210, '3:2048' => 220]);
$disc = new RLTHubDiscovery(700);
$disc->Create();
$res = $disc->Discover('127.0.0.1', '127.0.0.1', $good[1], 1);
$rows = json_decode($disc->ReadAttributeString('ResultsJSON'), true);
check('Robatherm-Register erkannt', count($rows) === 1 && $rows[0]['device'] === 'robatherm_truecontrol' && $rows[0]['host'] === '127.0.0.1', $res);
$upd = null;
foreach ($disc->formUpdates as $u) { if ($u[0] === 'Configurator') { $upd = json_decode($u[2], true); } }
check('Configurator-Zeile mit Anlegen-Angaben (Modul-GUID, Host, Port, Unit, Gerätetyp)', $upd !== null && $upd[0]['create']['moduleID'] === '{19C33A5B-8C10-45F5-9A33-9928D9005B69}' && $upd[0]['create']['configuration']['Host'] === '127.0.0.1' && $upd[0]['create']['configuration']['Port'] === $good[1] && $upd[0]['create']['configuration']['Device'] === 'robatherm_truecontrol');
$GLOBALS['RLT_INSTANCES'][800] = ['module' => '{19C33A5B-8C10-45F5-9A33-9928D9005B69}', 'props' => ['Host' => '127.0.0.1', 'Port' => $good[1], 'UnitId' => 1]];
$disc->Discover('127.0.0.1', '127.0.0.1', $good[1], 1);
$upd = null;
foreach ($disc->formUpdates as $u) { if ($u[0] === 'Configurator') { $upd = json_decode($u[2], true); } }
check('bereits vorhandene Instanz wird zugeordnet (instanceID)', ($upd[0]['instanceID'] ?? 0) === 800);
unset($GLOBALS['RLT_INSTANCES'][800]);
stopServer($good);
$zeros = startServer('zeros');
$disc->Discover('127.0.0.1', '127.0.0.1', $zeros[1], 1);
check('Gerät, das überall 0 liefert, ist kein Fund', json_decode($disc->ReadAttributeString('ResultsJSON'), true) === []);
stopServer($zeros);
$exc = startServer('map', []);
$disc->Discover('127.0.0.1', '127.0.0.1', $exc[1], 1);
check('Gerät mit Modbus-Exceptions ist kein Fund', json_decode($disc->ReadAttributeString('ResultsJSON'), true) === []);
stopServer($exc);
$disc->Discover('127.0.0.1', '127.0.0.1', 1, 1);
check('geschlossener Port: kein Fund, kein Absturz', json_decode($disc->ReadAttributeString('ResultsJSON'), true) === [] && strpos($disc->ReadAttributeString('ScanDetails'), '0 mit offenem Port') !== false, $disc->ReadAttributeString('ScanDetails'));
check('ungültiger Bereich wird gemeldet', strpos($disc->Discover('abc', 'def', 502, 1), 'Ungültiger') !== false);
$dform = json_decode($disc->GetConfigurationForm(), true);
check('Formular enthält Configurator und Suchknopf mit Modulpräfix', strpos($disc->GetConfigurationForm(), 'RLTD_Discover') !== false && count(array_filter(walkForm($dform['elements']), function ($e) { return ($e['type'] ?? '') === 'Configurator'; })) === 1);


echo "9) Modul-Konventionen (SUITE.md: Formular-Optik, Store-Review, Status, Rückmeldung, Sprache)\n";
// Testreste aus Abschnitt 6 entfernen: die Suche listet zur Laufzeit die Gateways der ANLAGE des Nutzers auf.
unset($GLOBALS['RLT_INSTANCES'][600], $GLOBALS['RLT_INSTANCES'][601]);
$lib = json_decode(file_get_contents(dirname(__DIR__) . '/library.json'), true);
$root = dirname(__DIR__);
$fresh = [
    'RLTHub'          => new RLTHub(900),
    'RLTHubGateway'   => new RLTHubGateway(901),
    'RLTHubDiscovery' => new RLTHubDiscovery(902),
];
$forms = [];
foreach ($fresh as $name => $m) {
    $m->Create();
    $m->ApplyChanges();
    $forms[$name] = json_decode($m->GetConfigurationForm(), true);
}
foreach ($forms as $name => $form) {
    $el = $form['elements'];
    $n = count($el);
    check("$name: Panel-Reihenfolge Wozu → Neu → Doku & Hilfe (eingeklappt) …", ($el[0]['name'] ?? '') === 'PurposeIntroPanel' && ($el[1]['name'] ?? '') === 'NewsPanel' && strpos($el[2]['caption'] ?? '', '📖 Dokumentation & Hilfe') === 0 && $el[2]['expanded'] === false);
    check("$name: … Forum-Hinweis, dann „Über dieses Modul“ ganz unten", ($el[$n - 2]['name'] ?? '') === 'ForumHintPanel' && strpos($el[$n - 1]['caption'] ?? '', '🧡  Über dieses Modul') === 0);
    check("$name: Wozu- und Neu-Panel aufgeklappt, Über-Panel eingeklappt und NICHT wegklickbar (kein name)", $el[0]['expanded'] === true && $el[1]['expanded'] === true && $el[$n - 1]['expanded'] === false && !isset($el[$n - 1]['name']));
    check("$name: Neu-Panel trägt die eigene Neuigkeits-Version in der Caption", strpos($el[1]['caption'], constant($name . '::NEWS_VERSION')) !== false, $el[1]['caption']);
    $dokuText = json_encode($el[2], JSON_UNESCAPED_UNICODE);
    check("$name: Doku-Panel nennt die Versionsnummer", strpos($dokuText, 'Version ' . $lib['version']) !== false);
    $paypal = 0; $license = 0;
    foreach (walkForm($el) as $e) {
        if (($e['type'] ?? '') === 'Button' && isset($e['link'])) {
            if ($e['link'] !== true || strpos($e['onClick'], "echo '") !== 0) { $paypal = -100; }
            if (strpos($e['onClick'], 'paypal.me/DietmarGureth') !== false) { $paypal++; }
            if (strpos($e['onClick'], 'github.com/DG65/NRGRLTHub/blob/beta/LICENSE') !== false) { $license++; }
        }
    }
    check("$name: Link-Schaltflächen nutzen onClick=echo + link=true; PayPal und Lizenz je einmal, Lizenz auf Repo NRGRLTHub/beta", $paypal === 1 && $license === 1, "paypal=$paypal license=$license");
    $all = json_encode(walkForm($el), JSON_UNESCAPED_UNICODE);
    // Der Suchbereich-Hinweis nennt zur Laufzeit das EIGENE Netz des Nutzers (abgeleitet, nicht fest im Code).
    $allNoRuntime = preg_replace('/🔗 Suchbereich: [\d.]+ bis [\d.]+ \(automatisch aus dem eigenen Netz\)\./u', '', $all);
    check("$name: Über-Panel im Wortlaut „Variante A“", strpos($all, 'Entstanden aus echter Begeisterung für die eigene Anlage — und ein paar durchgetippten Abenden.') !== false && strpos($all, 'dietmar@gureth.eu') !== false && strpos($all, 'Über eine kleine Spende freue ich mich') !== false);
    $badOnClick = false;
    foreach (walkForm($el) as $e) {
        if (isset($e['onClick']) && (strpos($e['onClick'], '$_IPS') !== false)) { $badOnClick = true; }
    }
    check("$name: kein \$_IPS['TARGET'] in onClick (Store-Review 10)", !$badOnClick);
    $doubleQ = false; $popupOk = true;
    foreach (walkForm($el) as $e) {
        if (($e['type'] ?? '') === 'PopupButton') {
            if (preg_match('/\?\s*\?/u', $e['caption'])) { $doubleQ = true; }
            if (substr(trim($e['caption']), -1) !== '?') { $popupOk = false; }
        }
    }
    check("$name: PopupButton-Captions sind die volle Frage, ohne zweites „?“", !$doubleQ && $popupOk);
    check("$name: kein RowLayout (nie mehrere Eingabefelder in eine Reihe)", strpos($all, 'RowLayout') === false);
    $foreign = preg_match('/.{0,25}(#\d{3,}|192\.168\.|10\.\d+\.\d+\.\d+|Otto|Zepp|Ghostraider).{0,25}/u', $allNoRuntime, $fm2);
    check("$name: keine fremden Anlagendetails im Formular (IDs, private IPs, Namen)", !$foreign, $foreign ? $fm2[0] : '');
    $anglicisms = [];
    foreach (['Scan ', 'Button ', 'Dry-Run', 'Polling', 'Framework', 'Event '] as $w) { if (strpos($all, $w) !== false) { $anglicisms[] = $w; } }
    check("$name: keine vermeidbaren Anglizismen im Formular", $anglicisms === [], implode(',', $anglicisms));
    $ackTargets = 0;
    foreach (walkForm($el) as $e) {
        if (($e['type'] ?? '') === 'Button' && isset($e['onClick']) && preg_match('/^[A-Z]+_Ack\w+\(\$id\);$/', $e['onClick'])) { $ackTargets++; }
    }
    check("$name: drei Ausblenden-Schaltflächen (Wozu, Neu, Forum) rufen PREFIX_Ack…(\$id)", $ackTargets === 3, (string)$ackTargets);
}
// Ein News-Panel gibt es nur bei echten Neuigkeiten (keine erfundene Neuigkeit): die Version eines Moduls
// steht nie über der library.json-Version, und das Modul mit der jüngsten Neuigkeit trägt genau sie.
check("Neu-Versionen: nie über der library.json-Version und nicht ohne Inhalt (NEWS nicht leer)", version_compare(RLTHub::NEWS_VERSION, $lib['version'], '<=') && version_compare(RLTHubGateway::NEWS_VERSION, $lib['version'], '<=') && version_compare(RLTHubDiscovery::NEWS_VERSION, $lib['version'], '<=') && count(RLTHub::NEWS) > 0 && count(RLTHubGateway::NEWS) > 0 && count(RLTHubDiscovery::NEWS) > 0, $lib['version']);
check("library.json: nur id/author/name/url/compatibility/version/build/date, compatibility als {version}", array_keys($lib) === ['id', 'author', 'name', 'url', 'compatibility', 'version', 'build', 'date'] && array_keys($lib['compatibility']) === ['version']);
foreach (['RLTHub', 'RLTHubGateway', 'RLTHubDiscovery'] as $mod) {
    $j = json_decode(file_get_contents("$root/$mod/module.json"), true);
    check("$mod: Modulname ohne „IPS“/„Symcon“, vendor leer (kein Gerätehersteller), Präfix eigen", stripos($j['name'] . ' ' . implode(' ', $j['aliases']), 'ips') === false && stripos($j['name'] . ' ' . implode(' ', $j['aliases']), 'symcon') === false && $j['vendor'] === '' && in_array($j['prefix'], ['RLT', 'RLTGW', 'RLTD'], true));
}
check("LICENSE: PolyForm Noncommercial 1.0.0 mit deutschem Vorspann", strpos(file_get_contents("$root/LICENSE"), 'NRG-Stack — Lizenz') === 0 && strpos(file_get_contents("$root/LICENSE"), 'PolyForm Noncommercial License 1.0.0') !== false);

// Öffentliche Funktionen: keine Standardwerte, nur skalare/keine Typen (Wrapper honorieren keine Defaults)
$badSig = [];
foreach (['RLTHub', 'RLTHubGateway', 'RLTHubDiscovery'] as $cls) {
    foreach ((new ReflectionClass($cls))->getMethods(ReflectionMethod::IS_PUBLIC) as $rm) {
        if ($rm->class === 'IPSModule' || $rm->isStatic() || $rm->isConstructor()) { continue; }
        foreach ($rm->getParameters() as $rp) {
            $t = $rp->getType();
            if ($rp->isDefaultValueAvailable() || ($t !== null && !in_array($t->getName(), ['string', 'int', 'float', 'bool'], true))) { $badSig[] = "$cls::{$rm->name}"; }
        }
    }
}
check("öffentliche Methoden: keine PHP-Standardwerte, nur skalare Parametertypen", $badSig === [], implode(',', $badSig));
$gfRet = (new ReflectionMethod('RLTHub', 'TestConnection'))->getReturnType();
check("Rückmeldungs-Methoden liefern Text zum Anzeigen; Vertragsfunktion GetFunctions liefert Daten (nie Text-als-bool)", $gfRet !== null && $gfRet->getName() === 'string' && (new ReflectionMethod('RLTHub', 'GetFunctions'))->getReturnType()->getName() === 'array');

// Status-Codes: jeder tatsächlich gesetzte Code steht in form["status"]
$hubT = new TestHub(910); $hubT->Create(); $hubT->props['Host'] = '192.0.2.1'; $hubT->ApplyChanges(); $hubT->mb = new FakeModbus(); $hubT->ReadValues();
$hubT->props['Active'] = false; $hubT->ApplyChanges();
$formT = json_decode($hubT->GetConfigurationForm(), true);
$declared = array_column($formT['status'], 'code');
check("Status-Codes: jeder gesetzte Code (102/104/201) hat einen form-status-Eintrag mit Icon", count(array_diff(array_keys($hubT->statusSeen), $declared)) === 0 && count(array_filter($formT['status'], function ($x) { return in_array($x['icon'], ['active', 'inactive', 'error'], true); })) === count($formT['status']), json_encode(array_keys($hubT->statusSeen)));
check("Status: inaktiv/unvollständig ist 104 (kein Fehlercode > 200, Store-Review 9d)", (function () {
    $m = new RLTHub(911); $m->Create(); $m->ApplyChanges(); $one = $m->status;
    $m->props['Host'] = '192.0.2.1'; $m->props['Active'] = false; $m->ApplyChanges();
    return $one === 104 && $m->status === 104;
})());

// Ausblenden: Bestätigen, Dauerhaftigkeit, Teilen nur zwischen Instanzen DESSELBEN Typs
$a = new RLTHub(920); $b = new RLTHub(921); $c = new RLTHubGateway(922);
foreach ([$a, $b, $c] as $m) { $m->Create(); }
$GLOBALS['RLT_INSTANCES'][920] = ['module' => RLTHub::MODULE_GUID, 'props' => []];
$GLOBALS['RLT_INSTANCES'][921] = ['module' => RLTHub::MODULE_GUID, 'props' => []];
$GLOBALS['RLT_INSTANCES'][922] = ['module' => RLTHubGateway::MODULE_GUID, 'props' => []];
$a->AckPurposeIntro(); $a->AckNews(); $a->AckForumHint();
$names = function ($m) { return array_column(array_filter(json_decode($m->GetConfigurationForm(), true)['elements'], function ($e) { return isset($e['name']); }), 'name'); };
check("Bestätigen blendet Wozu/Neu/Forum an dieser Instanz dauerhaft aus (Über-Panel bleibt)", array_intersect($names($a), ['PurposeIntroPanel', 'NewsPanel', 'ForumHintPanel']) === [] && strpos(json_encode(json_decode($a->GetConfigurationForm(), true)['elements'], JSON_UNESCAPED_UNICODE), 'Über dieses Modul') !== false);
check("Bestätigen wird an die Geschwister-Instanz desselben Typs weitergegeben", array_intersect($names($b), ['PurposeIntroPanel', 'NewsPanel', 'ForumHintPanel']) === []);
check("… aber NICHT an ein anderes Modul (RLTHubGateway behält alle drei Panels)", count(array_intersect($names($c), ['PurposeIntroPanel', 'NewsPanel', 'ForumHintPanel'])) === 3);
$d = new RLTHub(923); $d->Create(); $GLOBALS['RLT_INSTANCES'][923] = ['module' => RLTHub::MODULE_GUID, 'props' => []];
$d->ApplyChanges();
check("neu angelegte Instanz übernimmt beim ersten ApplyChanges den Ausblenden-Stand einer Geschwister-Instanz", array_intersect($names($d), ['PurposeIntroPanel', 'NewsPanel', 'ForumHintPanel']) === []);
$e1 = new RLTHub(924); $e1->Create(); $GLOBALS['RLT_INSTANCES'][924] = ['module' => RLTHub::MODULE_GUID, 'props' => []];
$e1->attrs['SeenNews'] = '0.0.1';
$e1->ApplyChanges();
check("Ping-Pong strukturell ausgeschlossen: AdoptDismissState propagiert nicht weiter (kein Endlosaufruf, Stand konsistent)", $e1->ReadAttributeString('SeenNews') === RLTHub::NEWS_VERSION);
unset($GLOBALS['RLT_INSTANCES'][920], $GLOBALS['RLT_INSTANCES'][921], $GLOBALS['RLT_INSTANCES'][922], $GLOBALS['RLT_INSTANCES'][923], $GLOBALS['RLT_INSTANCES'][924]);

// Forum-Hinweis: ohne echte URL nur Text (keine erfundene Verknüpfung), mit URL echte Link-Schaltfläche
$forumEl = function ($m) { foreach (json_decode($m->GetConfigurationForm(), true)['elements'] as $e) { if (($e['name'] ?? '') === 'ForumHintPanel') { return $e; } } return null; };
class TestHubNoForum extends RLTHub { public const FORUM_THREAD_URL = ''; }
$h930 = new TestHubNoForum(930); $h930->Create();
$f0 = $forumEl($h930);
check("Forum-Hinweis ohne URL (leer): nur Text, keine Platzhalter-Verknüpfung", $f0 !== null && count(array_filter($f0['items'], function ($i) { return isset($i['link']); })) === 0);
$realUrl = 'https://community.symcon.de/t/modul-nrg-stack-rlthub-lueftungsanlagen-rlt-kwl-per-modbus-anbinden-robatherm-truecontrol-proxon-fwt/144442';
check("Forum-Thread-URL ist in allen drei Modulen eingetragen und identisch", RLTHub::FORUM_THREAD_URL === $realUrl && RLTHubGateway::FORUM_THREAD_URL === $realUrl && RLTHubDiscovery::FORUM_THREAD_URL === $realUrl);
foreach (['RLTHub' => new RLTHub(932), 'RLTHubGateway' => new RLTHubGateway(933), 'RLTHubDiscovery' => new RLTHubDiscovery(934)] as $nm => $mm) {
    $mm->Create();
    $fe = $forumEl($mm);
    check("$nm: Forum-Hinweis mit Schaltfläche 'Zum Forums-Thread' (onClick=echo <URL>, link=true)", $fe !== null && count(array_filter($fe['items'], function ($i) use ($realUrl) { return ($i['link'] ?? false) === true && $i['onClick'] === "echo '" . $realUrl . "';" && $i['caption'] === 'Zum Forums-Thread'; })) === 1);
}
class TestHubForum extends RLTHub { public const FORUM_THREAD_URL = 'https://community.symcon.de/t/beispiel/1'; }
$fm = new TestHubForum(931); $fm->Create();
$f1 = $forumEl($fm);
check("Forum-Hinweis mit Thread-URL: Schaltfläche 'onClick=echo <URL>' und link=true", $f1 !== null && count(array_filter($f1['items'], function ($i) { return ($i['link'] ?? false) === true && strpos($i['onClick'], "echo 'https://community.symcon.de/t/beispiel/1'") === 0; })) === 1);

// Cast-Sicherheit (Store-Review 9c): SDK liefert beim Reload false statt des Typs
class FalseReadHub extends RLTHub
{
    public function ReadPropertyString($n) { return false; }
    public function ReadPropertyInteger($n) { return false; }
    public function ReadPropertyBoolean($n) { return false; }
    public function ReadAttributeString($n) { return false; }
    public function ReadAttributeInteger($n) { return false; }
    public function ReadAttributeBoolean($n) { return false; }
}
$fr = new FalseReadHub(940); $fr->Create();
$thrown = null;
try { $fr->ApplyChanges(); $fr->GetConfigurationForm(); $fr->GetFunctions(); $fr->ReadValues(); } catch (\Throwable $t) { $thrown = get_class($t) . ': ' . $t->getMessage(); }
check("SDK-Rückgabe false (Instanz lädt neu) wirft keinen TypeError/Fehler", $thrown === null, (string)$thrown);
$GLOBALS['RLT_RUNLEVEL'] = 10100;
$hk = new TestHub(941); $hk->Create(); $hk->props['Host'] = '192.0.2.1'; $hk->ApplyChanges(); $hk->mb = new FakeModbus(); $hk->status = 102;
$hk->ReadValues();
check("Zyklus läuft nur bei Kernel-Runlevel KR_READY (kein Lesen während des Hochfahrens)", $hk->status === 102);
$GLOBALS['RLT_RUNLEVEL'] = KR_READY;

// Sichtbarkeit von Fehlern: dauerhaft loggen, aber nur beim Übergang
$GLOBALS['RLT_LOG'] = [];
$hl = new TestHub(942); $hl->Create(); $hl->props['Host'] = '192.0.2.1'; $hl->ApplyChanges(); $hl->mb = new FakeModbus();
$hl->ReadValues(); $hl->ReadValues(); $hl->ReadValues();
check("Ausfall wird einmalig im Symcon-Protokoll vermerkt (nicht bei jedem Takt)", count($GLOBALS['RLT_LOG']) === 1 && $GLOBALS['RLT_LOG'][0][0] === 'RLTHub' && strpos($GLOBALS['RLT_LOG'][0][1], '#942') !== false, json_encode($GLOBALS['RLT_LOG']));
foreach ([2000 => 235, 2018 => 210, 2048 => 220, 2060 => 420, 2736 => 1234, 2351 => 80, 2353 => 75, 1992 => 1] as $addr => $val) { $hl->mb->holding[$addr] = [$val]; }
$hl->mb->coils[5] = [0]; $hl->mb->coils[0] = [1];
$hl->ReadValues();
$hl->mb->holding = []; $hl->ReadValues();
check("erneuter Ausfall nach Erfolg wird wieder vermerkt", count($GLOBALS['RLT_LOG']) === 2 && $hl->status === 201);

// Rückmeldung je Aktion
check("Verbindungstest: sichtbarer Ergebnistext (✅/❌) für Erfolg und Fehler", strpos($hl->TestConnection(), '❌') === 0 && (function () use ($hl) { $hl->mb->holding = [2000 => [235], 2018 => [210], 2048 => [220], 2060 => [1], 2736 => [1], 2351 => [1], 2353 => [1], 1992 => [1]]; $hl->mb->coils[5] = [0]; $hl->mb->coils[0] = [1]; return strpos($hl->TestConnection(), '✅') === 0; })());
$tGw = new RLTHubGateway(943); $tGw->Create(); $tGw->ApplyChanges();
check("Verbindungstest ohne Gateway: verständliche Fehlermeldung statt Absturz", strpos($tGw->TestConnection(), '❌') === 0);

// Neuinstallations-Simulation
$ni = new RLTHub(950); $ni->Create();
check("Neuinstallation: Vorgaben generisch (kein Host, Standardport 502, Unit-ID 1, Adress-Basis automatisch, aktiv)", $ni->props['Host'] === '' && $ni->props['Port'] === 502 && $ni->props['UnitId'] === 1 && $ni->props['AddressBase'] === 'auto');
$niAll = json_encode(walkForm(json_decode($ni->GetConfigurationForm(), true)['elements']), JSON_UNESCAPED_UNICODE);
check("Neuinstallation: Hinweis, dass die Gerätetyp-Vorbelegung nur der erste Listeneintrag ist, und wann die Adresse von Hand nötig ist", strpos($niAll, 'nur der erste Eintrag der Liste') !== false && strpos($niAll, 'trägt RLTHubDiscovery automatisch ein') !== false);
$dscNew = new RLTHubDiscovery(951); $dscNew->Create();
check("Neuinstallation Suche: Suchbereich leer vorbelegt (wird aus dem eigenen Netz abgeleitet, nicht aus dem des Autors)", $dscNew->props['ScanStartIP'] === '' && $dscNew->props['ScanEndIP'] === '');

// Suche: Verbund-Status-Kopfzeile
$dm = new RLTHubDiscovery(960); $dm->Create();
$dmForm = json_decode($dm->GetConfigurationForm(), true);
$search = null; foreach (walkForm($dmForm['elements']) as $e) { if (($e['caption'] ?? '') === '🔎 Suchbereich') { $search = $e; } }
$idxBtn = null; $idxLine = null; $lineCap = ''; $detailCollapsed = false;
foreach ($search['items'] as $i => $e) {
    if (($e['type'] ?? '') === 'Button') { $idxBtn = $i; }
    if (($e['name'] ?? '') === 'ScanResult') { $idxLine = $i; $lineCap = $e['caption']; }
    if (($e['caption'] ?? '') === 'Details der letzten Suche') { $detailCollapsed = $e['expanded'] === false; }
}
check("Suche: Schaltfläche steht VOR der Statuszeile, technische Details in eingeklapptem Unter-Panel", $idxBtn !== null && $idxLine !== null && $idxBtn < $idxLine && $detailCollapsed);
check("Suche: vor der ersten Suche „ℹ️ Noch nicht gesucht“", strpos($lineCap, 'ℹ️ Noch nicht gesucht') === 0, $lineCap);
$good2 = startServer('map', ['3:2000' => 235, '3:2018' => 210, '3:2048' => 220]);
$dm->Discover('127.0.0.1', '127.0.0.1', $good2[1], 1);
$line1 = ''; foreach (array_reverse($dm->formUpdates) as $u) { if ($u[0] === 'ScanResult') { $line1 = $u[2]; break; } }
check("Suche: Kopfzeile im Muster „✅ N … gefunden (zuletzt HH:MM:SS Uhr).“", (bool)preg_match('/^✅ 1 Lüftungsanlage\(n\) gefunden \(zuletzt \d\d:\d\d:\d\d Uhr\)\.$/u', $line1), $line1);
$dm->attrs['LastScanTs'] = 1;
stopServer($good2);
$dm->Discover('127.0.0.1', '127.0.0.1', 1, 1);
$line2 = ''; foreach (array_reverse($dm->formUpdates) as $u) { if ($u[0] === 'ScanResult') { $line2 = $u[2]; break; } }
check("Suche: Zeitstempel wird bei JEDER Suche fortgeschrieben, auch bei 0 Funden (⚠️)", $dm->ReadAttributeInteger('LastScanTs') > 1 && strpos($line2, '⚠️ 0 Lüftungsanlage(n)') === 0, $line2);
check("Suche: Rückgabe des Knopfs nennt Kopfzeile UND Details (sichtbare Rückmeldung)", strpos($dm->Discover('127.0.0.1', '127.0.0.1', 1, 1), 'Adressen geprüft') !== false);

// README, Badges, CI
$readme = file_get_contents("$root/README.md");
$hasWorkflow = file_exists("$root/.github/workflows/check-style.yml");
check("README: Badge-Zeile (Symcon, Modul Version, Symcon Version, License, PayPal) direkt unter der Überschrift, Versionen stimmen", (bool)preg_match('/^# NRG-Stack RLTHub\n\n!\[Symcon\]\(https:\/\/img\.shields\.io\/badge\/Symcon-PHPModul-blue\)\n!\[Modul Version\]\([^)]*' . preg_quote(str_replace('-', '--', $lib['version']), '/') . '[^)]*\)\n!\[Symcon Version\]\([^)]*9\.0%2B[^)]*\)\n!\[License\]\([^)]*PolyForm_Noncommercial_1\.0\.0[^)]*\)\n/u', $readme));
check("README: Check-Style-Badge nur, wenn der Workflow wirklich existiert (nie ein gefälschtes „passing“)", $hasWorkflow === (strpos($readme, 'actions/workflows/check-style.yml') !== false));
check("README: PayPal-Badge und Verweis „Teil des NRG-Stack“", strpos($readme, 'paypal.me/DietmarGureth') !== false && strpos($readme, '**Teil des NRG-Stack**') !== false);
check("CHANGELOG nennt die aktuelle Version", strpos(file_get_contents("$root/CHANGELOG.md"), '## ' . $lib['version']) !== false);

echo "10) Verbund-Verbindungen im Formular sichtbar machen (SUITE.md 21.09.2026): live berechnete Statuszeile\n";
$statusEl = function ($m) {
    foreach (walkForm(json_decode($m->GetConfigurationForm(), true)['elements']) as $e) { if (($e['name'] ?? '') === 'ConnectionStatusLine') { return $e['caption']; } }
    return null;
};
$stat = new TestHub(970); $stat->Create();
$stat->props['Active'] = false;
check("RLTHub: Kommunikation aus -> ℹ️-Zeile im ausgelieferten Formular (rekursiv im Panel gefunden)", strpos((string)$statusEl($stat), 'ℹ️ Kommunikation ist ausgeschaltet') === 0, (string)$statusEl($stat));
$stat->props['Active'] = true;
check("RLTHub: keine Adresse -> ℹ️ „Noch keine IP-Adresse“, sagt was dann gilt (es wird nichts gelesen)", strpos((string)$statusEl($stat), 'ℹ️ Noch keine IP-Adresse eingetragen — es wird nichts gelesen') === 0, (string)$statusEl($stat));
$stat->props['Host'] = '192.0.2.10'; $stat->ApplyChanges();
check("RLTHub: Adresse, aber noch keine Antwort -> ⚠️ mit Ziel und Hinweis auf den Verbindungstest", (bool)preg_match('/^⚠️ Verbunden mit 192\.0\.2\.10:502 \(Unit-ID 1\), aber noch keine gültige Antwort/u', (string)$statusEl($stat)), (string)$statusEl($stat));
$stat->mb = new FakeModbus();
foreach ([2000 => 235, 2018 => 210, 2048 => 220, 2060 => 420, 2736 => 1234, 2351 => 80, 2353 => 75, 1992 => 1] as $a => $v) { $stat->mb->holding[$a] = [$v]; }
$stat->mb->coils[5] = [1]; $stat->mb->coils[0] = [0];
$stat->ReadValues();
$ok = (string)$statusEl($stat);
check("RLTHub: erfolgreiche Antwort -> ✅ mit Ziel, Zeitstempel TT.MM.JJJJ und den übernommenen Werten samt Quelle", (bool)preg_match('/^✅ Verbunden mit 192\.0\.2\.10:502 \(Unit-ID 1\) — letzte gültige Antwort \d\d\.\d\d\.\d{4} \d\d:\d\d:\d\d Uhr\. Zuletzt gelesen \(Quelle: die Anlage\): Außenluft 23,5 °C, Zuluft 21,0 °C, Abluft 22,0 °C, Störung: ja\.$/u', $ok), $ok);
check("RLTHub: der statische Ersatzsatz ist weg (kein „wird automatisch erkannt“/„sobald installiert“ im Verbindungs-Panel)", strpos(json_encode(json_decode($stat->GetConfigurationForm(), true)['elements'], JSON_UNESCAPED_UNICODE), 'sobald installiert') === false);
$stat->mb = new FakeModbus(); $stat->ReadValues();
check("RLTHub: danach Ausfall -> ⚠️ „letzte Abfrage fehlgeschlagen“ mit letzter gültiger Antwort", (bool)preg_match('/^⚠️ Verbunden mit 192\.0\.2\.10:502.*die letzte Abfrage ist aber fehlgeschlagen \(letzte gültige Antwort \d\d\.\d\d\.\d{4}/u', (string)$statusEl($stat)), (string)$statusEl($stat));
$stat->formUpdates = [];
$stat->TestConnection();
$upd = null; foreach ($stat->formUpdates as $u) { if ($u[0] === 'ConnectionStatusLine') { $upd = $u; } }
check("Verbindungstest frischt die Statuszeile im offenen Formular auf (UpdateFormField auf ConnectionStatusLine)", $upd !== null && $upd[1] === 'caption' && strpos($upd[2], '⚠️') === 0, json_encode($upd));

$gw2 = new TestGw(971); $gw2->Create(); $gw2->ApplyChanges();
check("Gateway: kein ModBus-Gateway -> ℹ️ „Kein ModBus-Gateway verbunden — es wird nichts gelesen“", strpos((string)$statusEl($gw2), 'ℹ️ Kein ModBus-Gateway verbunden — es wird nichts gelesen') === 0, (string)$statusEl($gw2));
$GLOBALS['RLT_INSTANCES'][971] = ['ConnectionID' => 972];
$GLOBALS['RLT_INSTANCES'][972] = ['module' => '{A5F663AB-C400-4FE5-B207-4D67CC030564}', 'props' => ['DeviceID' => 41]];
$gw2->ApplyChanges();
check("Gateway: mit Gateway, noch keine Antwort -> ⚠️ mit Gateway-Name, Instanz-ID und Geräte-ID", strpos((string)$statusEl($gw2), '⚠️ Verbunden mit ModBus-Gateway „Instanz 972" (#972, Geräte-ID 41), aber noch keine gültige Antwort') === 0, (string)$statusEl($gw2));
$gw2->parentFn = function (string $json) { $r = json_decode($json, true); $v = [198 => 1420, 195 => 1825, 196 => 2420, 47 => 0, 469 => 1069, 16 => 1]; return isset($v[$r['Address']]) ? chr($r['Function']) . "\x02" . pack('n', $v[$r['Address']]) : false; };
$gw2->ReadValues();
$gok = (string)$statusEl($gw2);
check("Gateway: erfolgreiche Antwort -> ✅ mit Gateway, Werten (14,2 / 18,3 = 18,25 gerundet / 24,2 °C, keine Störung) und Quelle", (bool)preg_match('/^✅ Verbunden mit ModBus-Gateway „Instanz 972" \(#972, Geräte-ID 41\) — letzte gültige Antwort .* Außenluft 14,2 °C, Zuluft 18,3 °C, Abluft 24,2 °C, Störung: nein\.$/u', $gok), $gok);
unset($GLOBALS['RLT_INSTANCES'][971], $GLOBALS['RLT_INSTANCES'][972]);

// Suche: Gateway-Übersicht mit Zustandssymbolen
$dsum = new RLTHubDiscovery(973); $dsum->Create();
$sumEl = function ($m) { foreach (walkForm(json_decode($m->GetConfigurationForm(), true)['elements']) as $e) { if (strpos($e['caption'] ?? '', 'ModBus-Gateway') !== false && strpos($e['caption'] ?? '', 'RLTHubGateway') !== false && ($e['type'] ?? '') === 'Label' && (strpos($e['caption'], 'ℹ️') === 0 || strpos($e['caption'], '✅') === 0 || strpos($e['caption'], '⚠️') === 0)) { return $e['caption']; } } return null; };
check("Suche: weder Gateway noch RLTHubGateway -> ℹ️ mit Folge (RS485 nicht einbindbar)", strpos((string)$sumEl($dsum), 'ℹ️ Keine ModBus-Gateways und keine RLTHubGateway-Instanzen gefunden') === 0, (string)$sumEl($dsum));
$GLOBALS['RLT_INSTANCES'][974] = ['module' => '{A5F663AB-C400-4FE5-B207-4D67CC030564}', 'props' => ['DeviceID' => 41]];
$GLOBALS['RLT_INSTANCES'][975] = ['module' => RLTHubGateway::MODULE_GUID, 'props' => [], 'ConnectionID' => 974];
check("Suche: Gateway und verbundene RLTHubGateway -> ✅ mit Zahlen", strpos((string)$sumEl($dsum), '✅ 1 ModBus-Gateway(s) und 1 RLTHubGateway-Instanz(en) gefunden') === 0, (string)$sumEl($dsum));
$GLOBALS['RLT_INSTANCES'][975]['ConnectionID'] = 0;
check("Suche: RLTHubGateway ohne Gateway -> ⚠️ mit Auswahlhinweis", strpos((string)$sumEl($dsum), '⚠️ 1 RLTHubGateway-Instanz(en) ohne ModBus-Gateway') === 0, (string)$sumEl($dsum));
unset($GLOBALS['RLT_INSTANCES'][974], $GLOBALS['RLT_INSTANCES'][975]);

echo "11) Wert kommt automatisch: Eingabefeld ersetzen (SUITE.md 21.09.2026)\n";
$find = function (array $els, callable $pred) { foreach (walkForm($els) as $e) { if ($pred($e)) { return $e; } } return null; };
$topLevelIn = function (array $panel, string $name) { foreach ($panel['items'] as $i) { if (($i['name'] ?? '') === $name) { return true; } } return false; };
$verbindung = function ($m) { foreach (json_decode($m->GetConfigurationForm(), true)['elements'] as $e) { if (strpos($e['caption'] ?? '', '🔌 Verbindung') === 0) { return $e; } } return null; };
$overridePanel = function (array $panel) { foreach ($panel['items'] as $i) { if (($i['type'] ?? '') === 'ExpansionPanel' && strpos($i['caption'], 'Eigene Adress-Basis stattdessen verwenden') === 0) { return $i; } } return null; };

// Adress-Basis: Robatherm (Vorgabe: 1-basiert) und Proxon (Vorgabe: Excel-Nr. = Wire)
$ab = new RLTHub(980); $ab->Create(); $ab->props['Host'] = '192.0.2.5';
$vp = $verbindung($ab);
$lineRob = $find($vp['items'], function ($e) { return ($e['name'] ?? '') === 'AddressBaseLine'; });
check("Adress-Basis 'automatisch' (Robatherm): 🔗-Zeile mit geltendem Wert und Quelle (Vorgabe des Gerätetyps)", strpos($lineRob['caption'], '🔗 Adress-Basis: Doku-Adresse − 1 = Wire-Adresse (automatisch, Vorgabe des Gerätetyps Robatherm TrueControl)') === 0, $lineRob['caption']);
$op = $overridePanel($vp);
check("Adress-Basis 'automatisch': Auswahlfeld NICHT direkt im Panel, sondern im eingeklappten Überschreib-Panel", $op !== null && $op['expanded'] === false && $topLevelIn($op, 'AddressBase') && !$topLevelIn($vp, 'AddressBase'));
$gwb = new TestGw(981); $gwb->Create();
$lineProx = $find($verbindung($gwb)['items'], function ($e) { return ($e['name'] ?? '') === 'AddressBaseLine'; });
check("Adress-Basis 'automatisch' (Proxon): 🔗-Zeile mit dem Wert dieses Gerätetyps (Excel-Nr. = Wire-Adresse)", strpos($lineProx['caption'], '🔗 Adress-Basis: Doku-Adresse = Wire-Adresse (automatisch, Vorgabe des Gerätetyps Proxon FWT 2.0 (Zimmermann))') === 0, $lineProx['caption']);
$ab->props['AddressBase'] = 'zero';
$vp2 = $verbindung($ab);
$line2 = $find($vp2['items'], function ($e) { return ($e['name'] ?? '') === 'AddressBaseLine'; });
check("Eigene Adress-Basis: ✏️-Zeile nennt die eigene Angabe und die Vorgabe, das Auswahlfeld bleibt sichtbar (kein Überschreib-Panel)", strpos($line2['caption'], '✏️ Adress-Basis: Doku-Adresse = Wire-Adresse (eigene Angabe; die Vorgabe des Gerätetyps wäre: Doku-Adresse − 1 = Wire-Adresse)') === 0 && $topLevelIn($vp2, 'AddressBase') && $overridePanel($vp2) === null, $line2['caption']);
$ab->formUpdates = [];
$ab->OnChangeDevice('proxon_fwt', 'auto');
$u = null; foreach ($ab->formUpdates as $f) { if ($f[0] === 'AddressBaseLine') { $u = $f; } }
check("Gerätetyp im offenen Formular gewechselt: die Adress-Basis-Zeile folgt der Auswahl (onChange + UpdateFormField)", $u !== null && strpos($u[2], 'Vorgabe des Gerätetyps Proxon FWT 2.0') !== false, json_encode($u));
$ab->formUpdates = [];
$ab->OnChangeAddressBase('robatherm_truecontrol', 'one');
$u = null; foreach ($ab->formUpdates as $f) { if ($f[0] === 'AddressBaseLine') { $u = $f; } }
check("Adress-Basis im offenen Formular geändert: die Zeile folgt (✏️ statt 🔗)", $u !== null && strpos($u[2], '✏️ Adress-Basis: Doku-Adresse − 1 = Wire-Adresse') === 0, json_encode($u));
$noValueWrites = true; $files = ['libs/RLTCore.php', 'libs/RLTPanels.php', 'RLTHub/module.php', 'RLTHubGateway/module.php', 'RLTHubDiscovery/module.php'];
foreach ($files as $f) { if (preg_match("/UpdateFormField\([^)]*,\s*'value'\s*,/", file_get_contents(dirname(__DIR__) . '/' . $f))) { $noValueWrites = false; } }
check("Nie den automatischen Wert per UpdateFormField('…', 'value', …) ins Eingabefeld schreiben (Quelltext-Prüfung)", $noValueWrites);

// Gateway: Slave-ID kommt vom ModBus-Gateway, kein Eingabefeld
$sg = new TestGw(982); $sg->Create(); $sg->ApplyChanges();
$slaveNone = $find($verbindung($sg)['items'], function ($e) { return ($e['name'] ?? '') === 'SlaveIdLine'; });
check("Gateway ohne ModBus-Gateway: ℹ️ „Slave-ID wird am ModBus-Gateway eingestellt — noch kein Gateway verbunden“", strpos($slaveNone['caption'], 'ℹ️ Slave-ID: wird am ModBus-Gateway eingestellt (Geräte-ID) — noch kein Gateway verbunden') === 0, $slaveNone['caption']);
$GLOBALS['RLT_INSTANCES'][982] = ['ConnectionID' => 983];
$GLOBALS['RLT_INSTANCES'][983] = ['module' => '{A5F663AB-C400-4FE5-B207-4D67CC030564}', 'props' => ['DeviceID' => 41]];
$slaveOk = $find($verbindung($sg)['items'], function ($e) { return ($e['name'] ?? '') === 'SlaveIdLine'; });
check("Gateway mit ModBus-Gateway: 🔗 „Slave-ID (Geräte-ID): 41 (automatisch vom ModBus-Gateway …)“, kein Eingabefeld dafür", strpos($slaveOk['caption'], '🔗 Slave-ID (Geräte-ID): 41 (automatisch vom ModBus-Gateway „Instanz 983", dort einstellbar)') === 0 && $find(json_decode($sg->GetConfigurationForm(), true)['elements'], function ($e) { return in_array($e['name'] ?? '', ['UnitId', 'SlaveId'], true); }) === null, $slaveOk['caption']);
unset($GLOBALS['RLT_INSTANCES'][982], $GLOBALS['RLT_INSTANCES'][983]);

// Suche: Suchbereich
class DiscWithNet extends RLTHubDiscovery { protected function guessLocalSubnetPrefix(): string { return '192.0.2'; } }
class DiscNoNet extends RLTHubDiscovery { protected function guessLocalSubnetPrefix(): string { return ''; } }
$rangePanel = function ($m) { foreach (json_decode($m->GetConfigurationForm(), true)['elements'] as $e) { if (($e['caption'] ?? '') === '🔎 Suchbereich') { return $e; } } return null; };
$dn = new DiscWithNet(984); $dn->Create();
$rp = $rangePanel($dn);
$rline = $find($rp['items'], function ($e) { return ($e['name'] ?? '') === 'ScanRangeLine'; });
$rop = null; foreach ($rp['items'] as $i) { if (($i['type'] ?? '') === 'ExpansionPanel' && strpos($i['caption'], 'Eigenen Suchbereich stattdessen verwenden') === 0) { $rop = $i; } }
check("Suche, Netz erkennbar und nichts eingetragen: 🔗-Zeile mit Bereich und Quelle, Felder nur im eingeklappten Überschreib-Panel", $rline !== null && $rline['caption'] === '🔗 Suchbereich: 192.0.2.1 bis 192.0.2.254 (automatisch aus dem eigenen Netz).' && $rop !== null && $rop['expanded'] === false && $topLevelIn($rop, 'ScanStartIP') && $topLevelIn($rop, 'ScanEndIP') && !$topLevelIn($rp, 'ScanStartIP'), $rline['caption'] ?? 'null');
$dn->props['ScanStartIP'] = '10.9.8.1'; $dn->props['ScanEndIP'] = '10.9.8.20';
$rp2 = $rangePanel($dn);
$rline2 = $find($rp2['items'], function ($e) { return ($e['name'] ?? '') === 'ScanRangeLine'; });
check("Suche, eigener Bereich: ✏️-Zeile, Felder direkt sichtbar (kein Überschreib-Panel)", $rline2['caption'] === '✏️ Eigener Suchbereich: 10.9.8.1 bis 10.9.8.20.' && $topLevelIn($rp2, 'ScanStartIP') && $topLevelIn($rp2, 'ScanEndIP'), $rline2['caption']);
$dz = new DiscNoNet(985); $dz->Create();
$rp3 = $rangePanel($dz);
$rline3 = $find($rp3['items'], function ($e) { return ($e['name'] ?? '') === 'ScanRangeLine'; });
check("Suche, kein Netz erkennbar: ℹ️ „Start- und End-IP-Adresse werden gebraucht“, Felder sichtbar", $rline3['caption'] === 'ℹ️ Kein eigenes Netz erkannt — Start- und End-IP-Adresse werden gebraucht.' && $topLevelIn($rp3, 'ScanStartIP'), $rline3['caption']);
$dn->formUpdates = [];
$dn->OnChangeRange('', '');
$ur = null; foreach ($dn->formUpdates as $f) { if ($f[0] === 'ScanRangeLine') { $ur = $f; } }
check("Suchbereich im offenen Formular geleert: die Zeile folgt (wieder 🔗 automatisch)", $ur !== null && strpos($ur[2], '🔗 Suchbereich: 192.0.2.1 bis 192.0.2.254') === 0, json_encode($ur));
check("Der Knopf „Netzwerk durchsuchen“ übergibt die Feldwerte weiterhin (Felder stehen im Formular, auch im Überschreib-Panel)", strpos($dn->GetConfigurationForm(), 'RLTD_Discover($id, $ScanStartIP, $ScanEndIP, $ScanPort, $ScanUnitId)') !== false);

echo "8) Treiberliste und Modulkennungen\n";
$guids = [];
foreach (['RLTHub', 'RLTHubGateway', 'RLTHubDiscovery'] as $mod) {
    $j = json_decode(file_get_contents(dirname(__DIR__) . "/$mod/module.json"), true);
    $guids[] = $j['id'];
    check("$mod: Klassenname = module.json-Name", class_exists($j['name']) && $j['name'] === $mod);
}
check('Modul-GUIDs eindeutig und verschieden von der Bibliothek', count(array_unique($guids)) === 3 && !in_array(json_decode(file_get_contents(dirname(__DIR__) . '/library.json'), true)['id'], $guids, true));
check('jeder Treiber hat Klasse, Transport und Hinweis', (function () {
    foreach (RLT_Drivers::DRIVERS as $k => $d) {
        if (!class_exists($d['class']) || empty($d['transports']) || $d['confidence'] === '' || !(RLT_Drivers::create($k) instanceof RLT_VentilationDriverInterface)) { return false; }
    }
    return true;
})());

echo "\n" . ($fails === 0 ? "ALLE PRÜFUNGEN BESTANDEN\n" : "$fails PRÜFUNG(EN) FEHLGESCHLAGEN\n");
exit($fails === 0 ? 0 : 1);
