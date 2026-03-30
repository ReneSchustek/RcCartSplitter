# RcCartSplitter

Shopware 6 Plugin zur automatischen Positionstrennung im Warenkorb bei unterschiedlichen Kundeneingaben.

Wenn ein Kunde dasselbe Produkt mehrfach mit **unterschiedlichen Eingaben** (z. B. verschiedene Längen, Handlaufträger, Endkappen) in den Warenkorb legt, erzeugt dieses Plugin **separate Warenkorbpositionen**. Identische Eingaben erhöhen die Menge der bestehenden Position.

## Funktionen

- Erkennt TMMS-Kundeneingaben (TmmsProductCustomerInputs) automatisch
- Verschiedene Eingabewerte -> separate Warenkorbpositionen
- Gleiche Eingabewerte -> Mengenerhöhung
- Sichert Eingabewerte pro Position im LineItem-Payload (payload-basiert, nicht nur Session)
- Liest Eingaben bevorzugt aus dem Request-Payload (Hidden-Felder, vom JS injiziert), mit Fallback auf TMMS-Session-Daten
- Korrigiert die TMMS "Eingabe pruefen"-Anzeige im Warenkorb: Split-Positionen zeigen die korrekten Werte aus dem Payload statt den Session-Wert
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

2. **BeforeLineItemAddedEvent (TmmsInputCaptureSubscriber):** Liest die TMMS-Kundeneingaben und speichert sie im LineItem-Payload. Bevorzugt werden die Werte aus dem Request-Payload (Hidden-Felder, vom JS injiziert) gelesen. Als Fallback dienen die TMMS-Session-Daten. Damit hat jede Position ihre eigene Kopie der Eingabewerte.

3. **CartPageLoadedEvent (CartDisplayCorrectionSubscriber):** Korrigiert die TMMS "Eingabe pruefen"-Anzeige im Warenkorb. TMMS setzt die LineItem-Extensions aus der Session, die pro Produktnummer gespeichert ist -- bei Split-Positionen steht dort immer der gleiche Wert. Dieser Subscriber ueberschreibt die Extensions mit den korrekten Werten aus dem Payload.

4. **CheckoutOrderPlacedEvent (OrderInputCorrectionSubscriber):** Korrigiert die custom_fields pro Bestellposition mit den gesicherten Payload-Daten. Ohne diese Korrektur wuerde TMMS die letzte Eingabe auf alle Positionen desselben Produkts schreiben.

## Konfiguration

Keine eigene Konfiguration nötig. Das Plugin erkennt TMMS-Eingabefelder automatisch.

## Deployment

| Änderung | Befehl |
|----------|--------|
| Erstinstallation / JS-Änderung | `bin/build-storefront.sh` |
| Nur PHP-Änderung | `php bin/console cache:clear` |

## Entwicklung

```bash
composer quality
```

Fuehrt statische Analyse und Code-Style-Checks aus (sofern in der Shopware-Umgebung konfiguriert).

## Lizenz

Proprietaer -- siehe [composer.json](composer.json).
