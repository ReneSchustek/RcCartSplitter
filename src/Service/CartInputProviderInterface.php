<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter\Service;

use Shopware\Core\Checkout\Cart\Event\BeforeLineItemAddedEvent;

/**
 * Liefert Eingabewerte, die beim AddToCart in den LineItem-Payload geschrieben werden.
 *
 * Erlaubt es, neben TMMS weitere Input-Plugins anzubinden, ohne den Subscriber
 * zu aendern. Implementierungen werden ueber den Tag `rc_cart_splitter.input_provider`
 * automatisch registriert.
 */
interface CartInputProviderInterface
{
    /**
     * @return array<string, mixed> payload-Key => -Value, leer = nichts zu setzen
     */
    public function provide(BeforeLineItemAddedEvent $event): array;
}
