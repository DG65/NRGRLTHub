<?php

// ===========================================================================
// RLTHub — Formular-Panels nach der verbundweiten Konvention „Einheitliche
// Formular-Optik" (SUITE.md), Vorlage MeterHub/MeterHubDiscovery:
//
//   0 👋 Wozu dieses Modul?     einmalig wegklickbar   (PurposeIntroGone)
//   1 🆕 Neu in Version X.Y     je Version wegklickbar (SeenNews)
//   2 📖 Dokumentation & Hilfe  eingeklappt            (vom Modul geliefert)
//   3 Fachpanels                                       (vom Modul geliefert)
//   4 💬 Forum-Hinweis          einmalig wegklickbar   (ForumHintGone)
//   5 🧡 Über dieses Modul      NICHT wegklickbar      (Wortlaut „Variante A")
//
// Ausblenden wird zwischen Instanzen DESSELBEN Modultyps geteilt (nicht
// modulübergreifend, SUITE.md). Die verwendende Klasse liefert die
// Konstanten PREFIX, MODULE_GUID, MODULE_NAME, NEWS_VERSION, PURPOSE (Liste),
// NEWS (Liste), FORUM_THREAD_URL und LICENSE_URL.
// ===========================================================================
trait RLT_PanelTrait
{
    protected function rltPanelCreate(): void
    {
        $this->RegisterAttributeBoolean('PurposeIntroGone', false);
        $this->RegisterAttributeString('SeenNews', '');
        $this->RegisterAttributeBoolean('ForumHintGone', false);
    }

    /** Panels 0 und 1 — ganz oben im Formular. */
    protected function rltPanelsTop(): array
    {
        return array_values(array_filter([$this->rltPurposeIntro(), $this->rltNewsBanner()]));
    }

    /** Panels 4 und 5 — ganz unten im Formular. */
    protected function rltPanelsBottom(): array
    {
        return array_values(array_filter([$this->rltForumHint(), $this->rltLicenseHint()]));
    }

