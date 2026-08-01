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

2. **BeforeLineItemAddedEvent (CartInputCaptureSubscriber):** Sammelt die Eingaben aller registrierten `CartInputProviderInterface`-Implementierungen und speichert sie im LineItem-Payload. Standard-Provider ist `TmmsCartInputProvider`: bevorzugt werden die Werte aus dem Request-Payload (Hidden-Felder, vom JS injiziert) gelesen, als Fallback dienen die TMMS-Session-Daten. Weitere Input-Plugins können einen eigenen Provider unter dem Tag `rc_cart_splitter.input_provider` registrieren.

3. **CartPageLoadedEvent (CartDisplayCorrectionSubscriber):** Korrigiert die TMMS "Eingabe prüfen"-Anzeige im Warenkorb. TMMS setzt die LineItem-Extensions aus der Session, die pro Produktnummer gespeichert ist – bei Split-Positionen steht dort immer der gleiche Wert. Dieser Subscriber überschreibt die Extensions mit den korrekten Werten aus dem Payload.

4. **CheckoutOrderPlacedEvent (OrderInputCorrectionSubscriber):** Korrigiert die custom_fields pro Bestellposition mit den gesicherten Payload-Daten. Ohne diese Korrektur würde TMMS die letzte Eingabe auf alle Positionen desselben Produkts schreiben. Der Schreibvorgang läuft als einzelnes Batch-CASE-WHEN-UPDATE in einer Transaktion, damit Bestellungen mit vielen Split-Positionen nicht in N Roundtrips zerfallen; DB-Fehler werden geloggt, brechen den Checkout aber nicht ab.

## Erweiterung: weitere Suffix-Plugins

Das Plugin konsumiert generisch alle `data-rc*Suffix`-Attribute am `<form>` über `_collectAllSuffixes()` und mischt sie automatisch in den LineItem-ID-Hash. Die Event-Anmeldung ist seit v2.0.0 ebenfalls generisch: ein einziges Event `rcSuffixChanged` triggert die Re-Berechnung. Ein neues Suffix-Plugin braucht keine Code-Änderung an dieser Datei mehr — zwei Schritte reichen:

1. Suffix-Wert am Form setzen:
   ```js
   form.dataset.rcMaterialSuffix = 'eiche';
   ```
2. Nach jeder Änderung das generische Cart-Splitter-Event dispatchen:
   ```js
   form.dispatchEvent(new CustomEvent('rcSuffixChanged', {
       detail: { source: 'rcMaterial', suffix: 'eiche' },
   }));
   ```
   `detail.source` ist Pflichtfeld (vendor-eindeutiger Plugin-Name, camelCase). `detail.suffix` ist empfohlen; weitere Felder sind plugin-spezifisch und für Konsumenten unverbindlich. Plugins, die das Event auch selbst abhören, filtern eigene Dispatches per `event.detail?.source !== '<eigene-source>'` (Self-Loop-Schutz).

Der Event-Name ist als statische Konstante `CartSplitterPlugin.SUFFIX_CHANGED_EVENT` exponiert; ein JS-Unit-Test in `tests/Js/cart-splitter.test.mjs` verankert den Vertrag. Plugin-spezifische Events (`rc{Name}Changed`) bleiben für Plugin-interne Listener weiterhin zulässig.

**Warum der neutrale Name** (`rcSuffixChanged`, nicht `rcCartSplitter:suffixChanged`): der Event gehört dem Protokoll, nicht einem einzelnen Plugin. Suffix-Plugins funktionieren so auch in Standalone-Setups ohne RcCartSplitter (No-op-Dispatch ins Leere) und sind nicht namenstechnisch an einen Konsumenten gekoppelt.

## Konfiguration

Keine eigene Konfiguration nötig. Das Plugin erkennt TMMS-Eingabefelder automatisch.

## Architektur-Notizen

### Warum DBAL statt DAL an zwei Stellen

Der Plugin-Standard ist DAL (`EntityRepository`); zwei Stellen weichen bewusst auf DBAL aus:

