# 2.1.0

- Hinzugefügt: TMMS-Hinweistext frei konfigurierbar in drei Scopes — Produkt-Custom-Field `rc_cart_splitter_tmms_info_message`, Kategorie-Custom-Field `rc_cart_splitter_cat_tmms_info_message` (Kategorie-Chain), Plugin-Konfiguration `RcCartSplitter.config.tmmsInformationMessage`. Reihenfolge: Produkt > Kategorie > Plugin-Config > Snippet-Default (`rc-cart-splitter.tmmsInformationMessage`). Der Text ersetzt den TMMS-Default-Hinweis unter den Eingabefeldern, der mit aktivem RcCartSplitter fachlich falsch war ("vorherige Eingabe wird überschrieben"). Twig-Decoration auf den Block `buy_widget_configurator_include_customerinput_informationmessage_content`; Container/Sichtbarkeit/Icon bleiben beim TMMS-Plugin.
- Hinzugefügt: Service `TmmsInformationMessageResolver` mit eigenem `CategoryChainLoader` (kein Cross-Plugin-Coupling) und Scope-Logging im Channel `rc_cart_splitter`. Aufgelöster Text wird über die Page-Extension `rcCartSplitterTmmsInfo` an Produkt- und Quickview-Page gehängt.
- Hinzugefügt: Plugin-Migrations für die beiden Custom-Field-Sets `rc_cart_splitter` (Produkt) und `rc_cart_splitter_category` (Kategorie), jeweils mit Textarea-Feldern und mehrsprachigen Labels.
- Geändert: Plugin-Template-Priorität auf `-1` (Default 0), damit der TMMS-Block-Override deterministisch greift.

# 2.0.2

- Behoben: TMMS-Eingaben werden bei Split-Positionen (z. B. mehrere Bodenprofile mit unterschiedlichem Gehrungsschnitt und Längen-Suffix aus RcDynamicPrice) nicht mehr durch Session-Werte überschrieben. Der Session-Fallback im Input-Provider liefert jetzt dieselbe Payload-Form wie der JS-Pfad (`rcTmmsActive` plus `rcTmmsField<N>Value`/`Label`), und der Display-Subscriber entfernt geleakte TMMS-Extensions für Felder ohne Position-Wert. Betrifft alle TMMS-Feldtypen — Select-Felder besonders.
- Verbessert: Display-Korrektur schreibt jetzt zusätzlich das Label in die TMMS-Extension (vorher nur Wert).
- Verbessert (Security): TMMS-Session-Daten werden jetzt am Read-Layer (`TmmsPayloadReader::readSessionData`) gleich sanitisiert wie der Request-Pfad — `strip_tags` plus 2000-Zeichen-Cap. Schließt eine Asymmetrie, durch die Roh-Strings aus der Session unsanitisiert in `lineItem->payload` und `order_line_item.custom_fields` gelangen konnten (stored-XSS-/Payload-Bomb-Hardening, Defense-in-Depth gegenüber Twig-Auto-Escape).
- Aufgeräumt: Alle TMMS-Schema-Magic-Strings (`rcTmmsField<N>Value/Label`, `tmms_customer_input_<N>_value/label/placeholder/fieldtype`, `tmmsLineItemCustomerInput<N>`, `tmms_customer_input_<N>_<productNumber>`) konsolidiert in `TmmsConstants`-Builder-Methoden (`payloadValueKey`, `payloadLabelKey`, `sessionKey`, `extensionName`, `customFieldValueKey`, `customFieldLabelKey`, `customFieldPlaceholderKey`, `customFieldFieldtypeKey`). Schema-Änderungen brauchen jetzt nur noch eine Datei-Änderung.
- Aufgeräumt: `composer quality` läuft portabel auf Windows und Linux (`php vendor/bin/...`-Wrapper), `.php-cs-fixer.php` deckt jetzt auch `tests/` ab.

# 2.0.1

- Doku: README präzisiert den Detail-Payload-Vertrag (`source`-Pflichtfeld, `suffix` empfohlen, plugin-spezifische Felder unverbindlich) und nennt die Self-Loop-Konvention für Plugins, die das Event sowohl feuern als auch abhören. Naming-Begründung für den neutralen Event-Namespace dokumentiert (vorher nur in interner Notiz).
- Reine Doku-Klärung, kein Verhaltenswechsel. Patch-Bump.

# 2.0.0

> **Breaking Change.** Das Storefront-JS hört nur noch auf das generische Event `rcSuffixChanged`. Suffix-Plugins müssen dieses Event nach jeder Wert-Änderung mitfeuern; die alte hardcodierte Event-Liste (`rcMeterLengthChanged`, `rcColorPickerChanged`) wird nicht mehr beachtet. Plugin-spezifische Events bleiben für Plugin-interne Listener zulässig.

