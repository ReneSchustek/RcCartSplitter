# RcCartSplitter

Shopware 6 Plugin zur automatischen Positionstrennung im Warenkorb bei unterschiedlichen Kundeneingaben.

Wenn ein Kunde dasselbe Produkt mehrfach mit **unterschiedlichen Eingaben** (z. B. verschiedene Längen, Handlaufträger, Endkappen) in den Warenkorb legt, erzeugt dieses Plugin **separate Warenkorbpositionen**. Identische Eingaben erhöhen die Menge der bestehenden Position.

## Funktionen

- Erkennt TMMS-Kundeneingaben (TmmsProductCustomerInputs) automatisch
- Verschiedene Eingabewerte → separate Warenkorbpositionen
- Gleiche Eingabewerte → Mengenerhöhung
- Sichert Eingabewerte pro Position im LineItem-Payload
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

2. **BeforeLineItemAddedEvent:** Liest die TMMS-Session-Daten und speichert sie im LineItem-Payload (`rc_tmms_inputs`). Damit hat jede Position ihre eigene Kopie der Eingabewerte.

3. **CheckoutOrderPlacedEvent:** Korrigiert die custom_fields pro Bestellposition mit den gesicherten Payload-Daten. Ohne diese Korrektur würde TMMS die letzte Eingabe auf alle Positionen desselben Produkts schreiben.

## Konfiguration

Keine eigene Konfiguration nötig. Das Plugin erkennt TMMS-Eingabefelder automatisch.

## Deployment

| Änderung | Befehl |
|----------|--------|
| Erstinstallation / JS-Änderung | `bin/build-storefront.sh` |
| Nur PHP-Änderung | `php bin/console cache:clear` |