1. `OrderInputCorrectionService::batchUpdateCustomFields()` schreibt `custom_fields` per Batch-`UPDATE` direkt in die Tabelle `order_line_item`. DAL würde bei jedem Schreibvorgang ein `EntityWrittenEvent` feuern, das TmmsProductCustomerInputs abfängt und unsere Korrektur sofort wieder mit dem Session-Wert überschreibt. DBAL umgeht den Event-Bus. Zusätzlich wird ein einzelnes `CASE id WHEN ... THEN ... END`-Statement in einer Transaktion abgesetzt, damit Bestellungen mit vielen Split-Positionen nicht in N Einzel-Roundtrips zerfallen.
2. `TmmsCartInputProvider::fetchProductNumber()` liest die `product_number` per Native-`SELECT` aus der `product`-Tabelle. DAL würde die komplette `ProductEntity` inklusive Translations und Associations laden — pro `AddToCart`-Request wäre das unnötig teuer.

Beide Stellen verwenden Parameter-Binding (`Uuid::fromHexToBytes`) und sind durch Unit- und Integration-Tests abgedeckt.

## Barrierefreiheit (BFSG)

Seit dem 28. Juni 2025 verlangt das BFSG für B2C-Shops WCAG 2.2 AA. Dieses Plugin rendert nur einen kleinen Block unter dem Cart-LineItem; alles andere (Buy-Box, Mini-Cart-Region, Fokus-Stil) liegt beim Storefront-Theme.

### Vom Plugin abgedeckt

- Semantische `<dl>/<dt>/<dd>`-Struktur für Begriff-Wert-Paare statt `<ul>/<li>/<strong>` (WCAG 1.3.1 — Beziehungen)
- Programmatischer Gruppenkontext über `aria-label="{{ 'rc-cart-splitter.lineItemInputs'|trans|sw_sanitize }}"` an der `<dl>` (Snippets DE/EN unter `src/Resources/snippet/`, WCAG 1.3.1)
- Bootstrap-Token `text-body-secondary` statt `text-muted` (WCAG 1.4.3 — dokumentierter Kontrast)
- Maximale Feldzahl zentral aus `TmmsConstants::INPUT_COUNT`, kein Drift zwischen PHP/JS/Twig

### Kontrast-Baseline (Bootstrap 5.3 Default)

`text-body-secondary` löst sich in Bootstrap 5.3 zu `rgba(var(--bs-body-color-rgb), 0.75)` auf. Mit dem Default `--bs-body-color-rgb: 33, 37, 41` ergibt sich nach Alpha-Komposition über weißem Hintergrund die effektive Farbe `rgb(88, 92, 94)`:

| Hintergrund | effektives Verhältnis | WCAG-AA (kleiner Text) |
|---|---|---|
| `#ffffff` (Card-/Body-Default) | 6.76:1 | bestanden |
| `#f8f9fa` (`--bs-tertiary-bg`) | 6.41:1 | bestanden |

### Theme-/Storefront-Pflicht

- Kontrast ≥ 4.5:1 im aktiven Theme — `text-body-secondary` ist Token-basiert, der finale Wert hängt vom Theme
- Sichtbarer Fokus auf Buy-Form-Elementen (`:focus-visible`)
- `<html lang="de">` (oder Sprach-Code des Storefronts)
- Tastaturbedienbarkeit der Buy-Box inkl. TMMS-Eingabefelder
- Mini-Cart-Re-Render per AJAX: Container muss `aria-live="polite"` sein, sonst meldet kein Screenreader die neue Eingabeliste

## End-of-Life — Ablösung durch RcCustomFields

Dieses Plugin ist eine Brücke: Es repariert das Verhalten von `TmmsProductCustomerInputs` im
Warenkorb. Sobald **RcCustomFields** die Kundeneingaben selbst übernimmt, wird es überflüssig.
`RcCustomFields` bringt dafür bereits den Befehl `rc-custom-fields:migrate-tmms` mit (idempotent,
transaktional, mit Rücknahme).

