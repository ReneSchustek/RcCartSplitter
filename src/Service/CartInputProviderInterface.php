<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter\Service;

use Shopware\Core\Checkout\Cart\Event\BeforeLineItemAddedEvent;

// Andockpunkt fuer weitere Input-Plugins: Implementierungen registrieren sich ueber den
// Tag `rc_cart_splitter.input_provider` und liefern Werte fuer den LineItem-Payload.
interface CartInputProviderInterface
{
    /** @return array<string, mixed> payload-Key => -Value, leer = nichts zu setzen */
    public function provide(BeforeLineItemAddedEvent $event): array;
}
