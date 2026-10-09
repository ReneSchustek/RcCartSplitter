<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter\Storefront\Subscriber;

use Ruhrcoder\RcCartSplitter\Service\TmmsInformationMessageResolverInterface;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Hängt den aufgelösten TMMS-Hinweistext als Page-Extension `rcCartSplitterTmmsInfo` an die
 * Produktdetailseite. Gelesen wird sie im überschriebenen Block der Buy-Widget-Vorlage.
 *
 * Die Quickview bleibt außen vor: Ihre Vorlage überschreibt dieses Plugin nicht, ein Text dort
 * würde berechnet und nie gezeigt.
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
}
