<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter\Subscriber;

use Ruhrcoder\RcCartSplitter\Service\CartInputProviderInterface;
use Shopware\Core\Checkout\Cart\Event\BeforeLineItemAddedEvent;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

// Capture-Logik bewusst entkoppelt von TMMS: weitere Input-Quellen docken über den Tag
// `rc_cart_splitter.input_provider` an, ohne diesen Subscriber zu ändern.
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

        // Nur Produktpositionen erreichen die Provider.
        //
        // Der Warenkorb trägt mehr als Produkte: Gutschein-Platzhalter, Versandkosten,
        // Zuschläge. Die Provider sind ausnahmslos produktbezogen — sie schlagen die
        // Kundeneingaben zum Artikel nach. Ein Gutschein-Platzhalter trägt in
        // `referencedId` den **Code** statt einer Kennung; ein Provider, der das für eine
        // Produktkennung hielt, riss den ganzen Warenkorb-Zugang mit. Auf Live war dadurch
        // monatelang kein Gutscheincode einlösbar.
        //
        // Die Prüfung steht hier und nicht in den Providern: Die Schnittstelle ist ein
        // Andockpunkt für weitere Plugins, und eine Annahme, die jeder Provider einzeln
        // treffen müsste, trifft irgendwann einer nicht.
        if ($lineItem->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE) {
            return;
        }

        foreach ($this->providers as $provider) {
            foreach ($provider->provide($event) as $key => $value) {
                $lineItem->setPayloadValue($key, $value);
            }
        }
    }
}
