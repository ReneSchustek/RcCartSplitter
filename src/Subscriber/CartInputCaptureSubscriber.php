<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter\Subscriber;

use Ruhrcoder\RcCartSplitter\Service\CartInputProviderInterface;
use Shopware\Core\Checkout\Cart\Event\BeforeLineItemAddedEvent;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Schreibt beim Hinzufügen einer Position die Werte aller registrierten Eingabe-Provider in ihren
 * Payload. Der Subscriber kennt TMMS nicht; weitere Quellen docken über den Tag
 * `rc_cart_splitter.input_provider` an, ohne dass er sich ändert.
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
            // Priorität 100: Der Payload steht, bevor Listener mit Standardpriorität die Position lesen.
            BeforeLineItemAddedEvent::class => ['onBeforeLineItemAdded', 100],
        ];
    }

    public function onBeforeLineItemAdded(BeforeLineItemAddedEvent $event): void
    {
        // Das Ereignis feuert nach `Cart::add()`. Lag die Position schon im Warenkorb, steckt die
        // Menge in der vorhandenen Position; nur ein Payload dort kommt in der Berechnung an.
        $lineItem = $event->getCart()->get($event->getLineItem()->getId()) ?? $event->getLineItem();

        // Nur Produktpositionen erreichen die Provider. Sie schlagen Kundeneingaben zum Artikel
        // nach, der Warenkorb trägt aber auch Gutschein-Platzhalter mit dem Code in
        // `referencedId`. Hielte ein Provider den Code für eine Produktkennung, risse eine
        // Ausnahme den ganzen Warenkorb-Zugang mit, und kein Gutschein wäre mehr einlösbar.
        //
        // Die Prüfung steht hier und nicht in den Providern: Eine Annahme, die jeder Provider
        // einzeln treffen müsste, trifft irgendwann einer nicht.
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
