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
 * Das Plugin ist eine Übergangslösung: Sobald RcCustomFields die Kundeneingaben selbst übernimmt,
 * wird es überflüssig. Der Weg dorthin steht in der README unter „End-of-Life".
 */
final class RcCartSplitter extends Plugin
{
    /**
     * Je höher der Wert, desto weiter außen steht das Plugin in der Twig-Vererbungskette, und
     * seine Block-Überschreibungen gewinnen. TMMS bleibt auf dem Standardwert 0; mit 1000 setzt
     * sich der eigene Hinweistext auch gegen Plugins durch, die ihre Priorität maßvoll anheben.
     */
    public function getTemplatePriority(): int
    {
        return 1000;
    }
}
