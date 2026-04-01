<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter\Subscriber;

use Ruhrcoder\RcCartSplitter\Service\OrderInputCorrectionService;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Storefront\Page\Checkout\Finish\CheckoutFinishPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Korrigiert die TMMS-Kundeneingaben pro Bestellposition.
 *
 * TMMS schreibt alle Positionen desselben Produkts mit den gleichen Session-Daten.
 * Dieser Subscriber läuft NACH TMMS und delegiert die Korrektur an den
 * OrderInputCorrectionService.
 */
final class OrderInputCorrectionSubscriber implements EventSubscriberInterface
{
    /** @param EntityRepository<OrderLineItemCollection> $orderLineItemRepository */
    public function __construct(
        private readonly EntityRepository $orderLineItemRepository,
        private readonly OrderInputCorrectionService $correctionService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Muss nach TMMS laufen (Priorität ~0), das alle Positionen
            // desselben Produkts mit den gleichen Session-Daten überschreibt.
            // Beide Events nötig: TMMS überschreibt sowohl beim OrderPlaced
            // als auch beim FinishPageLoaded die custom_fields erneut.
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

        /** @var OrderLineItemCollection $collection */
        $collection = $this->orderLineItemRepository->search($criteria, $context)->getEntities();

        return $collection;
    }
}
