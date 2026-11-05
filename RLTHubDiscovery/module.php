<?php

require_once __DIR__ . '/../libs/RLTCore.php';

// NRG-Stack RLTHubDiscovery — sucht Lüftungsanlagen im Netzwerk und legt
// RLTHub-Instanzen an.
//
// Modbus TCP: durchsucht einen IP-Bereich nach offenem Port (parallel, nicht
// blockierend) und fragt bei jedem Treffer die Register jedes TCP-Treibers
// rein lesend ab. Ein Fund heißt „an den Registern dieses Treibers stehen
// plausible Temperaturen" — mehr nicht: RLT-Steuerungen wie TrueControl
// haben kein festes Erkennungsmerkmal, die Register werden je Projekt
// vergeben. Jeder Fund ist deshalb ein Vorschlag, den der Nutzer prüft.
//
// Modbus RTU (RS485): nicht per Netzwerksuche auffindbar. Hier werden nur die
// vorhandenen ModBus-Gateways und RLTHubGateway-Instanzen aufgelistet;
// RLTHubGateway wird manuell angelegt und mit dem Gateway verbunden (eine
// Configurator-Zeile kann laut Modul-Doku keine Verbindung zu einem
// bestehenden Parent herstellen, daher bewusst kein Anlegen-Knopf).
class RLTHubDiscovery extends IPSModule
{
    use RLT_PanelTrait;

    public const PREFIX = 'RLTD';
    public const MODULE_NAME = 'RLTHubDiscovery';
    public const MODULE_GUID = '{CC2C38CA-7675-4F27-B993-7B68187B8879}';

    public const NEWS_VERSION = '0.5.0';
    public const FORUM_THREAD_URL = 'https://community.symcon.de/t/modul-nrg-stack-rlthub-lueftungsanlagen-rlt-kwl-per-modbus-anbinden-robatherm-truecontrol-proxon-fwt/144442';
    public const LICENSE_URL = 'https://github.com/DG65/NRGRLTHub/blob/beta/LICENSE';
    public const PURPOSE = [
        'Searches your local network for ventilation systems with Modbus TCP and creates a prefilled RLTHub instance on click — IP address, port, unit ID and device type do not have to be entered by hand.',
        'The benefit: with several systems, a few clicks replace configuring each instance by hand. A find only means that plausible temperatures are present at the registers of the respective device type — please check every suggestion.',
        'The module itself measures nothing — the actual data collection is done afterwards by the newly created RLTHub instance. Devices with RS485/Modbus RTU cannot be found on the network; RLTHubGateway exists for those.',
    ];
    public const NEWS = [
        '• 🌐 New: bilingual (German/English). The form, messages and notes now follow the Symcon language setting. Identifiers and settings are unchanged.',
        '• 🪟 New: choose the form layout yourself — in the panel "Form: layout", decide for each section (search range, results list, RS485) whether it is expanded, collapsed or hidden when the form opens, and where it appears. Hidden sections keep working.',
        '• 🆕 The search now also detects Pichler LG systems (ES2020 controller) with Modbus TCP and names the type (LG 350, 450 or 740). As before, a find is only a suggestion — please check it.',
        '• ⚠️ Not yet checked on any Pichler system.',
    ];

    private const HUB_GUID            = '{19C33A5B-8C10-45F5-9A33-9928D9005B69}';
    private const GATEWAY_HUB_GUID    = '{1AE15A54-8ECC-4599-961B-447BD8671715}';
    private const MODBUS_GATEWAY_GUID = '{A5F663AB-C400-4FE5-B207-4D67CC030564}';
    private const MAX_HOSTS           = 1024;

