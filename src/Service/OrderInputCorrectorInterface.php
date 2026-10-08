<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter\Service;

use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;

/**
 * Korrigiert TMMS-Kundeneingaben in den custom_fields der Bestellpositionen.
 * Das Interface existiert, damit der Subscriber im Test einen Ersatz bekommt und der konkrete
 * Service trotzdem final bleiben kann.
 */
interface OrderInputCorrectorInterface
{
    /**
     * Schreibt die korrigierten custom_fields per UPDATE nach order_line_item und setzt sie danach
     * in $freshItems (frisch aus der Datenbank gelesen) und, falls übergeben, in $memoryItems (die
     * Positionen am Ereignis). Datenbankfehler werden protokolliert, nicht weitergeworfen; die
     * Objekte bleiben dann unverändert.
     */
    public function correctLineItems(
        OrderLineItemCollection $freshItems,
        ?OrderLineItemCollection $memoryItems,
    ): void;
}
