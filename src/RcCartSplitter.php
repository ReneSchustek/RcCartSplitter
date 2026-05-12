<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter;

use Shopware\Core\Framework\Plugin;

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