    private function rltPurposeIntro(): ?array
    {
        if ((bool)$this->ReadAttributeBoolean('PurposeIntroGone')) {
            return null;
        }
        $items = [];
        foreach (static::PURPOSE as $line) {
            $items[] = ['type' => 'Label', 'caption' => $line];
        }
        $items[] = ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => static::PREFIX . '_AckPurposeIntro($id);'];
        return [
            'type' => 'ExpansionPanel', 'name' => 'PurposeIntroPanel', 'expanded' => true,
            'caption' => '👋  Wozu dieses Modul?',
            'items' => $items,
        ];
    }

    public function AckPurposeIntro()
    {
        $this->WriteAttributeBoolean('PurposeIntroGone', true);
        $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
        $this->rltPropagateDismiss('PurposeIntro', '');
    }

    private function rltNewsBanner(): ?array
    {
        if ((string)$this->ReadAttributeString('SeenNews') === static::NEWS_VERSION) {
            return null;
        }
        $items = [];
        foreach (static::NEWS as $line) {
            $items[] = ['type' => 'Label', 'caption' => $line];
        }
        $items[] = ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => static::PREFIX . '_AckNews($id);'];
        return [
            'type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'expanded' => true,
            'caption' => '🆕  Neu in Version ' . static::NEWS_VERSION,
            'items' => $items,
        ];
    }

    public function AckNews()
    {
        $this->WriteAttributeString('SeenNews', static::NEWS_VERSION);
        $this->UpdateFormField('NewsPanel', 'visible', false);
        $this->rltPropagateDismiss('News', static::NEWS_VERSION);
    }

    private function rltForumHint(): ?array
    {
        if ((bool)$this->ReadAttributeBoolean('ForumHintGone')) {
            return null;
        }
        $items = [];
        if (static::FORUM_THREAD_URL !== '') {
            $items[] = ['type' => 'Label', 'caption' => static::MODULE_NAME . ' ist Beta — Rückmeldungen sind ausdrücklich willkommen im Community-Thread.'];
            $items[] = ['type' => 'Button', 'caption' => 'Zum Forums-Thread', 'onClick' => "echo '" . static::FORUM_THREAD_URL . "';", 'link' => true];
        } else {
            $items[] = ['type' => 'Label', 'caption' => static::MODULE_NAME . ' ist Beta — Rückmeldungen sind ausdrücklich willkommen. Der Thread im Symcon-Forum wird noch angelegt, der Link erscheint hier, sobald er existiert.'];
        }
        $items[] = ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => static::PREFIX . '_AckForumHint($id);'];
        return [
            'type' => 'ExpansionPanel', 'name' => 'ForumHintPanel', 'expanded' => true,
            'caption' => '💬  Feedback im Symcon-Forum',
            'items' => $items,
        ];
    }

    public function AckForumHint()
    {
        $this->WriteAttributeBoolean('ForumHintGone', true);
        $this->UpdateFormField('ForumHintPanel', 'visible', false);
        $this->rltPropagateDismiss('ForumHint', '');
    }

    // „Variante A" — verbundweit identischer Wortlaut (SUITE.md), nur die
    // LICENSE-Verknüpfung zeigt aufs eigene Repo und den Branch, der die
    // PolyForm-Lizenz trägt. Bewusst NICHT wegklickbar (kein name, kein Ack).
    private function rltLicenseHint(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '🧡  Über dieses Modul',
            'items' => [
                ['type' => 'Label', 'caption' => 'Entstanden aus echter Begeisterung für die eigene Anlage — und ein paar durchgetippten Abenden. Trotzdem: Software-Hobby hin oder her, das hier ist geistiges Eigentum und echte Arbeit steckt drin.'],
                ['type' => 'Label', 'caption' => 'Lizenz: PolyForm Noncommercial 1.0.0 — privat und nicht-kommerziell frei nutzbar, für den gewerblichen Einsatz braucht es eine gesonderte Lizenz vom Rechteinhaber.'],
                ['type' => 'Button', 'caption' => 'Lizenztext ansehen', 'onClick' => "echo '" . static::LICENSE_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => 'Gewerbliche Nutzung oder Fragen zur Lizenz? Einfach melden: dietmar@gureth.eu'],
                ['type' => 'Label', 'caption' => 'Gefällt dir das Modul und du möchtest trotzdem etwas dalassen? Über eine kleine Spende freue ich mich — völlig freiwillig, keine Gegenleistung nötig.'],
                ['type' => 'Button', 'caption' => '☕  Spenden via PayPal', 'onClick' => "echo 'https://paypal.me/DietmarGureth';", 'link' => true],
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Ausblenden über Geschwister-Instanzen desselben Modultyps teilen.
    // „Bestätigen" (Ack*) propagiert, „Übernehmen" (AdoptDismissState)
    // propagiert nie weiter — so ist kein Ping-Pong möglich, ganz ohne Merker.
    // Cross-Instanz-Aufrufe in try/catch(\Throwable): ein @ hält keinen
    // Fatal Error auf.
    // -----------------------------------------------------------------------

    private function rltPropagateDismiss(string $what, string $value): void
    {
        $fn = static::PREFIX . '_AdoptDismissState';
        if (!function_exists($fn)) {
            return;
        }
        foreach (IPS_GetInstanceListByModuleID(static::MODULE_GUID) as $sib) {
            if ($sib === $this->InstanceID) {
                continue;
            }
            try {
                $fn($sib, $what, $value);
            } catch (\Throwable $e) {
                // Eine Instanz mitten im Reload/Löschen darf das Ausblenden nicht mitreißen.
            }
        }
    }

    /** Reiner Übernahme-Schritt für eine Geschwister-Instanz. */
    public function AdoptDismissState(string $what, string $value)
    {
        switch ($what) {
            case 'PurposeIntro':
                $this->WriteAttributeBoolean('PurposeIntroGone', true);
                $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
                break;
            case 'ForumHint':
                $this->WriteAttributeBoolean('ForumHintGone', true);
                $this->UpdateFormField('ForumHintPanel', 'visible', false);
                break;
            case 'News':
                $this->WriteAttributeString('SeenNews', $value);
                $this->UpdateFormField('NewsPanel', 'visible', false);
                break;
        }
    }

    /** Attribute sind von außen nicht lesbar — deshalb eine eigene öffentliche Funktion. */
    public function GetDismissState(): array
    {
        return [
            'purposeIntroGone' => (bool)$this->ReadAttributeBoolean('PurposeIntroGone'),
            'forumHintGone'    => (bool)$this->ReadAttributeBoolean('ForumHintGone'),
            'seenNews'         => (string)$this->ReadAttributeString('SeenNews'),
        ];
    }

    /**
     * Gegenrichtung: eine neu angelegte Instanz übernimmt beim ersten
     * ApplyChanges() den Stand einer Geschwister-Instanz. Zieht nur vor
     * (false→true, ältere→aktuelle News-Version), überschreibt nie einen
     * weiter fortgeschrittenen eigenen Stand.
     */
    protected function rltAdoptDismissFromSibling(): void
    {
        if ((bool)$this->ReadAttributeBoolean('PurposeIntroGone') && (bool)$this->ReadAttributeBoolean('ForumHintGone')
            && (string)$this->ReadAttributeString('SeenNews') === static::NEWS_VERSION) {
            return;
        }
        $fn = static::PREFIX . '_GetDismissState';
        if (!function_exists($fn)) {
            return;
        }
        foreach (IPS_GetInstanceListByModuleID(static::MODULE_GUID) as $sib) {
            if ($sib === $this->InstanceID) {
                continue;
            }
            try {
                $state = $fn($sib);
            } catch (\Throwable $e) {
                continue;
            }
            if (!is_array($state)) {
                continue;
            }
            if (!(bool)$this->ReadAttributeBoolean('PurposeIntroGone') && !empty($state['purposeIntroGone'])) {
                $this->WriteAttributeBoolean('PurposeIntroGone', true);
            }
            if (!(bool)$this->ReadAttributeBoolean('ForumHintGone') && !empty($state['forumHintGone'])) {
                $this->WriteAttributeBoolean('ForumHintGone', true);
            }
            if ((string)$this->ReadAttributeString('SeenNews') !== static::NEWS_VERSION && ($state['seenNews'] ?? '') === static::NEWS_VERSION) {
                $this->WriteAttributeString('SeenNews', static::NEWS_VERSION);
            }
            break;
        }
    }
}
