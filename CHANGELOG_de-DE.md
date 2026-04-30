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
