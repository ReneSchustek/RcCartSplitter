<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Ruhrcoder\RcCartSplitter\Service\OrderInputCorrectionService;
use Ruhrcoder\RcCartSplitter\TmmsConstants;

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
            'rcTmmsField1Value' => '100cm',
            'rcTmmsField1Label' => 'Laenge',
            'rcTmmsField2Value' => 'rot',
            'rcTmmsField2Label' => 'Farbe',
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
            'rcTmmsField1Value' => '100cm',
            'rcTmmsField1Label' => 'Laenge',
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
            'rcTmmsField1Value' => '100cm',
            'rcTmmsField1Label' => 'Laenge',
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
}