**Vor dem ersten Schritt lesen:** Die Reihenfolge unten ist nicht beliebig. Wer dieses Plugin
abschaltet, solange Positionen mit Kundeneingaben im Warenkorb eines Kunden liegen, nimmt diesen
Positionen ihre Zuordnung — die Eingaben stehen dann nicht mehr an der richtigen Position.

### Ablauf

1. **Bestandsaufnahme.** Wie viele Bestellungen tragen Positions-Eingaben dieses Plugins?

   ```sql
   SELECT COUNT(*) AS positionen,
          COUNT(DISTINCT order_id) AS bestellungen,
          MAX(created_at) AS zuletzt
   FROM order_line_item
   WHERE JSON_EXTRACT(payload, '$.rcTmmsActive') IS NOT NULL;
   ```

   Solange `zuletzt` in den letzten Tagen liegt, ist das Plugin im aktiven Einsatz. Der Marker
   heißt `rcTmmsActive`, die Werte selbst stehen daneben als `rcTmmsField<N>Value` — auf dem
   Live-Spiegel gemessen; `rc_tmms_inputs` kommt nur im Session-Rückfall vor und fehlt in den
   Bestellungen.

2. **RcCustomFields einrichten** und die Produkt-Felder übernehmen:

   ```bash
   php bin/console rc-custom-fields:migrate-tmms --dry-run   # zeigt, was passieren würde
   php bin/console rc-custom-fields:migrate-tmms
   ```

3. **Ruhige Minute abwarten.** Keine offenen Warenkörbe mit Kundeneingaben — die Abfrage aus
   Schritt 1 auf `cart` statt `order_line_item` angewendet zeigt es; im Zweifel außerhalb der
   Geschäftszeiten umstellen.

4. **RcCartSplitter deaktivieren**, Cache leeren, im Frontend gegenprüfen: Ein Artikel mit
   Kundeneingabe zweimal mit **verschiedenen** Werten in den Warenkorb — es müssen weiterhin zwei
   Positionen entstehen, jetzt von RcCustomFields.

5. **Deinstallieren mit erhaltenen Daten:**

   ```bash
   php bin/console plugin:uninstall --keep-user-data RcCartSplitter
   ```

   Die Positions-Payloads in bestehenden Bestellungen bleiben damit unangetastet. Sie sind
   Bestandsdaten: Was ein Kunde bestellt hat, muss nachlesbar bleiben.

6. **Endgültig aufräumen** erst nach der Aufbewahrungsfrist des Shops und nur, wenn keine
   Bestellung aus Schritt 1 mehr benötigt wird.

### Rückweg

Bis einschließlich Schritt 4 ist der Weg ohne Datenverlust umkehrbar:

```bash
php bin/console rc-custom-fields:migrate-tmms --rollback
php bin/console plugin:activate RcCartSplitter
php bin/console cache:clear
```

Ab Schritt 5 ist die Rückkehr eine Neuinstallation — die Bestelldaten bleiben, die
Plugin-Konfiguration ist neu zu setzen.

### Woran man erkennt, dass etwas nicht stimmt

| Symptom | Bedeutung |
|---|---|
| Eine Position zeigt die Eingabe einer anderen | Beide Plugins waren gleichzeitig aktiv — eines abschalten |
| Zwei gleiche Artikel mit verschiedenen Eingaben landen in einer Position | Keines der beiden Plugins greift; Schritt 4 rückgängig machen |
| Eingaben fehlen in neuen Bestellungen, alte sind vollständig | Die Übernahme aus Schritt 2 lief nicht oder nicht für alle Produkte |

## Deployment

| Änderung | Befehl |
|----------|--------|
| Erstinstallation / JS-Änderung | `bin/build-storefront.sh` |
| Nur PHP-Änderung | `php bin/console cache:clear` |

## Entwicklung

