<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter\Service;

use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;

/**
 * Korrigiert TMMS-Kundeneingaben in den custom_fields der Bestellpositionen.
 * Schmales Interface, damit der Subscriber gegen eine Abstraktion typisiert
 * und der konkrete Service final bleiben kann.
 */
interface OrderInputCorrectorInterface
{
    /**
     * Setzt korrigierte custom_fields in $freshItems (DB-Lese-Stand) und gleicht
     * sie optional in $memoryItems (in-Memory-Stand des Events) ab. Schreibt per
     * Batch-UPDATE an order_line_item; Fehler werden geloggt, nicht weitergeworfen.
     */
    public function correctLineItems(
        OrderLineItemCollection $freshItems,
        ?OrderLineItemCollection $memoryItems,
    ): void;
}
