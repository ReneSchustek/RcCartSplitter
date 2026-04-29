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

// Laeuft nach TMMS, weil TMMS sonst alle Split-Positionen mit den gleichen Session-Daten ueberschreibt;
// die eigentliche Korrektur liegt im OrderInputCorrectionService.
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
        // Beide Events: TMMS schreibt sowohl bei OrderPlaced als auch bei FinishPageLoaded zurueck
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