```bash
composer test         # Unit-Tests ausführen
composer test:js      # JS-Unit-Tests für cart-splitter.plugin.js (Node ≥ 18, ohne npm-Dependencies)
composer phpstan      # Statische Analyse (Level 8)
composer cs-check     # Code-Style prüfen (PSR-12)
composer cs-fix       # Code-Style automatisch korrigieren
composer lint:xml     # services.xml und Co. auf well-formed prüfen (PHP-DOM)
composer lint:twig    # Storefront-Templates über Twig-Lexer prüfen (Syntax)
composer coverage     # PHPUnit mit Clover-Coverage-Report (coverage.xml)
composer coverage:gate # Aggregat-Coverage gegen Schwellen prüfen
composer quality      # Alle Checks (cs-check + lint:xml + lint:twig + phpstan + test)
```

`composer coverage` setzt einen aktiven Coverage-Treiber voraus (`pcov` empfohlen, alternativ `xdebug` mit `XDEBUG_MODE=coverage`). Aggregat-Coverage-Schwellen werden in `bin/coverage-gate.php` gepflegt:

- `src/Service/`: ≥ 80 % Line-Coverage
- `src/Subscriber/`: ≥ 60 % Line-Coverage

CI ruft `composer coverage` und anschließend `composer coverage:gate`; ein Schwellen-Verstoß bricht den Build. Der Clover-Report wird als Workflow-Artefakt hochgeladen.

`composer lint:twig` arbeitet ohne Plattform-Boot und prüft daher nur die Twig-Syntax (Lexer-Stufe). Tag- und Filter-Existenz (`sw_extends`, `sw_sanitize`, `sw_icon`) wird gegen eine vollständige Shopware-Installation mit dem gebooteten Konsolen-Befehl gegenvalidiert:

```bash
bin/console lint:twig src/Resources/views
bin/console lint:xml  src/Resources/config
```

CI läuft automatisch bei Push und Pull Requests via GitHub Actions.

### JS-Unit-Tests

Die Storefront-Logik in `cart-splitter.plugin.js` ist über Node-eigene Test-Tools (`node:test`) abgedeckt. Keine npm-Dependencies, keine `package.json` — der Test-Runner liest die Quelldatei direkt ein, evaluiert sie gegen eine Plugin-Stub-Klasse und prüft `_fnv32a`, `_computeId`, `_collectAllSuffixes`, `_cleanLabel` und `_getTmmsFieldLabel`. Determinismus von FNV-1a ist über öffentliche Referenzwerte (z. B. `0xbf9cf968` für `"foobar"`) belegt.

```bash
node --test tests/Js/cart-splitter.test.mjs
# oder:
composer test:js
```

CI führt diese Tests in einem eigenen Job (`js-tests`) bei jedem Push und Pull Request aus.

### Integration-Tests

Tests in `tests/Integration/` sichern den Korrektur-Pfad gegen eine echte Shopware-Test-Datenbank (DBAL-Batch-UPDATE auf `order_line_item.custom_fields`). Sie laufen ausschließlich in einer Plattform-Test-Umgebung mit gesetztem `KERNEL_CLASS`:

```bash
KERNEL_CLASS=Shopware\\Core\\Kernel vendor/bin/phpunit --testsuite=Integration
```

Ohne Bootstrap überspringen die Tests sich selbst — `composer test` führt nur die Unit-Suite aus.

## Versionen

Vollständige Versions-Historie: [`CHANGELOG_de-DE.md`](CHANGELOG_de-DE.md) (deutsch) bzw. [`CHANGELOG_en-GB.md`](CHANGELOG_en-GB.md) (englisch). Die Dateien folgen der Shopware-Plugin-Manager-Konvention und werden im Admin direkt angezeigt.

## Release-Prozess

Rollback bei fehlgeschlagenem Update: Downgrade über den Plugin-Manager reicht — das Plugin bringt keine eigenen Migrationen mit.

## Lizenz

Proprietär – siehe [composer.json](composer.json).

<!-- TRIAGE-WORKFLOW: auto-managed by triage-deploy.ps1 -->
