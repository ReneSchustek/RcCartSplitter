<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter\Service;

use Shopware\Core\Framework\Context;

interface CategoryChainLoaderInterface
{
    /**
     * Lädt die Kategorie-Kette einer Primärkategorie von der Kategorie selbst
     * bis zur Wurzel (deepest first). Pro Eintrag wird `id` und `customFields`
     * geliefert — Reihenfolge bestimmt die Gewinner-Logik im Resolver.
     *
     * @return list<array{id: string, customFields: array<string, mixed>}>
     */
    public function loadChain(string $primaryCategoryId, Context $context): array;
}
