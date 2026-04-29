<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter\Tests\Unit\Subscriber;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCartSplitter\Service\OrderInputCorrectorInterface;
use Ruhrcoder\RcCartSplitter\Subscriber\OrderInputCorrectionSubscriber;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Checkout\Finish\CheckoutFinishPage;
use Shopware\Storefront\Page\Checkout\Finish\CheckoutFinishPageLoadedEvent;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(OrderInputCorrectionSubscriber::class)]
final class OrderInputCorrectionSubscriberTest extends TestCase
{
    private EntityRepository&MockObject $repository;
    private OrderInputCorrectorInterface&MockObject $correctionService;
    private OrderInputCorrectionSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(EntityRepository::class);
        $this->correctionService = $this->createMock(OrderInputCorrectorInterface::class);

        $this->subscriber = new OrderInputCorrectionSubscriber(
            $this->repository,
            $this->correctionService,
        );
    }

    #[Test]
    public function subscribesToOrderPlacedAndFinishEventsWithLowPriority(): void
    {
        $events = OrderInputCorrectionSubscriber::getSubscribedEvents();

        // Prio -500 ist Pflicht, damit TMMS nicht nach uns ueberschreibt
        self::assertSame(['onOrderPlaced', -500], $events[CheckoutOrderPlacedEvent::class]);
        self::assertSame(['onCheckoutFinish', -500], $events[CheckoutFinishPageLoadedEvent::class]);
    }

    #[Test]
    public function onOrderPlacedLoadsFreshItemsAndDelegatesToService(): void
    {
        $orderId = Uuid::randomHex();
        $memoryItems = new OrderLineItemCollection();
        $freshItems = new OrderLineItemCollection([$this->makeLineItem(Uuid::randomHex())]);

        $order = new OrderEntity();
        $order->setId($orderId);
        $order->setLineItems($memoryItems);

        $context = Context::createDefaultContext();
        $event = new CheckoutOrderPlacedEvent(
            $this->createSalesChannelContextStub($context),
            $order,
        );

        $this->expectRepositorySearch($orderId, $context, $freshItems);

        $this->correctionService
            ->expects(self::once())
            ->method('correctLineItems')
            ->with($freshItems, $memoryItems);

        $this->subscriber->onOrderPlaced($event);
    }

    #[Test]
    public function onCheckoutFinishLoadsFreshItemsAndDelegatesToService(): void
    {
        $orderId = Uuid::randomHex();
        $memoryItems = new OrderLineItemCollection();
        $freshItems = new OrderLineItemCollection([$this->makeLineItem(Uuid::randomHex())]);

        $order = new OrderEntity();
        $order->setId($orderId);
        $order->setLineItems($memoryItems);

        $page = new CheckoutFinishPage();
        $page->setOrder($order);

        $context = Context::createDefaultContext();
        $salesChannelContext = $this->createSalesChannelContextStub($context);

        $event = new CheckoutFinishPageLoadedEvent(
            $page,
            $salesChannelContext,
            new Request(),
        );

        $this->expectRepositorySearch($orderId, $context, $freshItems);

        $this->correctionService
            ->expects(self::once())
            ->method('correctLineItems')
            ->with($freshItems, $memoryItems);

        $this->subscriber->onCheckoutFinish($event);
    }

    #[Test]
    public function skipsServiceCallWhenNoLineItemsLoadedFromDb(): void
    {
        $orderId = Uuid::randomHex();
        $order = new OrderEntity();
        $order->setId($orderId);
        $order->setLineItems(new OrderLineItemCollection());

        $context = Context::createDefaultContext();
        $event = new CheckoutOrderPlacedEvent(
            $this->createSalesChannelContextStub($context),
            $order,
        );

        $this->expectRepositorySearch($orderId, $context, new OrderLineItemCollection());

        $this->correctionService
            ->expects(self::never())
            ->method('correctLineItems');

        $this->subscriber->onOrderPlaced($event);
    }

    private function expectRepositorySearch(
        string $orderId,
        Context $context,
        OrderLineItemCollection $result,
    ): void {
        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('getEntities')->willReturn($result);

        $this->repository
            ->expects(self::once())
            ->method('search')
            ->with(
                self::callback(static function (Criteria $criteria) use ($orderId): bool {
                    foreach ($criteria->getFilters() as $filter) {
                        if ($filter instanceof EqualsFilter
                            && $filter->getField() === 'orderId'
                            && $filter->getValue() === $orderId
                        ) {
                            return true;
                        }
                    }
                    return false;
                }),
                $context,
            )
            ->willReturn($searchResult);
    }

    private function createSalesChannelContextStub(Context $context): SalesChannelContext
    {
        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getContext')->willReturn($context);

        return $salesChannelContext;
    }

    private function makeLineItem(string $id): OrderLineItemEntity
    {
        $entity = new OrderLineItemEntity();
        $entity->setId($id);
        $entity->setUniqueIdentifier($id);

        return $entity;
    }
}
