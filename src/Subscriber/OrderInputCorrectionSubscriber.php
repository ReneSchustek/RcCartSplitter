<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter\Subscriber;

use Ruhrcoder\RcCartSplitter\Service\OrderInputCorrectorInterface;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Storefront\Page\Checkout\Finish\CheckoutFinishPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Stößt nach dem Bestellabschluss die Korrektur der TMMS-Eingaben in den Bestellpositionen an.
 *
 * TMMS schreibt die Session-Werte bei beiden Ereignissen in die custom_fields aller Positionen
 * eines Artikels. Die eigentliche Korrektur liegt im OrderInputCorrectionService.
 */
final class OrderInputCorrectionSubscriber implements EventSubscriberInterface
{
    /** @param EntityRepository<OrderLineItemCollection> $orderLineItemRepository */
    public function __construct(
        private readonly EntityRepository $orderLineItemRepository,
        private readonly OrderInputCorrectorInterface $correctionService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // TMMS schreibt bei beiden Ereignissen mit Priorität 0. -500 lässt die Korrektur nach TMMS
        // und nach anderen Listenern mit Standardpriorität laufen, sodass ihr Stand der letzte ist.
        return [
            CheckoutOrderPlacedEvent::class => ['onOrderPlaced', -500],
            CheckoutFinishPageLoadedEvent::class => ['onCheckoutFinish', -500],
        ];
    }

    public function onOrderPlaced(CheckoutOrderPlacedEvent $event): void
    {
        $order = $event->getOrder();
        $this->correctOrder($order->getId(), $event->getContext(), $order->getLineItems());
    }

    public function onCheckoutFinish(CheckoutFinishPageLoadedEvent $event): void
    {
        $order = $event->getPage()->getOrder();
        $this->correctOrder($order->getId(), $event->getSalesChannelContext()->getContext(), $order->getLineItems());
    }

    private function correctOrder(string $orderId, Context $context, ?OrderLineItemCollection $memoryItems): void
    {
        // Die Korrektur arbeitet auf dem gespeicherten Stand mit den custom_fields, die TMMS gerade
        // geschrieben hat. Die Positionen am Ereignis können fehlen, `getLineItems()` darf null sein.
        $freshItems = $this->loadLineItemsFromDb($orderId, $context);

        if ($freshItems->count() === 0) {
            return;
        }

        $this->correctionService->correctLineItems($freshItems, $memoryItems);
    }

    private function loadLineItemsFromDb(string $orderId, Context $context): OrderLineItemCollection
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderId', $orderId));
        // Obergrenze, damit eine B2B-Bestellung mit Tausenden Positionen den Checkout nicht
        // ausbremst. 500 liegt weit über dem, was eine Bestellung mit Kundeneingaben realistisch
        // umfasst; was darüber liegt, bleibt unkorrigiert.
        $criteria->setLimit(500);

        /** @var OrderLineItemCollection $collection */
        $collection = $this->orderLineItemRepository->search($criteria, $context)->getEntities();

        return $collection;
    }
}
