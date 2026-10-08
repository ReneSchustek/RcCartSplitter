<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter\Service;

use Shopware\Core\Framework\Context;

/** Abstraktion des Kettenladers, damit der Resolver ohne Repository testbar bleibt. */
interface CategoryChainLoaderInterface
{
    /**
     * Liefert die Kategorie selbst und danach ihre Vorfahren bis zur Wurzel, den nächsten zuerst.
     * Der Resolver nimmt den ersten Eintrag mit gesetztem Text, die Reihenfolge entscheidet also,
     * welche Kategorie gewinnt. Ist die Kategorie nicht lesbar, ist die Liste leer.
     *
     * @return list<array{id: string, customFields: array<string, mixed>}>
     */
    public function loadChain(string $primaryCategoryId, Context $context): array;
}