- Geändert: Generisches Suffix-Event `rcSuffixChanged` als alleiniger Trigger für die LineItem-ID-Re-Berechnung. Der Event-Name ist als statische Konstante `CartSplitterPlugin.SUFFIX_CHANGED_EVENT` exponiert und durch einen JS-Unit-Test verankert (POLS).
- Verbessert: Erweiterungs-Pfad für neue Suffix-Plugins erfordert keinen Pull Request mehr gegen `cart-splitter.plugin.js` — Suffix setzen plus generisches Event dispatchen reicht.
- Geändert: Plugin-Interaktionsprotokoll (`.ai/rules/plugin-interaction.md`) und README dokumentieren den neuen Event-Vertrag und den vereinfachten Erweiterungs-Pfad.

### Upgrade-Hinweis
- Vor dem Update auf v2.0.0 müssen alle aktiven Suffix-Plugins die neue Event-Konvention erfüllen: **RcColorPicker ≥ 2.2.0** und **RcDynamicPrice ≥ 1.7.0**. Ohne aktualisierte Suffix-Plugins greifen Suffix-Änderungen am Form erst beim nächsten regulären TMMS-Input-Event — die Funktion bricht nicht, läuft aber träge.
- Standalone-Suffix-Plugins (ohne RcCartSplitter) laufen unverändert, das zusätzlich gefeuerte Event ist ohne Listener ein No-op.

# 1.2.0

- Hinzugefügt: Screenreader-Bezugskontext für die Kundeneingaben-Liste über `aria-label` — neue Snippets in Deutsch und Englisch (WCAG 1.3.1)
- Verbessert: Korrektur-Service auf eine öffentliche Methode reduziert und versiegelt — schmales Interface für saubere Tests und Erweiterungen (DIP/FCoI)
- Verbessert: Defensive Obergrenze (500 Positionen) beim Laden für die Bestell-Korrektur, härtere Validierung der Session-Daten gegen Typ-Manipulation, DB-Hiccups beim AddToCart sind jetzt im Log sichtbar
- Verbessert: Eigener Logging-Kanal `rc_cart_splitter`, Korrektur-Fehler schreiben den vollständigen Stack-Trace statt nur die Fehlermeldung
- Verbessert: Lokales Quality-Gate prüft Twig-Syntax und XML-Strukturen, CI führt PHPUnit mit pcov-Coverage und Schwellen-Gate (Service 80 %, Subscriber 60 %)
- Verbessert: README um Architektur-Notizen zu den zwei DBAL-Sonderfällen, Anleitung „weitere Suffix-Plugins" und BFSG-Kontrast-Baseline (6,76:1 gegen weißen Hintergrund — über WCAG-AA-Schwelle)
- Verbessert: Aussagekräftige Variablennamen in der LineItem-ID-Berechnung im Storefront-JavaScript
- Behoben: PHPUnit-Lauf ohne aktiven Coverage-Treiber bricht nicht mehr — Coverage-Konfiguration läuft jetzt rein über `composer coverage`

# 1.1.0

- Verbessert: Korrektur der `order_line_item.custom_fields` läuft als einzelnes Batch-CASE-WHEN-UPDATE in einer Transaktion statt N Einzel-Roundtrips
- Verbessert: Eingabe-Capture entkoppelt von TMMS — weitere Input-Plugins können sich über den Tag `rc_cart_splitter.input_provider` einklinken
- Verbessert: TMMS-Payload-Reader mit Schema- und Längen-Validierung (Schutz vor Payload-Bombs)
- Verbessert: aggregiertes Logging und Fehlerbehandlung im Korrektur-Pfad — DB-Fehler brechen den Checkout nicht ab

# 1.0.2

- Hinzugefügt: GitHub-Actions-Pipeline für PHPStan, PHP CS Fixer und PHPUnit
- Verbessert: Service-Extraktion in `OrderInputCorrectionService`, Unit-Tests
- Behoben: Diverse Review-Findings (FQCN-Importe, Snippets, Priorität)

# 1.0.1

- Behoben: Bestellpositionen erhalten die korrekten TMMS-Eingaben pro Split — TMMS überschrieb zuvor alle Positionen desselben Produkts mit dem letzten Wert

# 1.0.0

- Hinzugefügt: Automatische Positionstrennung bei unterschiedlichen Kundeneingaben
- Hinzugefügt: TMMS-Eingaben werden pro Position im LineItem-Payload gesichert
- Hinzugefügt: Korrektur der TMMS-„Eingabe prüfen"-Anzeige im Warenkorb (Mini-Cart, Cart, Confirm)
- Hinzugefügt: Generisches Suffix-Protokoll für LineItem-IDs — andere Plugins (z. B. RcDynamicPrice) wirken ohne Code-Änderung mit
