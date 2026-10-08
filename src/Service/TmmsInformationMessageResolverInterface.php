<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter\Service;

use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;

/** Abstraktion des Resolvers, damit der Storefront-Subscriber gegen sie typisiert und der Resolver final bleiben kann. */
interface TmmsInformationMessageResolverInterface
{
    /**
     * Reihenfolge: Produkt → Kategoriekette → Plugin-Konfiguration des Verkaufskanals → null.
     * Bei null zeigt das Template das Snippet.
     */
    public function resolveForProduct(
        ProductEntity $product,
        string $salesChannelId,
        Context $context,
    ): ResolvedTmmsInfoMessage;
}
