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

    // --- correctLineItems: Mapping aus Payload-Schluesseln ---

    #[Test]
    public function correctLineItemsWritesPayloadFieldsToCustomFields(): void
    {
        $payload = [
            TmmsConstants::PAYLOAD_TMMS_ACTIVE => '1',
            self::payloadValueKey(1) => '100cm',
            self::payloadLabelKey(1) => 'Laenge',
            self::payloadValueKey(2) => 'rot',
            self::payloadLabelKey(2) => 'Farbe',
        ];

        $customFields = $this->captureWrittenCustomFields($payload);

        self::assertNotNull($customFields);
        self::assertSame('100cm', $customFields['tmms_customer_input_1_value']);
        self::assertSame('Laenge', $customFields['tmms_customer_input_1_label']);
        self::assertSame('rot', $customFields['tmms_customer_input_2_value']);
        self::assertSame('Farbe', $customFields['tmms_customer_input_2_label']);
    }

    #[Test]
    public function correctLineItemsPreservesExistingCustomFieldsFromPayload(): void
    {
        $payload = [
            TmmsConstants::PAYLOAD_TMMS_ACTIVE => '1',
            self::payloadValueKey(1) => '100cm',
            self::payloadLabelKey(1) => 'Laenge',
        ];

        $customFields = $this->captureWrittenCustomFields($payload, ['some_other_field' => 'value']);

        self::assertNotNull($customFields);
        self::assertSame('value', $customFields['some_other_field']);
        self::assertSame('100cm', $customFields['tmms_customer_input_1_value']);
    }

    #[Test]
    public function correctLineItemsSetsEmptyStringForMissingPayloadFields(): void
    {
        $payload = [
            TmmsConstants::PAYLOAD_TMMS_ACTIVE => '1',
            self::payloadValueKey(1) => '100cm',
            self::payloadLabelKey(1) => 'Laenge',
        ];

        $customFields = $this->captureWrittenCustomFields($payload);

        self::assertNotNull($customFields);
        // Felder 2..INPUT_COUNT sind leer befuellt
        self::assertSame('', $customFields['tmms_customer_input_2_value']);
        self::assertSame('', $customFields['tmms_customer_input_2_label']);
        self::assertSame('', $customFields['tmms_customer_input_5_value']);
    }

    // --- correctLineItems: Mapping aus Session-Daten ---

    #[Test]
    public function correctLineItemsSkipsWhenSessionInputsEmptyArray(): void
    {
        $this->expectNoCorrection([TmmsConstants::PAYLOAD_TMMS_INPUTS => []]);
    }

    #[Test]
    public function correctLineItemsSkipsWhenSessionInputsNotArray(): void
    {
        $this->expectNoCorrection([TmmsConstants::PAYLOAD_TMMS_INPUTS => 'invalid']);
    }

    #[Test]
    public function correctLineItemsWritesSessionFieldsToCustomFields(): void
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

        $customFields = $this->captureWrittenCustomFields($payload);

        self::assertNotNull($customFields);
        self::assertSame('100cm', $customFields['tmms_customer_input_1_value']);
        self::assertSame('Laenge', $customFields['tmms_customer_input_1_label']);
        self::assertSame('z.B. 100cm', $customFields['tmms_customer_input_1_placeholder']);
        self::assertSame('text', $customFields['tmms_customer_input_1_fieldtype']);
        self::assertSame('rot', $customFields['tmms_customer_input_3_value']);
        self::assertSame('select', $customFields['tmms_customer_input_3_fieldtype']);
    }

    #[Test]
    public function correctLineItemsPreservesExistingCustomFieldsFromSession(): void
    {
        $payload = [
            TmmsConstants::PAYLOAD_TMMS_INPUTS => [
                1 => [
                    TmmsConstants::SESSION_VALUE_KEY => '100cm',
                    TmmsConstants::SESSION_LABEL_KEY => 'Laenge',
                ],
            ],
        ];

        $customFields = $this->captureWrittenCustomFields($payload, ['existing_key' => 'existing_value']);

        self::assertNotNull($customFields);
        self::assertSame('existing_value', $customFields['existing_key']);
        self::assertSame('100cm', $customFields['tmms_customer_input_1_value']);
    }

    #[Test]
    public function correctLineItemsUsesEmptyStringForMissingSessionKeys(): void
    {
        $payload = [
            TmmsConstants::PAYLOAD_TMMS_INPUTS => [
                1 => [],
            ],
        ];

        $customFields = $this->captureWrittenCustomFields($payload);

        self::assertNotNull($customFields);
        self::assertSame('', $customFields['tmms_customer_input_1_value']);
        self::assertSame('', $customFields['tmms_customer_input_1_label']);
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

    /**
     * Black-Box-Aufruf von correctLineItems mit einem Single-Item-Setup. Faengt das
     * via DBAL geschriebene customFields-JSON ab und gibt es decodiert zurueck.
     * Liefert null, wenn kein UPDATE stattfand (Service hat die Korrektur uebersprungen).
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $existingCustomFields
     * @return array<string, mixed>|null
     */
    private function captureWrittenCustomFields(array $payload, array $existingCustomFields = []): ?array
    {
        $hexId = Uuid::randomHex();
        $item = $this->createLineItem($hexId, payload: $payload);
        $item->setCustomFields($existingCustomFields);
        $fresh = new OrderLineItemCollection([$item]);

        $this->connection
            ->method('transactional')
            ->willReturnCallback(fn (\Closure $cb): mixed => $cb($this->connection));

        $captured = null;
        $this->connection
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params) use (&$captured): int {
                /** @var array<string, mixed> $decoded */
                $decoded = json_decode((string) $params['cf_0'], true, flags: \JSON_THROW_ON_ERROR);
                $captured = $decoded;
                return 1;
            });

        $this->service->correctLineItems($fresh, null);

        return $captured;
    }

    /**
     * Black-Box-Erwartung: correctLineItems fasst Connection nicht an, weil keine
     * Korrektur ausgeloest wurde.
     *
     * @param array<string, mixed> $payload
     */
    private function expectNoCorrection(array $payload): void
    {
        $hexId = Uuid::randomHex();
        $item = $this->createLineItem($hexId, payload: $payload);
        $fresh = new OrderLineItemCollection([$item]);

        $this->connection->expects(self::never())->method('transactional');
        $this->connection->expects(self::never())->method('executeStatement');

        $this->service->correctLineItems($fresh, null);
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
