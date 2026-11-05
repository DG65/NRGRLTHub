<?php

// ===========================================================================
// Übersetzung für RLTHub, RLTHubGateway und RLTHubDiscovery. Quelltexte stehen
// auf Englisch im Code, die deutsche Fassung in locale.json (Symcons übliches
// Muster). libs/locale.json ist die Quelle, php .tools/locale-bauen.php kopiert
// sie in alle drei Modulordner (Symcon liest locale.json nur dort).
//
// Treiber, Traits und Modbus-Clients haben kein $this->Translate(); die Module
// setzen deshalb im Konstruktor den Übersetzer. Ohne Übersetzer bleibt der
// englische Text stehen.
//
// Symcons Translate() liest bei JEDEM Aufruf die locale.json (SUITE.md
// Stolperstein 24). Hier fragt jeder Text Symcon deshalb höchstens EINMAL je
// Prozess und merkt sich die Antwort.
// ===========================================================================

final class RLT_Text
{
    /** @var callable|null fn(string): string */
    private static $translator = null;
    /** @var array<string,string> gemerkte Übersetzungen */
    private static $memo = [];
    /** @var int Zähler für den Prüfstand: wie oft wurde Symcon wirklich gefragt */
    public static $requests = 0;

    public static function setTranslator(?callable $translator): void
    {
        self::$translator = $translator;
        self::$memo = [];
    }

    /** Übersetzer für ein Modul: Symcons Translate(), aber je Text nur einmal je Prozess. */
    public static function withMemo(callable $translate): callable
    {
        return function (string $text) use ($translate): string {
            if (!isset(self::$memo[$text])) {
                self::$requests++;
                try {
                    self::$memo[$text] = (string)$translate($text);
                } catch (\Throwable $e) {
                    // z. B. Instanz während des Aufrufs gelöscht: Quelltext zeigen, nichts merken.
                    return $text;
                }
            }
            return self::$memo[$text];
        };
    }

    /** Text übersetzen; mit Argumenten wie sprintf (Platzhalter %s/%d, ein Prozentzeichen als %%). */
    public static function t(string $text, ...$args): string
    {
        $translated = self::$translator !== null ? (string)(self::$translator)($text) : $text;
        if ($args === []) {
            return $translated;
        }
        $out = @vsprintf($translated, array_map(function ($a) {
            return is_scalar($a) || $a === null ? (string)$a : (string)json_encode($a);
        }, $args));
        return $out === false ? $translated : $out;
    }

    /**
     * Dezimalzahl mit einer Nachkommastelle in der Schreibweise der Sprache. Der Schlüssel "." ist das
     * englische Dezimalzeichen selbst; locale.json übersetzt es zu "," (ein anderer Quelltext als der
     * Zeichenschlüssel selbst wäre im Englischen sichtbar).
     */
    public static function number(float $value): string
    {
        return number_format($value, 1, self::t('.'), '');
    }

    /** Zeitstempel in der Schreibweise der Sprache (Deutsch TT.MM.JJJJ HH:MM:SS, Englisch ISO). */
    public static function time(int $timestamp): string
    {
        return date(self::t('Y-m-d H:i:s'), $timestamp);
    }

    /** Uhrzeit in der Schreibweise der Sprache. */
    public static function clock(int $timestamp): string
    {
        return date('H:i:s', $timestamp);
    }
}
