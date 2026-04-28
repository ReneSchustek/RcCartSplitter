# RcCartSplitter

Shopware 6 Plugin zur automatischen Positionstrennung im Warenkorb bei unterschiedlichen Kundeneingaben.

Wenn ein Kunde dasselbe Produkt mehrfach mit **unterschiedlichen Eingaben** (z. B. verschiedene Längen, Handlaufträger, Endkappen) in den Warenkorb legt, erzeugt dieses Plugin **separate Warenkorbpositionen**. Identische Eingaben erhöhen die Menge der bestehenden Position.

## Funktionen

- Erkennt TMMS-Kundeneingaben (TmmsProductCustomerInputs) automatisch
- Verschiedene Eingabewerte -> separate Warenkorbpositionen
- Gleiche Eingabewerte -> Mengenerhöhung
- Sichert Eingabewerte pro Position im LineItem-Payload (payload-basiert, nicht nur Session)
- Liest Eingaben bevorzugt aus dem Request-Payload (Hidden-Felder, vom JS injiziert), mit Fallback auf TMMS-Session-Daten
- Korrigiert die TMMS "Eingabe prüfen"-Anzeige im Warenkorb: Split-Positionen zeigen die korrekten Werte aus dem Payload statt den Session-Wert
- Korrigiert Bestelldaten bei Bestellabschluss (TMMS schreibt sonst die letzte Eingabe auf alle Positionen)
- Kompatibel mit RcDynamicPrice (Meter-Suffix wird in den Hash einbezogen)

## Voraussetzungen

- Shopware 6.7 oder 6.8
- PHP 8.2+
- TmmsProductCustomerInputs (aktiv und konfiguriert)

## Installation

```bash
php bin/console plugin:refresh
php bin/console plugin:install --activate RcCartSplitter
bin/build-storefront.sh
php bin/console cache:clear
```

## Wie es funktioniert

1. **JavaScript:** Überwacht TMMS-Eingabefelder. Bei jeder Änderung wird ein Hash aller Eingabewerte berechnet und als LineItem-ID gesetzt. Verschiedene Hashes = verschiedene IDs = separate Positionen.

2. **BeforeLineItemAddedEvent (CartInputCaptureSubscriber):** Sammelt die Eingaben aller registrierten `CartInputProviderInterface`-Implementierungen und speichert sie im LineItem-Payload. Standard-Provider ist `TmmsCartInputProvider`: bevorzugt werden die Werte aus dem Request-Payload (Hidden-Felder, vom JS injiziert) gelesen, als Fallback dienen die TMMS-Session-Daten. Weitere Input-Plugins koennen einen eigenen Provider unter dem Tag `rc_cart_splitter.input_provider` registrieren.

3. **CartPageLoadedEvent (CartDisplayCorrectionSubscriber):** Korrigiert die TMMS "Eingabe prüfen"-Anzeige im Warenkorb. TMMS setzt die LineItem-Extensions aus der Session, die pro Produktnummer gespeichert ist – bei Split-Positionen steht dort immer der gleiche Wert. Dieser Subscriber überschreibt die Extensions mit den korrekten Werten aus dem Payload.

4. **CheckoutOrderPlacedEvent (OrderInputCorrectionSubscriber):** Korrigiert die custom_fields pro Bestellposition mit den gesicherten Payload-Daten. Ohne diese Korrektur würde TMMS die letzte Eingabe auf alle Positionen desselben Produkts schreiben. Der Schreibvorgang laeuft als einzelnes Batch-CASE-WHEN-UPDATE in einer Transaktion, damit Bestellungen mit vielen Split-Positionen nicht in N Roundtrips zerfallen; DB-Fehler werden geloggt, brechen den Checkout aber nicht ab.

## Konfiguration

Keine eigene Konfiguration nötig. Das Plugin erkennt TMMS-Eingabefelder automatisch.

## Barrierefreiheit (BFSG)

Seit dem 28. Juni 2025 verlangt das BFSG für B2C-Shops WCAG 2.2 AA. Dieses Plugin rendert nur einen kleinen Block unter dem Cart-LineItem; alles andere (Buy-Box, Mini-Cart-Region, Fokus-Stil) liegt beim Storefront-Theme.

### Vom Plugin abgedeckt

- Semantische `<dl>/<dt>/<dd>`-Struktur für Begriff-Wert-Paare statt `<ul>/<li>/<strong>` (WCAG 1.3.1 — Beziehungen)
- Bootstrap-Token `text-body-secondary` statt `text-muted` (WCAG 1.4.3 — dokumentierter Kontrast)
- Maximale Feldzahl zentral aus `TmmsConstants::INPUT_COUNT`, kein Drift zwischen PHP/JS/Twig

### Theme-/Storefront-Pflicht

- Kontrast ≥ 4.5:1 im aktiven Theme — `text-body-secondary` ist Token-basiert, der finale Wert hängt vom Theme
- Sichtbarer Fokus auf Buy-Form-Elementen (`:focus-visible`)
- `<html lang="de">` (oder Sprach-Code des Storefronts)
- Tastaturbedienbarkeit der Buy-Box inkl. TMMS-Eingabefelder
- Mini-Cart-Re-Render per AJAX: Container muss `aria-live="polite"` sein, sonst meldet kein Screenreader die neue Eingabeliste

## Deployment

| Änderung | Befehl |
|----------|--------|
| Erstinstallation / JS-Änderung | `bin/build-storefront.sh` |
| Nur PHP-Änderung | `php bin/console cache:clear` |

## Entwicklung

```bash
composer test         # Unit-Tests ausführen
composer phpstan      # Statische Analyse (Level 8)
composer cs-check     # Code-Style prüfen (PSR-12)
composer cs-fix       # Code-Style automatisch korrigieren
composer quality      # Alle Checks (cs-check + phpstan + test)
```

CI läuft automatisch bei Push und Pull Requests via GitHub Actions.

### Integration-Tests

Tests in `tests/Integration/` sichern den Korrektur-Pfad gegen eine echte Shopware-Test-Datenbank (DBAL-Batch-UPDATE auf `order_line_item.custom_fields`). Sie laufen ausschliesslich in einer Plattform-Test-Umgebung mit gesetztem `KERNEL_CLASS`:

```bash
KERNEL_CLASS=Shopware\\Core\\Kernel vendor/bin/phpunit --testsuite=Integration
```

Ohne Bootstrap überspringen die Tests sich selbst — `composer test` führt nur die Unit-Suite aus.

## Lizenz

Proprietär – siehe [composer.json](composer.json).

<!-- TRIAGE-WORKFLOW: auto-managed by triage-deploy.ps1 -->
## Triage und Reviews

- **Watcher starten:** `.\triage-watch.ps1` (bzw. `.\triage-watch-php.ps1` / `.\triage-watch-shopware.ps1`) im Projekt-Root
- **Review on-demand:** `.\triage-review.ps1` -- laedt Projekt-Regeln aus `.ai/rules/` und uebergibt sie an Ollama
- **Enterprise-Review (ERP-2026):** in Claude Code anfragen -- Claude orchestriert, Ollama macht mechanische Sub-Tasks
- **Status-Dateien:** `.ai/triage-status.json`, `.ai/triage-escalation.md`, `.ai/reviews/*.md`, `.ai/erp/*.md`

Volle Doku: `F:\Entwicklung\_Anleitungen\allgemein\triage-workflow.md`
Routing-Regeln: `.ai/rules/ollama-delegation.md` und `.ai/rules/enterprise-review.md`
<!-- /TRIAGE-WORKFLOW -->
