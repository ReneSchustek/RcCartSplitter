<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter\Tests\Integration\Subscriber;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCartSplitter\Service\OrderInputCorrectionService;
use Ruhrcoder\RcCartSplitter\Subscriber\OrderInputCorrectionSubscriber;
use Ruhrcoder\RcCartSplitter\TmmsConstants;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Test\Integration\Traits\OrderFixture;
use Shopware\Storefront\Page\Checkout\Finish\CheckoutFinishPage;
use Shopware\Storefront\Page\Checkout\Finish\CheckoutFinishPageLoadedEvent;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(OrderInputCorrectionSubscriber::class)]
#[CoversClass(OrderInputCorrectionService::class)]
final class OrderInputCorrectionSubscriberTest extends TestCase
{
    use IntegrationTestBehaviour;
    use OrderFixture;

    protected function setUp(): void
    {
        // Plattform-Bootstrap nur in CI vorhanden — lokal ueberspringen
        if (!class_exists(\Shopware\Core\Kernel::class) || !getenv('KERNEL_CLASS')) {
            self::markTestSkipped('Shopware-Kernel nicht verfuegbar — Integration-Test laeuft nur in der Plattform-Test-Umgebung.');
        }
    }

    #[Test]
    public function checkoutOrderPlacedCorrectsCustomFieldsForActiveLineItemsOnly(): void
    {
        $orderId = Uuid::randomHex();
        $itemWithPayloadId = Uuid::randomHex();
        $itemWithoutPayloadId = Uuid::randomHex();

        $this->insertOrderWithTwoLineItems(
            $orderId,
            $itemWithPayloadId,
            $itemWithoutPayloadId,
        );

        $this->getSubscriber()->onOrderPlaced(
            $this->buildOrderPlacedEvent($orderId),
        );

        $activeFields = $this->fetchCustomFields($itemWithPayloadId);
        self::assertNotNull($activeFields);
        self::assertSame('1190', $activeFields['tmms_customer_input_1_value'] ?? null);
        self::assertSame('Laenge', $activeFields['tmms_customer_input_1_label'] ?? null);

        $passiveFields = $this->fetchCustomFields($itemWithoutPayloadId);
        self::assertNull(
            $passiveFields,
            'LineItem ohne rcTmmsActive darf nicht angefasst werden.',
        );
    }

    #[Test]
    public function splitLineItemsOfSameProductReceiveDistinctCustomFields(): void
    {
        $orderId = Uuid::randomHex();
        $firstSplitId = Uuid::randomHex();
        $secondSplitId = Uuid::randomHex();

        $this->insertOrderWithTwoLineItems(
            $orderId,
            $firstSplitId,
            $secondSplitId,
            payloadForSecond: [
                TmmsConstants::PAYLOAD_TMMS_ACTIVE => '1',
                'rcTmmsField1Value' => '1195',
                'rcTmmsField1Label' => 'Laenge',
            ],
        );

        $this->getSubscriber()->onOrderPlaced(
            $this->buildOrderPlacedEvent($orderId),
        );

        $first = $this->fetchCustomFields($firstSplitId);
        $second = $this->fetchCustomFields($secondSplitId);

        self::assertSame('1190', $first['tmms_customer_input_1_value'] ?? null);
        self::assertSame('1195', $second['tmms_customer_input_1_value'] ?? null);
    }

    #[Test]
    public function checkoutFinishPageLoadedCorrectsCustomFields(): void
    {
        $orderId = Uuid::randomHex();
        $itemId = Uuid::randomHex();

        $this->insertOrderWithTwoLineItems(
            $orderId,
            $itemId,
            Uuid::randomHex(),
        );

        $context = Context::createDefaultContext();
        $order = $this->loadOrder($orderId, $context);

        $page = new CheckoutFinishPage();
        $page->setOrder($order);

        $event = new CheckoutFinishPageLoadedEvent(
            $page,
            $this->createSalesChannelContextStub($context),
            new Request(),
        );

        $this->getSubscriber()->onCheckoutFinish($event);

        $fields = $this->fetchCustomFields($itemId);
        self::assertSame('1190', $fields['tmms_customer_input_1_value'] ?? null);
    }

