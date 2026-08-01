<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter;

use Shopware\Core\Framework\Plugin;

/**
 * Repariert das Verhalten von `TmmsProductCustomerInputs` im Warenkorb.
 *
 * TMMS hält die Kundeneingaben in der Session statt an der Position. Legt ein Kunde denselben
 * Artikel zweimal mit verschiedenen Eingaben in den Warenkorb, fasst Shopware beides zu einer
 * Position zusammen, und bei der Bestellung landet die zuletzt eingegebene Angabe auf allen
 * Positionen. Dieses Plugin schreibt die Eingaben in den Positions-Payload, trennt Positionen
 * mit abweichenden Werten und korrigiert Anzeige und Bestelldaten.
 *
 * **Übergangslösung mit Ablaufdatum:** Sobald RcCustomFields die Kundeneingaben selbst übernimmt,
 * wird dieses Plugin überflüssig. Der Weg dorthin steht in der README unter „End-of-Life".
 */
final class RcCartSplitter extends Plugin
{
    /**
     * Höhere Priority = spätere Ladereihenfolge = OUTER-Layer in der Twig-Inheritance,
     * gewinnt dadurch bei Block-Overrides (validated gegen TMMS' Default in EB640100-2-Test).
     */
    public function getTemplatePriority(): int
    {
        return 1000;
    }
}
