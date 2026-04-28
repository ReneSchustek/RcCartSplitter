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
