<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter\Service;

use Shopware\Core\Checkout\Cart\Event\BeforeLineItemAddedEvent;

/**
 * Andockpunkt für Quellen von Kundeneingaben. Implementierungen registrieren sich über den Tag
 * `rc_cart_splitter.input_provider`; der CartInputCaptureSubscriber schreibt ihre Werte in den
 * Payload der Position. Er ruft sie nur für Produktpositionen auf.
 */
interface CartInputProviderInterface
{
    /**
     * Liefert ein Provider einen Schlüssel, den ein späterer in der Tag-Reihenfolge ebenfalls
     * liefert, gilt der Wert des späteren.
     *
     * @return array<string, mixed> payload-Key => -Value, leer = nichts zu setzen
     */
    public function provide(BeforeLineItemAddedEvent $event): array;
}