    public function __construct($InstanceID)
    {
        parent::__construct($InstanceID);
        RLT_Text::setTranslator(RLT_Text::withMemo(function (string $text): string {
            return $this->Translate($text);
        }));
    }

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('ScanStartIP', '');
        $this->RegisterPropertyString('ScanEndIP', '');
        $this->RegisterPropertyInteger('ScanPort', 502);
        $this->RegisterPropertyInteger('ScanUnitId', 1);
        $this->RegisterPropertyString('FormLayout', '[]');
        $this->RegisterAttributeString('ResultsJSON', '[]');
        // Status-Kopfzeile (SUITE.md „Einheitliche Verbund-Status-Kopfzeile").
        $this->RegisterAttributeInteger('LastScanTs', 0);
        $this->RegisterAttributeString('ScanDetails', '');
        $this->rltPanelCreate();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->rltAdoptDismissFromSibling();
    }

    // Eine Zeile: Symbol, Kernzahl, Zeitstempel der letzten Suche.
    private function ScanSummaryLine(): string
    {
        $ts = (int)$this->ReadAttributeInteger('LastScanTs');
        if ($ts === 0) {
            return RLT_Text::t('ℹ️ Not searched yet — press the button above.');
        }
        $rows = json_decode((string)$this->ReadAttributeString('ResultsJSON'), true);
        $count = is_array($rows) ? count($rows) : 0;
        return RLT_Text::t('%s %d ventilation system(s) found (last search at %s).', $count > 0 ? '✅' : '⚠️', $count, RLT_Text::clock($ts));
    }

    public function GetConfigurationForm()
    {
        $prefix = $this->guessLocalSubnetPrefix();

        $rows = json_decode((string)$this->ReadAttributeString('ResultsJSON'), true);
        $values = $this->buildValues(is_array($rows) ? $rows : []);

        $libraryInfo = @json_decode((string)@file_get_contents(__DIR__ . '/../library.json'), true);
        $version = (is_array($libraryInfo) && isset($libraryInfo['version'])) ? (string)$libraryInfo['version'] : '?';

        $doku = [
            'type'     => 'ExpansionPanel',
            'caption'  => RLT_Text::t('📖 Documentation & help'),
            'expanded' => false,
            'items'    => [
                ['type' => 'Label', 'caption' => RLT_Text::t('%s version %s — not yet verified on any real system.', static::MODULE_NAME, $version)],
                ['type' => 'Label', 'caption' => RLT_Text::t('Procedure: check the search range, click "Search network", select the desired system in the results list and click "Create". The new RLTHub instance is prefilled; you only need to check it and activate it.')],
                ['type' => 'Label', 'caption' => RLT_Text::t('The search checks which addresses have the Modbus port open and reads the registers of every supported device type there, read-only. A find means: plausible temperatures are present at these registers. Controllers such as Robatherm TrueControl have no reliable identifying characteristic — please check every suggestion.')],
                ['type' => 'Label', 'caption' => RLT_Text::t('A device that returns 0 everywhere, or one that responds to the registers with a Modbus error, does not count as a find.')],
            ],
        ];

        $free = [
            'suche' => [
                'type'     => 'ExpansionPanel',
                'caption'  => RLT_Text::t('🔎 Search range'),
                'expanded' => true,
                'items'    => array_merge($this->rangeItems($prefix), [
                    ['type' => 'NumberSpinner', 'name' => 'ScanPort', 'caption' => RLT_Text::t('TCP port'), 'minimum' => 1, 'maximum' => 65535],
                    ['type' => 'NumberSpinner', 'name' => 'ScanUnitId', 'caption' => RLT_Text::t('Unit ID'), 'minimum' => 1, 'maximum' => 247],
                    // Schaltfläche zuerst, direkt darunter die eine Kopfzeile (SUITE.md).
                    [
                        'type'    => 'Button',
                        'caption' => RLT_Text::t('🔎  Search network'),
                        'onClick' => 'echo RLTD_Discover($id, $ScanStartIP, $ScanEndIP, $ScanPort, $ScanUnitId);',
                    ],
                    ['type' => 'Label', 'name' => 'ScanResult', 'caption' => $this->ScanSummaryLine()],
                    [
                        'type'     => 'ExpansionPanel',
                        'caption'  => RLT_Text::t('Details of the last search'),
                        'expanded' => false,
                        'items'    => [
                            ['type' => 'Label', 'name' => 'ScanDetails', 'caption' => (string)$this->ReadAttributeString('ScanDetails') !== '' ? (string)$this->ReadAttributeString('ScanDetails') : RLT_Text::t('Not searched yet.')],
                        ],
                    ],
                ]),
            ],
            'fundliste' => [
                'type'     => 'ExpansionPanel',
                'caption'  => RLT_Text::t('📋 Results list'),
                'expanded' => true,
                'items'    => [
                    [
                        'type'     => 'Configurator',
                        'name'     => 'Configurator',
                        'caption'  => RLT_Text::t('Ventilation systems found'),
                        'rowCount' => 6,
                        'delete'   => false,
                        'sort'     => ['column' => 'address', 'direction' => 'ascending'],
                        'columns'  => [
                            ['caption' => RLT_Text::t('Address'), 'name' => 'address', 'width' => '150px'],
                            ['caption' => RLT_Text::t('Device type'), 'name' => 'device', 'width' => '230px'],
                            ['caption' => RLT_Text::t('Result'), 'name' => 'detail', 'width' => 'auto'],
                        ],
                        'values'   => $values,
                    ],
                ],
            ],
            'rs485' => [
                'type'     => 'ExpansionPanel',
                'caption'  => RLT_Text::t('🔌 RS485 / Modbus RTU (e.g. Proxon FWT)'),
                'expanded' => false,
                'items'    => [
                    ['type' => 'Label', 'caption' => RLT_Text::t('RTU devices cannot be found on the network. To set one up: create an "RLTHubGateway" instance and select the ModBus gateway under "Gateway" at the top there (Serial Port → ModBus Gateway, mode RTU).')],
                    ['type' => 'Label', 'caption' => $this->gatewaySummary()],
                ],
            ],
        ];

        // Oben und unten stehen die Panels der Verbund-Konvention fest; dazwischen liegen die Fachpanels,
        // deren Reihenfolge und Aufklapp-Zustand der Nutzer im Panel „Formular: Aufbau“ wählt (SUITE.md 3a).
        $layout = $this->formLayout(array_keys($free));
        $middle = [];
        foreach ($layout as $key => $l) {
            $panel = $free[$key];
            if ($l['open'] === 'open') {
                $panel['expanded'] = true;
            } elseif ($l['open'] === 'closed') {
                $panel['expanded'] = false;
            } elseif ($l['open'] === 'hidden') {
                // Nicht weglassen: Felder und Fundliste bleiben im Formular, gespeicherte Werte bleiben erhalten.
                $panel['visible'] = false;
            }
            $middle[] = $panel;
        }

        $form = [
            'elements' => array_merge($this->rltPanelsTop(), [$doku], $middle, [$this->layoutPanel($layout, $free)], $this->rltPanelsBottom()),
            'actions'  => [],
        ];
        return json_encode($form);
    }

    private const LAYOUT_KEYS = ['suche', 'fundliste', 'rs485'];

    /**
     * Reihenfolge und Aufklapp-Zustand der Fachpanels aus der Einstellung „FormLayout“.
     * Unbekannte oder fehlende Einträge fallen auf Standardreihenfolge und „Standard“ zurück.
     *
     * @param string[] $keys Schlüssel der Fachpanels in Standardreihenfolge
     * @return array key => ['open' => 'std'|'open'|'closed'|'hidden', 'pos' => int, 'idx' => int], in anzuwendender Reihenfolge
     */
    private function formLayout(array $keys): array
    {
        $saved = json_decode((string)$this->ReadPropertyString('FormLayout'), true);
        $by = [];
        foreach (is_array($saved) ? $saved : [] as $row) {
            if (is_array($row) && isset($row['Key'])) {
                $by[(string)$row['Key']] = $row;
            }
        }
        $out = [];
        foreach ($keys as $i => $k) {
            $r = $by[$k] ?? [];
            // Vorrang bei Widerspruch: ausgeblendet vor zugeklappt vor aufgeklappt, kein Haken = Standard
            $open = !empty($r['Hidden']) ? 'hidden' : (!empty($r['Closed']) ? 'closed' : (!empty($r['Opened']) ? 'open' : 'std'));
            $out[$k] = [
                'open' => $open,
                'pos'  => isset($r['Pos']) ? max(1, min(99, (int)$r['Pos'])) : $i + 1,
                'idx'  => $i,
            ];
        }
        uasort($out, function ($a, $b) { return [$a['pos'], $a['idx']] <=> [$b['pos'], $b['idx']]; });
        return $out;
    }

    /** Wählbarer Aufbau des Formulars: je Fachpanel Aufklapp-Zustand beim Öffnen und Position. */
    private function layoutPanel(array $layout, array $free): array
    {
        $rows   = [];
        $hidden = [];
        foreach ($layout as $key => $l) {
            $name = trim((string)preg_replace('/\s*\(.*$/u', '', (string)($free[$key]['caption'] ?? $key)));
            $rows[] = ['Key' => $key, 'Panel' => $name, 'Opened' => $l['open'] === 'open', 'Closed' => $l['open'] === 'closed',
                'Hidden' => $l['open'] === 'hidden', 'Pos' => $l['pos']];
            if ($l['open'] === 'hidden') {
                $hidden[] = trim((string)preg_replace('/^[^\p{L}\p{N}]+/u', '', $name));
            }
        }
        $hiddenLine = $hidden
            ? [['type' => 'Label', 'caption' => RLT_Text::t('🙈 Hidden: %s — to show again, clear the "Hidden" checkbox. Hidden sections keep working, only the display is missing.', implode(', ', $hidden))]]
            : [];
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => $hidden ? RLT_Text::t('🪟  Form: layout (%d hidden)', count($hidden)) : RLT_Text::t('🪟  Form: layout'),
            'items' => array_merge($hiddenLine, [
                ['type' => 'Label', 'caption' => RLT_Text::t('Here you decide which sections are expanded when the form opens and in which order they appear. Symcon does not remember which sections you expand or collapse, so the setting here applies every time the form opens. It takes effect the next time the form is opened after you click "Apply".')],
                ['type' => 'Label', 'caption' => RLT_Text::t('If no checkbox is set, the default applies, as intended by the module. The position is a number; with equal numbers the default order applies. "Hidden" removes a section from the display without losing anything: its settings are kept and keep working. To show it again, clear the "Hidden" checkbox or click "Reset to default". If several checkboxes are set, "Hidden" wins over "Collapsed", which wins over "Expanded". The sections at the top (purpose, news, documentation) and at the bottom (feedback, about this module) stay where they are and cannot be hidden.')],
                [
                    'type' => 'List', 'name' => 'FormLayout', 'caption' => RLT_Text::t('Sections'), 'rowCount' => count($rows), 'add' => false, 'delete' => false,
                    'loadValuesFromConfiguration' => false,
                    'values' => $rows,
                    'columns' => [
                        ['caption' => RLT_Text::t('Section'), 'name' => 'Panel', 'width' => 'auto', 'add' => ''],
                        ['caption' => RLT_Text::t('Key'), 'name' => 'Key', 'width' => '0px', 'visible' => false, 'add' => '', 'save' => true],
                        ['caption' => RLT_Text::t('Expanded'), 'name' => 'Opened', 'width' => '110px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => RLT_Text::t('Collapsed'), 'name' => 'Closed', 'width' => '110px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => RLT_Text::t('🙈 Hidden'), 'name' => 'Hidden', 'width' => '130px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => RLT_Text::t('Position'), 'name' => 'Pos', 'width' => '100px', 'add' => 1, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 1, 'maximum' => 99]],
                    ],
                ],
                ['type' => 'Button', 'caption' => RLT_Text::t('↩️ Reset to default'), 'onClick' => 'echo ' . static::PREFIX . '_ResetFormLayout($id);'],
            ]),
        ];
    }

    /** Setzt Aufklapp-Zustand und Reihenfolge der Fachpanels im offenen Formular auf den Standard (gilt nach „Übernehmen“). */
    public function ResetFormLayout(): string
    {
        $captions = ['suche' => RLT_Text::t('🔎 Search range'), 'fundliste' => RLT_Text::t('📋 Results list'), 'rs485' => 'RS485 / Modbus RTU'];
        $rows = [];
        foreach (self::LAYOUT_KEYS as $i => $k) {
            $rows[] = ['Key' => $k, 'Panel' => $captions[$k], 'Opened' => false, 'Closed' => false, 'Hidden' => false, 'Pos' => $i + 1];
        }
        $this->UpdateFormField('FormLayout', 'values', json_encode($rows, JSON_UNESCAPED_UNICODE));
        return RLT_Text::t('ℹ️ Reset to default. Click "Apply" at the bottom to save.');
    }

    // Suchbereich nach der Verbund-Konvention „Wert kommt automatisch: Eingabefeld
    // ersetzen“ (SUITE.md, 21.09.2026): Ist nichts eingetragen und ein eigenes Netz
    // erkennbar, zeigt das Formular den geltenden Bereich als schreibgeschützte Zeile
    // (🔗); die Felder stecken in einem eingeklappten Panel zum bewussten Überschreiben.
    // Eigene Angabe bleibt sichtbar (✏️), ohne erkennbares Netz werden die Felder gebraucht (ℹ️).
    // Der automatische Wert wird nie per UpdateFormField('value') ins Feld geschrieben.
    private function rangeLine(string $prefix, string $start, string $end): string
    {
        $start = trim($start);
        $end = trim($end);
        if ($start !== '' || $end !== '') {
            return RLT_Text::t('✏️ Custom search range: %s to %s.', $start !== '' ? $start : '?', $end !== '' ? $end : '?');
        }
        if ($prefix !== '') {
            return RLT_Text::t('🔗 Search range: %s.1 to %s.254 (automatically from your own network).', $prefix, $prefix);
        }
        return RLT_Text::t('ℹ️ No own network detected — start and end IP address are needed.');
    }

    private function rangeItems(string $prefix): array
    {
        $start = (string)$this->ReadPropertyString('ScanStartIP');
        $end = (string)$this->ReadPropertyString('ScanEndIP');
        $fields = [
            ['type' => 'ValidationTextBox', 'name' => 'ScanStartIP', 'caption' => RLT_Text::t('Start IP address'), 'validate' => '^$|^\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}$', 'onChange' => 'RLTD_OnChangeRange($id, $ScanStartIP, $ScanEndIP);'],
            ['type' => 'ValidationTextBox', 'name' => 'ScanEndIP', 'caption' => RLT_Text::t('End IP address'), 'validate' => '^$|^\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}$', 'onChange' => 'RLTD_OnChangeRange($id, $ScanStartIP, $ScanEndIP);'],
        ];
        $line = RLT_Ui::line('ScanRangeLine', $this->rangeLine($prefix, $start, $end));
        if (trim($start) === '' && trim($end) === '' && $prefix !== '') {
            return [$line, ['type' => 'ExpansionPanel', 'caption' => RLT_Text::t('Use a custom search range instead'), 'expanded' => false, 'items' => $fields]];
        }
        return array_merge([$line], $fields);
    }

    public function OnChangeRange(string $start, string $end): void
    {
        $caption = $this->rangeLine($this->guessLocalSubnetPrefix(), $start, $end);
        $this->UpdateFormField('ScanRangeLine', 'caption', $caption);
        $this->UpdateFormField('ScanRangeLine', 'color', RLT_Ui::color($caption));
    }

    public function Discover(string $StartIP, string $EndIP, int $Port, int $UnitId): string
    {
        $StartIP = trim($StartIP);
        $EndIP   = trim($EndIP);
        $note    = '';
        if ($StartIP === '' || $EndIP === '') {
            $prefix = $this->guessLocalSubnetPrefix();
            if ($prefix === '') {
                return RLT_Text::t('❌ Enter a start and end IP address (your own network could not be determined).');
            }
            $StartIP = $prefix . '.1';
            $EndIP   = $prefix . '.254';
            $note    = RLT_Text::t(' (range derived from your own network: %s – %s)', $StartIP, $EndIP);
        }
        $ips = $this->expandRange($StartIP, $EndIP);
        if (count($ips) === 0) {
            return RLT_Text::t('❌ Invalid IP range.');
        }
        if (count($ips) > self::MAX_HOSTS) {
            $ips = array_slice($ips, 0, self::MAX_HOSTS);
            $note .= RLT_Text::t(' — limited to %d addresses', self::MAX_HOSTS);
        }

        $open = $this->scanPortOpen($ips, $Port, 4.0);
        $rows = [];
        foreach ($open as $host) {
            foreach (RLT_Drivers::DRIVERS as $key => $def) {
                if (!in_array('tcp', $def['transports'], true)) {
                    continue;
                }
                $mb = new RLT_ModbusTcpClient($host, $Port, $UnitId);
                $found = RLT_Drivers::create($key)->probe($mb);
                $mb->close();
                if ($found !== null) {
                    $rows[] = ['host' => $host, 'port' => $Port, 'unit' => $UnitId, 'device' => $key, 'detail' => $found];
                }
            }
        }
        $details = RLT_Text::t('%d addresses checked, %d with open port %d, %d ventilation system(s) detected%s.', count($ips), count($open), $Port, count($rows), $note);
        return $this->finish($details, $rows);
    }

    // Zeitstempel bei JEDER Suche fortschreiben (auch bei 0 Funden), Kopfzeile
    // und Fundliste gemeinsam auffrischen — erst speichern, dann anzeigen.
    private function finish(string $details, array $rows): string
    {
        $this->WriteAttributeString('ResultsJSON', json_encode($rows));
        $this->WriteAttributeString('ScanDetails', $details);
        $this->WriteAttributeInteger('LastScanTs', time());
        $summary = $this->ScanSummaryLine();
        $this->UpdateFormField('ScanResult', 'caption', $summary);
        $this->UpdateFormField('ScanDetails', 'caption', $details);
        $this->UpdateFormField('Configurator', 'values', json_encode($this->buildValues($rows)));
        return $summary . "\n" . $details;
    }

    private function buildValues(array $rows): array
    {
        $values = [];
        foreach ($rows as $r) {
            $caption = RLT_Text::t(RLT_Drivers::DRIVERS[$r['device']]['caption'] ?? $r['device']);
            $row = [
                'address' => $r['host'],
                'device'  => $caption,
                'detail'  => $r['detail'],
                'create'  => [
                    'moduleID'      => self::HUB_GUID,
                    'configuration' => [
                        'Host'   => $r['host'],
                        'Port'   => (int)$r['port'],
                        'UnitId' => (int)$r['unit'],
                        'Device' => $r['device'],
                    ],
                    'name'          => $caption . ' (' . $r['host'] . ')',
                ],
            ];
            $existing = $this->findExistingInstance($r['host'], (int)$r['port'], (int)$r['unit']);
            if ($existing > 0) {
                $row['instanceID'] = $existing;
            }
            $values[] = $row;
        }
        return $values;
    }

    private function findExistingInstance(string $host, int $port, int $unit): int
    {
        foreach (IPS_GetInstanceListByModuleID(self::HUB_GUID) as $iid) {
            if (IPS_GetProperty($iid, 'Host') === $host
                && (int)IPS_GetProperty($iid, 'Port') === $port
                && (int)IPS_GetProperty($iid, 'UnitId') === $unit) {
                return $iid;
            }
        }
        return 0;
    }

    /**
     * Echte Live-Prüfung für RLTHub::rltConnectionItems() (SUITE.md „Verbund-
     * Verbindungen im Formular sichtbar machen"): war diese genaue Adresse Teil
     * des LETZTEN Suchergebnisses? Nur dann darf RLTHub sie als 🔗 automatisch
     * übernommen ausweisen — nichts wird geraten oder dauerhaft verknüpft, denn
     * nach dem Anlegen ist der Host-Wert eine normale Property wie jede andere.
     */
    public function GetLastMatch(string $Host, int $Port, int $UnitId): array
    {
        $rows = json_decode((string)$this->ReadAttributeString('ResultsJSON'), true);
        if (!is_array($rows)) {
            return ['found' => false];
        }
        foreach ($rows as $r) {
            if (($r['host'] ?? '') === $Host && (int)($r['port'] ?? 0) === $Port && (int)($r['unit'] ?? 0) === $UnitId) {
                return [
                    'found'  => true,
                    'device' => RLT_Text::t(RLT_Drivers::DRIVERS[$r['device']]['caption'] ?? (string)$r['device']),
                    'ts'     => (int)$this->ReadAttributeInteger('LastScanTs'),
                ];
            }
        }
        return ['found' => false];
    }

    private function gatewaySummary(): string
    {
        $gateways = IPS_GetInstanceListByModuleID(self::MODBUS_GATEWAY_GUID);
        $hubs = IPS_GetInstanceListByModuleID(self::GATEWAY_HUB_GUID);
        if (count($gateways) === 0 && count($hubs) === 0) {
            return RLT_Text::t('ℹ️ No ModBus gateways and no RLTHubGateway instances found — RS485 devices cannot be integrated without a ModBus gateway.');
        }
        $lines = [];
        $unconnected = 0;
        foreach ($gateways as $iid) {
            $lines[] = RLT_Text::t('• ModBus gateway "%s" (#%d, device ID %d)', IPS_GetName($iid), $iid, (int)@IPS_GetProperty($iid, 'DeviceID'));
        }
        foreach ($hubs as $iid) {
            $conn = (int)(@IPS_GetInstance($iid)['ConnectionID'] ?? 0);
            if ($conn === 0) {
                $unconnected++;
            }
            $lines[] = $conn > 0
                ? RLT_Text::t('• RLTHubGateway "%s" (#%d) connected to #%d', IPS_GetName($iid), $iid, $conn)
                : RLT_Text::t('• RLTHubGateway "%s" (#%d) without gateway', IPS_GetName($iid), $iid);
        }
        $head = $unconnected > 0
            ? RLT_Text::t('⚠️ %d RLTHubGateway instance(s) without a ModBus gateway — select one under "Gateway" at the top there:', $unconnected)
            : RLT_Text::t('✅ %d ModBus gateway(s) and %d RLTHubGateway instance(s) found:', count($gateways), count($hubs));
        return $head . "\n" . implode("\n", $lines);
    }

    protected function guessLocalSubnetPrefix(): string
    {
        $ip = @gethostbyname(gethostname());
        if ($ip === false || $ip === gethostname()) {
            return '';
        }
        $parts = explode('.', $ip);
        if (count($parts) !== 4) {
            return '';
        }
        $isPrivate = ($parts[0] === '10')
            || ($parts[0] === '192' && $parts[1] === '168')
            || ($parts[0] === '172' && (int)$parts[1] >= 16 && (int)$parts[1] <= 31);
        return $isPrivate ? $parts[0] . '.' . $parts[1] . '.' . $parts[2] : '';
    }

    private function expandRange(string $startIp, string $endIp): array
    {
        $start = ip2long($startIp);
        $end   = ip2long($endIp);
        if ($start === false || $end === false || $start > $end) {
            return [];
        }
        $ips = [];
        for ($i = $start; $i <= $end && count($ips) <= self::MAX_HOSTS; $i++) {
            $ips[] = long2ip($i);
        }
        return $ips;
    }

    // Nicht-blockierender Parallel-Portscan (Vorbild MeterHubDiscovery).
    private function scanPortOpen(array $ips, int $port, float $timeoutSec): array
    {
        $pending = [];
        foreach ($ips as $ip) {
            $s = @stream_socket_client("tcp://$ip:$port", $errno, $errstr, 0.01, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
            if ($s !== false) {
                stream_set_blocking($s, false);
                $pending[$ip] = $s;
            }
        }
        $open = [];
        $deadline = microtime(true) + $timeoutSec;
        while (count($pending) > 0 && microtime(true) < $deadline) {
            $write  = array_values($pending);
            $read   = [];
            $except = [];
            if (@stream_select($read, $write, $except, 0, 200000) === false) {
                break;
            }
            foreach ($pending as $ip => $sock) {
                if (in_array($sock, $write, true)) {
                    if (@stream_socket_get_name($sock, true) !== false) {
                        $open[] = $ip;
                    }
                    fclose($sock);
                    unset($pending[$ip]);
                }
            }
        }
        foreach ($pending as $sock) {
            @fclose($sock);
        }
        return $open;
    }
}
