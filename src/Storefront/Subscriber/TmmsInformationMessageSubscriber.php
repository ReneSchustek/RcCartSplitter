<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter\Storefront\Subscriber;

use Ruhrcoder\RcCartSplitter\Service\TmmsInformationMessageResolverInterface;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Shopware\Storefront\Page\Product\QuickView\MinimalQuickViewPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Hängt den aufgelösten TMMS-Hinweistext als Page-Extension `rcCartSplitterTmmsInfo` an die
 * Produktdetailseite und die Quickview-Seite.
 *
 * Gelesen wird die Extension im überschriebenen Block der Buy-Widget-Vorlage. Für die Quickview
 * überschreibt dieses Plugin keinen Block; dort hängt die Extension an, ohne angezeigt zu werden.
 */
final class TmmsInformationMessageSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly TmmsInformationMessageResolverInterface $resolver,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductPageLoadedEvent::class => 'onProductPageLoaded',
            MinimalQuickViewPageLoadedEvent::class => 'onQuickViewPageLoaded',
        ];
    }

    public function onProductPageLoaded(ProductPageLoadedEvent $event): void
    {
        $resolved = $this->resolver->resolveForProduct(
            $event->getPage()->getProduct(),
            $event->getSalesChannelContext()->getSalesChannel()->getId(),
            $event->getSalesChannelContext()->getContext(),
        );

        $event->getPage()->addExtension('rcCartSplitterTmmsInfo', $resolved);
    }

    public function onQuickViewPageLoaded(MinimalQuickViewPageLoadedEvent $event): void
    {
        $resolved = $this->resolver->resolveForProduct(
            $event->getPage()->getProduct(),
            $event->getSalesChannelContext()->getSalesChannel()->getId(),
            $event->getSalesChannelContext()->getContext(),
        );

        $event->getPage()->addExtension('rcCartSplitterTmmsInfo', $resolved);
    }
}