    /** @param array<string, mixed>|null $payloadForSecond */
    private function insertOrderWithTwoLineItems(
        string $orderId,
        string $firstLineItemId,
        string $secondLineItemId,
        ?array $payloadForSecond = null,
    ): void {
        $context = Context::createDefaultContext();
        $orderData = $this->getOrderData($orderId, $context)[0];

        // Erstes LineItem: ueberschreiben mit unserer ID + TMMS-Payload
        $orderData['lineItems'][0]['id'] = $firstLineItemId;
        $orderData['lineItems'][0]['payload'] = [
            TmmsConstants::PAYLOAD_TMMS_ACTIVE => '1',
            'rcTmmsField1Value' => '1190',
            'rcTmmsField1Label' => 'Laenge',
        ];

        // Zweites LineItem: gleicher Datensatz, andere ID, optional ohne Payload
        $secondItem = $orderData['lineItems'][0];
        $secondItem['id'] = $secondLineItemId;
        $secondItem['identifier'] = 'test-2';
        $secondItem['payload'] = $payloadForSecond ?? [];
        $orderData['lineItems'][] = $secondItem;

        // Delivery-Position muss auf das erste LineItem zeigen — ID auch dort durchziehen
        $orderData['deliveries'][0]['positions'][0]['orderLineItemId'] = $firstLineItemId;
        $orderData['deliveries'][0]['positions'][0]['price'] = new CalculatedPrice(
            10,
            10,
            new CalculatedTaxCollection(),
            new TaxRuleCollection(),
        );

        $orderData['lineItems'][0]['priceDefinition'] = new QuantityPriceDefinition(10, new TaxRuleCollection());
        $orderData['lineItems'][1]['priceDefinition'] = new QuantityPriceDefinition(10, new TaxRuleCollection());

        /** @var EntityRepository<OrderCollection> $orderRepository */
        $orderRepository = static::getContainer()->get('order.repository');
        $orderRepository->create([$orderData], $context);
    }

    private function buildOrderPlacedEvent(string $orderId): CheckoutOrderPlacedEvent
    {
        $context = Context::createDefaultContext();
        $order = $this->loadOrder($orderId, $context);

        return new CheckoutOrderPlacedEvent(
            $this->createSalesChannelContextStub($context),
            $order,
        );
    }

    private function loadOrder(string $orderId, Context $context): OrderEntity
    {
        /** @var EntityRepository<OrderCollection> $orderRepository */
        $orderRepository = static::getContainer()->get('order.repository');

        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('lineItems');

        $order = $orderRepository->search($criteria, $context)->getEntities()->first();
        self::assertInstanceOf(OrderEntity::class, $order);
        self::assertInstanceOf(OrderLineItemCollection::class, $order->getLineItems());

        return $order;
    }

    /** @return array<string, mixed>|null */
    private function fetchCustomFields(string $lineItemId): ?array
    {
        $connection = static::getContainer()->get(Connection::class);
        $raw = $connection->fetchOne(
            'SELECT custom_fields FROM order_line_item WHERE id = :id',
            ['id' => Uuid::fromHexToBytes($lineItemId)],
        );

        if ($raw === false || $raw === null || $raw === '') {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $raw, true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function getSubscriber(): OrderInputCorrectionSubscriber
    {
        $subscriber = static::getContainer()->get(OrderInputCorrectionSubscriber::class);
        self::assertInstanceOf(OrderInputCorrectionSubscriber::class, $subscriber);

        return $subscriber;
    }

    private function createSalesChannelContextStub(Context $context): SalesChannelContext
    {
        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getContext')->willReturn($context);

        return $salesChannelContext;
    }
}
