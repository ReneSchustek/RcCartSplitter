<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter\Tests\Unit\Subscriber;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCartSplitter\Subscriber\CartDisplayCorrectionSubscriber;
use Ruhrcoder\RcCartSplitter\TmmsConstants;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\Framework\Struct\ArrayEntity;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPage;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;
use Symfony\Component\HttpFoundation\Request;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

#[CoversClass(CartDisplayCorrectionSubscriber::class)]
final class CartDisplayCorrectionSubscriberTest extends TestCase
{
    private CartDisplayCorrectionSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->subscriber = new CartDisplayCorrectionSubscriber();
    }

    #[Test]
    public function subscribedEventsContainsAllCartPageEvents(): void
    {
        $events = CartDisplayCorrectionSubscriber::getSubscribedEvents();

        self::assertArrayHasKey('Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent', $events);
        self::assertArrayHasKey('Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent', $events);
        self::assertArrayHasKey('Shopware\Storefront\Page\Checkout\Offcanvas\OffcanvasCartPageLoadedEvent', $events);
    }

    #[Test]
    public function onCartPageLoadedSkipsNonProductLineItems(): void
    {
        $lineItem = new LineItem('promo-1', LineItem::PROMOTION_LINE_ITEM_TYPE);

        $event = $this->createCartPageEvent(new LineItemCollection([$lineItem]));

        $this->subscriber->onCartPageLoaded($event);

        // Promotion-Items sollten keine Extensions erhalten
        self::assertFalse($lineItem->hasExtension('tmmsLineItemCustomerInput1'));
    }

    #[Test]
    public function onCartPageLoadedSkipsItemsWithoutTmmsActive(): void
    {
        $lineItem = new LineItem('product-1', LineItem::PRODUCT_LINE_ITEM_TYPE);
        $lineItem->setPayloadValue('someOtherKey', 'value');

        $event = $this->createCartPageEvent(new LineItemCollection([$lineItem]));

        $this->subscriber->onCartPageLoaded($event);

        self::assertFalse($lineItem->hasExtension('tmmsLineItemCustomerInput1'));
    }

    #[Test]
    public function onCartPageLoadedSetsExtensionsForActiveItems(): void
    {
        $lineItem = new LineItem('product-1', LineItem::PRODUCT_LINE_ITEM_TYPE);
        $lineItem->setPayloadValue(TmmsConstants::PAYLOAD_TMMS_ACTIVE, '1');
        $lineItem->setPayloadValue('rcTmmsField1Value', '100cm');
        $lineItem->setPayloadValue('rcTmmsField2Value', 'rot');

        $event = $this->createCartPageEvent(new LineItemCollection([$lineItem]));

        $this->subscriber->onCartPageLoaded($event);

        $ext1 = $lineItem->getExtension('tmmsLineItemCustomerInput1');
        self::assertInstanceOf(ArrayEntity::class, $ext1);
        self::assertSame('100cm', $ext1->get('value'));

        $ext2 = $lineItem->getExtension('tmmsLineItemCustomerInput2');
        self::assertInstanceOf(ArrayEntity::class, $ext2);
        self::assertSame('rot', $ext2->get('value'));
    }

    #[Test]
    public function onCartPageLoadedSkipsEmptyValues(): void
    {
        $lineItem = new LineItem('product-1', LineItem::PRODUCT_LINE_ITEM_TYPE);
        $lineItem->setPayloadValue(TmmsConstants::PAYLOAD_TMMS_ACTIVE, '1');
        $lineItem->setPayloadValue('rcTmmsField1Value', '100cm');
        // Feld 2 nicht gesetzt → soll keine Extension erzeugen

        // Vorher eine TMMS-Extension setzen (simuliert TMMS-Subscriber)
        $lineItem->addExtension(
            'tmmsLineItemCustomerInput2',
            new ArrayEntity(['value' => 'TMMS-Originalwert']),
        );

        $event = $this->createCartPageEvent(new LineItemCollection([$lineItem]));

        $this->subscriber->onCartPageLoaded($event);

        // Feld 1 wurde korrigiert
        $ext1 = $lineItem->getExtension('tmmsLineItemCustomerInput1');
        self::assertInstanceOf(ArrayEntity::class, $ext1);
        self::assertSame('100cm', $ext1->get('value'));

        // Feld 2: TMMS-Originalwert bleibt erhalten (nicht überschrieben)
        $ext2 = $lineItem->getExtension('tmmsLineItemCustomerInput2');
        self::assertInstanceOf(ArrayEntity::class, $ext2);
        self::assertSame('TMMS-Originalwert', $ext2->get('value'));
    }

    private function createCartPageEvent(LineItemCollection $lineItems): CheckoutCartPageLoadedEvent
    {
        $cart = new Cart('test-cart');
        $cart->setLineItems($lineItems);

        $page = new CheckoutCartPage();
        $page->setCart($cart);

        $salesChannelContext = $this->createMock(SalesChannelContext::class);

        return new CheckoutCartPageLoadedEvent(
            $page,
            $salesChannelContext,
            new Request(),
        );
    }
}
