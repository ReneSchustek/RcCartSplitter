<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter\Tests\Unit\Subscriber;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCartSplitter\Service\CartInputProviderInterface;
use Ruhrcoder\RcCartSplitter\Subscriber\CartInputCaptureSubscriber;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Event\BeforeLineItemAddedEvent;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

#[CoversClass(CartInputCaptureSubscriber::class)]
final class CartInputCaptureSubscriberTest extends TestCase
{
    #[Test]
    public function subscribesToBeforeLineItemAddedEvent(): void
    {
        self::assertArrayHasKey(
            BeforeLineItemAddedEvent::class,
            CartInputCaptureSubscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function appliesAllProviderValuesToLineItemPayload(): void
    {
        $providerA = $this->makeProvider(['keyA' => 'valueA']);
        $providerB = $this->makeProvider(['keyB' => 'valueB', 'keyC' => 42]);

        $subscriber = new CartInputCaptureSubscriber([$providerA, $providerB]);

        $lineItem = new LineItem('li-1', LineItem::PRODUCT_LINE_ITEM_TYPE);
        $event = $this->createEvent($lineItem);

        $subscriber->onBeforeLineItemAdded($event);

        self::assertSame('valueA', $lineItem->getPayloadValue('keyA'));
        self::assertSame('valueB', $lineItem->getPayloadValue('keyB'));
        self::assertSame(42, $lineItem->getPayloadValue('keyC'));
    }

    #[Test]
    public function ignoresEmptyProviderResults(): void
    {
        $subscriber = new CartInputCaptureSubscriber([$this->makeProvider([])]);

        $lineItem = new LineItem('li-1', LineItem::PRODUCT_LINE_ITEM_TYPE);
        $event = $this->createEvent($lineItem);

        $subscriber->onBeforeLineItemAdded($event);

        self::assertSame([], $lineItem->getPayload());
    }

    #[Test]
    public function laterProviderOverwritesEarlierForSameKey(): void
    {
        $providerA = $this->makeProvider(['key' => 'first']);
        $providerB = $this->makeProvider(['key' => 'second']);

        $subscriber = new CartInputCaptureSubscriber([$providerA, $providerB]);

        $lineItem = new LineItem('li-1', LineItem::PRODUCT_LINE_ITEM_TYPE);
        $subscriber->onBeforeLineItemAdded($this->createEvent($lineItem));

        // Reihenfolge der Provider definiert die Praezedenz — letzter gewinnt.
        self::assertSame('second', $lineItem->getPayloadValue('key'));
    }

    /** @param array<string, mixed> $values */
    private function makeProvider(array $values): CartInputProviderInterface
    {
        return new class ($values) implements CartInputProviderInterface {
            /** @param array<string, mixed> $values */
            public function __construct(private readonly array $values)
            {
            }

            public function provide(BeforeLineItemAddedEvent $event): array
            {
                return $this->values;
            }
        };
    }

    private function createEvent(LineItem $lineItem): BeforeLineItemAddedEvent
    {
        $cart = new Cart('test');
        $cart->setLineItems(new LineItemCollection([$lineItem]));

        return new BeforeLineItemAddedEvent(
            $lineItem,
            $cart,
            $this->createMock(SalesChannelContext::class),
        );
    }
}
