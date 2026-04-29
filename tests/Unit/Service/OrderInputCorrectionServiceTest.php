<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Ruhrcoder\RcCartSplitter\Service\OrderInputCorrectionService;
use Ruhrcoder\RcCartSplitter\TmmsConstants;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Framework\Uuid\Uuid;

#[CoversClass(OrderInputCorrectionService::class)]
final class OrderInputCorrectionServiceTest extends TestCase
{
    private OrderInputCorrectionService $service;
    private Connection&MockObject $connection;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new OrderInputCorrectionService(
            $this->connection,
            $this->logger,
        );
    }

    // --- correctLineItems (Batch-UPDATE + Transaktion + Error-Flow) ---

    #[Test]
    public function correctLineItemsDoesNothingWhenNoCorrections(): void
    {
        $lineItem = $this->createLineItem(Uuid::randomHex(), payload: []);
        $collection = new OrderLineItemCollection([$lineItem]);

        $this->connection->expects(self::never())->method('transactional');
        $this->connection->expects(self::never())->method('executeStatement');
        $this->logger->expects(self::never())->method('debug');

        $this->service->correctLineItems($collection, null);
    }

    #[Test]
    public function correctLineItemsRunsBatchUpdateInsideTransactionAndLogsAggregated(): void
    {
        $idA = Uuid::randomHex();
        $idB = Uuid::randomHex();

        $itemA = $this->createLineItem($idA, payload: [
            TmmsConstants::PAYLOAD_TMMS_ACTIVE => '1',
            self::payloadValueKey(1) => '100cm',
            self::payloadLabelKey(1) => 'Laenge',
        ]);
        $itemB = $this->createLineItem($idB, payload: [
            TmmsConstants::PAYLOAD_TMMS_ACTIVE => '1',
            self::payloadValueKey(1) => '200cm',
            self::payloadLabelKey(1) => 'Laenge',
        ]);

        $fresh = new OrderLineItemCollection([$itemA, $itemB]);

        // transactional ruft den Closure mit der Connection auf
        $this->connection
            ->expects(self::once())
            ->method('transactional')
            ->willReturnCallback(fn (\Closure $cb): mixed => $cb($this->connection));

        $capturedSql = null;
        $capturedParams = null;
        $this->connection
            ->expects(self::once())
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params = []) use (&$capturedSql, &$capturedParams): int {
                $capturedSql = $sql;
                $capturedParams = $params;
                return 2;
            });

        // Aggregierter Log-Eintrag, NICHT pro LineItem
        $this->logger
            ->expects(self::once())
            ->method('debug')
            ->with('TMMS-Eingaben korrigiert', ['count' => 2]);

        $this->service->correctLineItems($fresh, null);

        self::assertNotNull($capturedSql);
        self::assertStringContainsString('UPDATE order_line_item', $capturedSql);
        self::assertStringContainsString('CASE id', $capturedSql);
        self::assertStringContainsString('WHERE id IN', $capturedSql);
        self::assertCount(4, $capturedParams ?? [], 'Pro LineItem ein id- und ein cf-Parameter');

        // In-Memory-Update wurde durchgefuehrt
        self::assertSame('100cm', $itemA->getCustomFields()['tmms_customer_input_1_value'] ?? null);
        self::assertSame('200cm', $itemB->getCustomFields()['tmms_customer_input_1_value'] ?? null);
    }

    #[Test]
    public function correctLineItemsLogsErrorAndDoesNotRethrowOnDbalFailure(): void
    {
        $id = Uuid::randomHex();
        $item = $this->createLineItem($id, payload: [
            TmmsConstants::PAYLOAD_TMMS_ACTIVE => '1',
            self::payloadValueKey(1) => '100cm',
        ]);
        $fresh = new OrderLineItemCollection([$item]);

        $this->connection
            ->method('transactional')
            ->willThrowException($this->createMock(DbalException::class));

        // Fehler darf den Checkout nicht killen — error-Log statt throw
        $this->logger
            ->expects(self::once())
            ->method('error')
            ->with(
                'TMMS-Korrektur fehlgeschlagen',
                self::callback(fn (array $ctx): bool => $ctx['count'] === 1 && $ctx['lineItemIds'] === [$id]),
            );
        $this->logger->expects(self::never())->method('debug');

        $this->service->correctLineItems($fresh, null);

        // In-Memory wurde NICHT korrigiert (DB-Write fehlgeschlagen)
        self::assertSame([], $item->getCustomFields());
    }

    #[Test]
    public function correctLineItemsAlsoUpdatesMemoryItems(): void
    {
        $id = Uuid::randomHex();
        $fresh = $this->createLineItem($id, payload: [
            TmmsConstants::PAYLOAD_TMMS_ACTIVE => '1',
            self::payloadValueKey(1) => '100cm',
        ]);
        $memory = $this->createLineItem($id, payload: []);

        $freshCol = new OrderLineItemCollection([$fresh]);
        $memoryCol = new OrderLineItemCollection([$memory]);

        $this->connection
            ->method('transactional')
            ->willReturnCallback(fn (\Closure $cb): mixed => $cb($this->connection));
        $this->connection->method('executeStatement')->willReturn(1);

        $this->service->correctLineItems($freshCol, $memoryCol);

        self::assertSame('100cm', $memory->getCustomFields()['tmms_customer_input_1_value'] ?? null);
    }

    // --- buildFromPayloadKeys ---

    #[Test]
    public function buildFromPayloadKeysReturnsNullWhenTmmsNotActive(): void
    {
        $result = $this->service->buildFromPayloadKeys([], []);

        self::assertNull($result);
    }

    #[Test]
    public function buildFromPayloadKeysMapsAllFields(): void
    {
        $payload = [
            TmmsConstants::PAYLOAD_TMMS_ACTIVE => '1',
            self::payloadValueKey(1) => '100cm',
            self::payloadLabelKey(1) => 'Laenge',
            self::payloadValueKey(2) => 'rot',
            self::payloadLabelKey(2) => 'Farbe',
        ];

        $result = $this->service->buildFromPayloadKeys($payload, []);

        self::assertNotNull($result);
        self::assertSame('100cm', $result['tmms_customer_input_1_value']);
        self::assertSame('Laenge', $result['tmms_customer_input_1_label']);
        self::assertSame('rot', $result['tmms_customer_input_2_value']);
        self::assertSame('Farbe', $result['tmms_customer_input_2_label']);
    }

    #[Test]
    public function buildFromPayloadKeysPreservesExistingCustomFields(): void
    {
        $payload = [
            TmmsConstants::PAYLOAD_TMMS_ACTIVE => '1',
            self::payloadValueKey(1) => '100cm',
            self::payloadLabelKey(1) => 'Laenge',
        ];

        $existingFields = ['some_other_field' => 'value'];

        $result = $this->service->buildFromPayloadKeys($payload, $existingFields);

        self::assertNotNull($result);
        self::assertSame('value', $result['some_other_field']);
        self::assertSame('100cm', $result['tmms_customer_input_1_value']);
    }

    #[Test]
    public function buildFromPayloadKeysSetsEmptyStringForMissingFields(): void
    {
        $payload = [
            TmmsConstants::PAYLOAD_TMMS_ACTIVE => '1',
            self::payloadValueKey(1) => '100cm',
            self::payloadLabelKey(1) => 'Laenge',
        ];

        $result = $this->service->buildFromPayloadKeys($payload, []);

        self::assertNotNull($result);
        // Feld 2-5 sollten leer sein
        self::assertSame('', $result['tmms_customer_input_2_value']);
        self::assertSame('', $result['tmms_customer_input_2_label']);
        self::assertSame('', $result['tmms_customer_input_5_value']);
    }

    // --- buildFromSessionData ---

    #[Test]
    public function buildFromSessionDataReturnsNullWhenNoInputs(): void
    {
        $result = $this->service->buildFromSessionData([], []);

        self::assertNull($result);
    }

    #[Test]
    public function buildFromSessionDataReturnsNullWhenInputsEmpty(): void
    {
        $payload = [TmmsConstants::PAYLOAD_TMMS_INPUTS => []];

        $result = $this->service->buildFromSessionData($payload, []);

        self::assertNull($result);
    }

    #[Test]
    public function buildFromSessionDataReturnsNullWhenInputsNotArray(): void
    {
        $payload = [TmmsConstants::PAYLOAD_TMMS_INPUTS => 'invalid'];

        $result = $this->service->buildFromSessionData($payload, []);

        self::assertNull($result);
    }

    #[Test]
    public function buildFromSessionDataMapsAllSessionFields(): void
    {
        $payload = [
            TmmsConstants::PAYLOAD_TMMS_INPUTS => [
                1 => [
                    TmmsConstants::SESSION_VALUE_KEY => '100cm',
                    TmmsConstants::SESSION_LABEL_KEY => 'Laenge',
                    TmmsConstants::SESSION_PLACEHOLDER_KEY => 'z.B. 100cm',
                    TmmsConstants::SESSION_FIELDTYPE_KEY => 'text',
                ],
                3 => [
                    TmmsConstants::SESSION_VALUE_KEY => 'rot',
                    TmmsConstants::SESSION_LABEL_KEY => 'Farbe',
                    TmmsConstants::SESSION_PLACEHOLDER_KEY => '',
                    TmmsConstants::SESSION_FIELDTYPE_KEY => 'select',
                ],
            ],
        ];

        $result = $this->service->buildFromSessionData($payload, []);

        self::assertNotNull($result);
        self::assertSame('100cm', $result['tmms_customer_input_1_value']);
        self::assertSame('Laenge', $result['tmms_customer_input_1_label']);
        self::assertSame('z.B. 100cm', $result['tmms_customer_input_1_placeholder']);
        self::assertSame('text', $result['tmms_customer_input_1_fieldtype']);
        self::assertSame('rot', $result['tmms_customer_input_3_value']);
        self::assertSame('select', $result['tmms_customer_input_3_fieldtype']);
    }

    #[Test]
    public function buildFromSessionDataPreservesExistingCustomFields(): void
    {
        $payload = [
            TmmsConstants::PAYLOAD_TMMS_INPUTS => [
                1 => [
                    TmmsConstants::SESSION_VALUE_KEY => '100cm',
                    TmmsConstants::SESSION_LABEL_KEY => 'Laenge',
                ],
            ],
        ];

        $existingFields = ['existing_key' => 'existing_value'];

        $result = $this->service->buildFromSessionData($payload, $existingFields);

        self::assertNotNull($result);
        self::assertSame('existing_value', $result['existing_key']);
        self::assertSame('100cm', $result['tmms_customer_input_1_value']);
    }

    #[Test]
    public function buildFromSessionDataUsesEmptyStringForMissingKeys(): void
    {
        $payload = [
            TmmsConstants::PAYLOAD_TMMS_INPUTS => [
                1 => [],
            ],
        ];

        $result = $this->service->buildFromSessionData($payload, []);

        self::assertNotNull($result);
        self::assertSame('', $result['tmms_customer_input_1_value']);
        self::assertSame('', $result['tmms_customer_input_1_label']);
    }

    /** @param array<string, mixed> $payload */
    private function createLineItem(string $id, array $payload): OrderLineItemEntity
    {
        $entity = new OrderLineItemEntity();
        $entity->setId($id);
        $entity->setUniqueIdentifier($id);
        $entity->setPayload($payload);
        $entity->setCustomFields([]);

        return $entity;
    }

    private static function payloadValueKey(int $i): string
    {
        return TmmsConstants::PAYLOAD_FIELD_PREFIX . $i . TmmsConstants::PAYLOAD_FIELD_VALUE_SUFFIX;
    }

    private static function payloadLabelKey(int $i): string
    {
        return TmmsConstants::PAYLOAD_FIELD_PREFIX . $i . TmmsConstants::PAYLOAD_FIELD_LABEL_SUFFIX;
    }
}
