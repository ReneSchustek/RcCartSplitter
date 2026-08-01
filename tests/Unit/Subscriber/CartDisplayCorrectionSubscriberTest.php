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
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPage;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;
use Symfony\Component\HttpFoundation\Request;

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
        $lineItem->setPayloadValue(TmmsConstants::PAYLOAD_TMMS_ACTIVE, '1');
        $lineItem->setPayloadValue(TmmsConstants::payloadValueKey(1), '100cm');

        $this->dispatch($lineItem);

        // Promotion-Items duerfen nicht beruehrt werden — TMMS-Logik ist produktspezifisch.
        self::assertFalse($lineItem->hasExtension(TmmsConstants::extensionName(1)));
    }

    #[Test]
    public function onCartPageLoadedSkipsItemsWithoutTmmsMarkerOrSessionFallback(): void
    {
        $lineItem = new LineItem('product-1', LineItem::PRODUCT_LINE_ITEM_TYPE);
        $lineItem->setPayloadValue('someOtherKey', 'value');

        // Eine vorhandene TMMS-Extension darf in diesem Fall stehen bleiben — wir korrigieren nur,
        // wenn unser Plugin Daten beigesteuert hat.
        $lineItem->addExtension(
            TmmsConstants::extensionName(1),
            new ArrayEntity(['value' => 'fremd']),
        );

        $this->dispatch($lineItem);

        $ext = $lineItem->getExtension(TmmsConstants::extensionName(1));
        self::assertInstanceOf(ArrayEntity::class, $ext);
        self::assertSame('fremd', $ext->get('value'));
    }

    #[Test]
    public function onCartPageLoadedSetsValueAndLabelExtensionFromPayload(): void
    {
        $lineItem = new LineItem('product-1', LineItem::PRODUCT_LINE_ITEM_TYPE);
        $lineItem->setPayloadValue(TmmsConstants::PAYLOAD_TMMS_ACTIVE, '1');
        $lineItem->setPayloadValue(TmmsConstants::payloadValueKey(1), '100cm');
        $lineItem->setPayloadValue(TmmsConstants::payloadLabelKey(1), 'Laenge');
        $lineItem->setPayloadValue(TmmsConstants::payloadValueKey(2), 'rechts');
        $lineItem->setPayloadValue(TmmsConstants::payloadLabelKey(2), 'Gehrungsschnitt');

        $this->dispatch($lineItem);

        $ext1 = $lineItem->getExtension(TmmsConstants::extensionName(1));
        self::assertInstanceOf(ArrayEntity::class, $ext1);
        self::assertSame('100cm', $ext1->get('value'));
        self::assertSame('Laenge', $ext1->get('label'));

        $ext2 = $lineItem->getExtension(TmmsConstants::extensionName(2));
        self::assertInstanceOf(ArrayEntity::class, $ext2);
        self::assertSame('rechts', $ext2->get('value'));
        self::assertSame('Gehrungsschnitt', $ext2->get('label'));
    }

    #[Test]
    public function onCartPageLoadedRemovesLeakedTmmsExtensionForEmptyPayloadField(): void
    {
        $lineItem = new LineItem('product-1', LineItem::PRODUCT_LINE_ITEM_TYPE);
        $lineItem->setPayloadValue(TmmsConstants::PAYLOAD_TMMS_ACTIVE, '1');
        $lineItem->setPayloadValue(TmmsConstants::payloadValueKey(1), '100cm');
        // Feld 2 nicht im Payload — Position hat dieses Feld nicht ausgefuellt.

        // TMMS hat zuvor aus der Session pro Produktnummer dieselbe Extension fuer alle
        // Split-Positionen geschrieben. Genau dieser Leak muss verschwinden, sonst zeigen
        // beide Positionen dasselbe Feld 2.
        $lineItem->addExtension(
            TmmsConstants::extensionName(2),
            new ArrayEntity(['value' => 'Session-Leak']),
        );

        $this->dispatch($lineItem);

        self::assertTrue($lineItem->hasExtension(TmmsConstants::extensionName(1)));
        self::assertFalse($lineItem->hasExtension(TmmsConstants::extensionName(2)));
    }

    #[Test]
    public function onCartPageLoadedHandlesLegacySessionFallbackWithoutTmmsActive(): void
    {
        // Alt-Carts (vor dem Provider-Fix) haben nur den Sammel-Key — Defense-in-Depth:
        // auch dieser Pfad muss die Anzeige pro Position korrigieren.
        $lineItem = new LineItem('product-1', LineItem::PRODUCT_LINE_ITEM_TYPE);
        $lineItem->setPayloadValue(TmmsConstants::PAYLOAD_TMMS_INPUTS, [
            1 => [
                TmmsConstants::SESSION_VALUE_KEY => '50cm',
                TmmsConstants::SESSION_LABEL_KEY => 'Laenge',
            ],
            2 => [
                TmmsConstants::SESSION_VALUE_KEY => 'links',
                TmmsConstants::SESSION_LABEL_KEY => 'Gehrungsschnitt',
            ],
        ]);

        $this->dispatch($lineItem);

        $ext1 = $lineItem->getExtension(TmmsConstants::extensionName(1));
        self::assertInstanceOf(ArrayEntity::class, $ext1);
        self::assertSame('50cm', $ext1->get('value'));
        self::assertSame('Laenge', $ext1->get('label'));

        $ext2 = $lineItem->getExtension(TmmsConstants::extensionName(2));
        self::assertInstanceOf(ArrayEntity::class, $ext2);
        self::assertSame('links', $ext2->get('value'));
        self::assertSame('Gehrungsschnitt', $ext2->get('label'));
    }

    #[Test]
    public function onCartPageLoadedDoesNotRemoveExtensionsInLegacySessionFallback(): void
    {
        // Ohne rcTmmsActive duerfen wir keine Extensions entfernen — wir wissen nicht,
        // ob der Payload autoritativ ist. Defensive Variante: nur ergaenzen.
        $lineItem = new LineItem('product-1', LineItem::PRODUCT_LINE_ITEM_TYPE);
        $lineItem->setPayloadValue(TmmsConstants::PAYLOAD_TMMS_INPUTS, [
            1 => [
                TmmsConstants::SESSION_VALUE_KEY => '50cm',
                TmmsConstants::SESSION_LABEL_KEY => 'Laenge',
            ],
        ]);
        $lineItem->addExtension(
            TmmsConstants::extensionName(2),
            new ArrayEntity(['value' => 'TMMS-Originalwert']),
        );

        $this->dispatch($lineItem);

        self::assertTrue($lineItem->hasExtension(TmmsConstants::extensionName(2)));
    }

    private function dispatch(LineItem $lineItem): void
    {
        $this->subscriber->onCartPageLoaded($this->createCartPageEvent(new LineItemCollection([$lineItem])));
    }

    private function createCartPageEvent(LineItemCollection $lineItems): CheckoutCartPageLoadedEvent
    {
        $cart = new Cart('test-cart');
        $cart->setLineItems($lineItems);

        $page = new CheckoutCartPage();
        $page->setCart($cart);

        return new CheckoutCartPageLoadedEvent(
            $page,
            $this->createMock(SalesChannelContext::class),
            new Request(),
        );
    }
}
