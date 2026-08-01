<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter\Service;

use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;

interface TmmsInformationMessageResolverInterface
{
    /**
     * Löst den anzuzeigenden TMMS-Hinweistext für ein Produkt auf.
     * Reihenfolge: Produkt → Kategorie-Chain → Plugin-Config → null (Twig-Fallback).
     */
    public function resolveForProduct(
        ProductEntity $product,
        string $salesChannelId,
        Context $context,
    ): ResolvedTmmsInfoMessage;
}
