<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCartSplitter\Service\TmmsCartInputProvider;
use Ruhrcoder\RcCartSplitter\Service\TmmsPayloadReader;
use Ruhrcoder\RcCartSplitter\TmmsConstants;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Event\BeforeLineItemAddedEvent;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

#[CoversClass(TmmsCartInputProvider::class)]
final class TmmsCartInputProviderTest extends TestCase
{
    private RequestStack $requestStack;
    private Connection&MockObject $connection;
    private TmmsPayloadReader $payloadReader;
    private TmmsCartInputProvider $provider;

    protected function setUp(): void
    {
        $this->requestStack = new RequestStack();
        $this->connection = $this->createMock(Connection::class);
        $this->payloadReader = new TmmsPayloadReader();

        $this->provider = new TmmsCartInputProvider(
            $this->requestStack,
            $this->connection,
            $this->payloadReader,
        );
    }

    #[Test]
    public function provideReturnsEmptyWhenNoRequest(): void
    {
        $event = $this->createEvent('product-1', $this->createLineItem('product-1'));

        self::assertSame([], $this->provider->provide($event));
    }

    #[Test]
    public function provideReturnsEmptyWhenLineItemHasNoReferencedId(): void
    {
        $this->requestStack->push(new Request());
        $lineItem = new LineItem('li-1', LineItem::PRODUCT_LINE_ITEM_TYPE);
        // kein referencedId
        $event = $this->createEvent('li-1', $lineItem);

        self::assertSame([], $this->provider->provide($event));
    }

    #[Test]
    public function providePrefersRequestPayloadOverSession(): void
    {
        $productId = 'product-123';
        $request = new Request(request: [
            'lineItems' => [
                $productId => [
                    'payload' => [
                        TmmsConstants::PAYLOAD_TMMS_ACTIVE => '1',
                        'rcTmmsField1Value' => '100cm',
                        'rcTmmsField1Label' => 'Laenge',
                    ],
                ],
            ],
        ]);
        $this->requestStack->push($request);

        $lineItem = $this->createLineItem($productId);
        $event = $this->createEvent($productId, $lineItem);

        // Session/Connection duerfen NICHT gefragt werden, wenn Request-Payload reicht
        $this->connection->expects(self::never())->method('fetchOne');

        $result = $this->provider->provide($event);

        self::assertSame('1', $result[TmmsConstants::PAYLOAD_TMMS_ACTIVE]);
        self::assertSame('100cm', $result['rcTmmsField1Value']);
        self::assertSame('Laenge', $result['rcTmmsField1Label']);
    }

    #[Test]
    public function provideFallsBackToSessionWhenRequestPayloadEmpty(): void
    {
        $productHexId = Uuid::randomHex();
        $request = new Request();
        $session = new Session(new MockArraySessionStorage());
        $session->set('tmms_customer_input_1_SW10001', [
            TmmsConstants::SESSION_VALUE_KEY => '50cm',
            TmmsConstants::SESSION_LABEL_KEY => 'Laenge',
        ]);
        $request->setSession($session);
        $this->requestStack->push($request);

        $lineItem = $this->createLineItem($productHexId);
        $event = $this->createEvent($productHexId, $lineItem);

        $this->connection
            ->expects(self::once())
            ->method('fetchOne')
            ->with(
                self::stringContains('SELECT product_number FROM product WHERE id'),
                ['id' => Uuid::fromHexToBytes($productHexId)],
            )
            ->willReturn('SW10001');

        $result = $this->provider->provide($event);

        self::assertArrayHasKey(TmmsConstants::PAYLOAD_TMMS_INPUTS, $result);
        $inputs = $result[TmmsConstants::PAYLOAD_TMMS_INPUTS];
        self::assertSame('50cm', $inputs[1][TmmsConstants::SESSION_VALUE_KEY]);
    }

    #[Test]
    public function provideReturnsEmptyWhenProductNumberNotFound(): void
    {
        $productHexId = Uuid::randomHex();
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $this->requestStack->push($request);

        $event = $this->createEvent($productHexId, $this->createLineItem($productHexId));

        $this->connection->method('fetchOne')->willReturn(false);

        self::assertSame([], $this->provider->provide($event));
    }

    #[Test]
    public function provideReturnsEmptyOnDbalFailure(): void
    {
        $productHexId = Uuid::randomHex();
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $this->requestStack->push($request);

        $event = $this->createEvent($productHexId, $this->createLineItem($productHexId));

        $this->connection
            ->method('fetchOne')
            ->willThrowException($this->createMock(DbalException::class));

        // DB-Fehler darf AddToCart nicht killen — leere Antwort, kein Throw
        self::assertSame([], $this->provider->provide($event));
    }

    private function createLineItem(string $referencedId): LineItem
    {
        $lineItem = new LineItem($referencedId, LineItem::PRODUCT_LINE_ITEM_TYPE);
        $lineItem->setReferencedId($referencedId);

        return $lineItem;
    }

    private function createEvent(string $cartLineItemKey, LineItem $lineItem): BeforeLineItemAddedEvent
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
