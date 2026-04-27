<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter\Subscriber;

use Ruhrcoder\RcCartSplitter\Service\CartInputProviderInterface;
use Shopware\Core\Checkout\Cart\Event\BeforeLineItemAddedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Schreibt Eingabewerte aus allen registrierten {@see CartInputProviderInterface}-Implementierungen
 * in den LineItem-Payload, sobald ein Produkt in den Warenkorb gelegt wird.
 *
 * Damit ist die Capture-Logik nicht mehr an TMMS gebunden — weitere Input-Plugins
 * koennen einen eigenen Provider unter Tag `rc_cart_splitter.input_provider` registrieren.
 */
final class CartInputCaptureSubscriber implements EventSubscriberInterface
{
    /** @param iterable<CartInputProviderInterface> $providers */
    public function __construct(
        private readonly iterable $providers,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            BeforeLineItemAddedEvent::class => ['onBeforeLineItemAdded', 100],
        ];
    }

    public function onBeforeLineItemAdded(BeforeLineItemAddedEvent $event): void
    {
        $lineItem = $event->getCart()->get($event->getLineItem()->getId()) ?? $event->getLineItem();

        foreach ($this->providers as $provider) {
            foreach ($provider->provide($event) as $key => $value) {
                $lineItem->setPayloadValue($key, $value);
            }
        }
    }
}
