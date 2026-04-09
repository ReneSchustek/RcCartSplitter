<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCartSplitter\Service\TmmsPayloadReader;
use Ruhrcoder\RcCartSplitter\TmmsConstants;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

#[CoversClass(TmmsPayloadReader::class)]
final class TmmsPayloadReaderTest extends TestCase
{
    private TmmsPayloadReader $reader;

    protected function setUp(): void
    {
        $this->reader = new TmmsPayloadReader();
    }

    // --- readRequestPayload ---

    #[Test]
    public function readRequestPayloadReturnsEmptyWhenNoLineItems(): void
    {
        $request = new Request();

        $result = $this->reader->readRequestPayload($request, 'product-123');

        self::assertSame([], $result);
    }

    #[Test]
    public function readRequestPayloadReturnsEmptyWhenTmmsNotActive(): void
    {
        $request = new Request(request: [
            'lineItems' => [
                'product-123' => [
                    'payload' => [
                        'rcTmmsField1Value' => 'Wert',
                    ],
                ],
            ],
        ]);

        $result = $this->reader->readRequestPayload($request, 'product-123');

        self::assertSame([], $result);
    }

    #[Test]
    public function readRequestPayloadExtractsFieldsCorrectly(): void
    {
        $request = new Request(request: [
            'lineItems' => [
                'product-123' => [
                    'payload' => [
                        TmmsConstants::PAYLOAD_TMMS_ACTIVE => '1',
                        'rcTmmsField1Value' => ' 100cm ',
                        'rcTmmsField1Label' => ' Laenge ',
                        'rcTmmsField2Value' => 'rot',
                        'rcTmmsField2Label' => 'Farbe',
                    ],
                ],
            ],
        ]);

        $result = $this->reader->readRequestPayload($request, 'product-123');

        self::assertSame('1', $result[TmmsConstants::PAYLOAD_TMMS_ACTIVE]);
        self::assertSame('100cm', $result['rcTmmsField1Value']);
        self::assertSame('Laenge', $result['rcTmmsField1Label']);
        self::assertSame('rot', $result['rcTmmsField2Value']);
        self::assertSame('Farbe', $result['rcTmmsField2Label']);
    }

    #[Test]
    public function readRequestPayloadSkipsEmptyValues(): void
    {
        $request = new Request(request: [
            'lineItems' => [
                'product-123' => [
                    'payload' => [
                        TmmsConstants::PAYLOAD_TMMS_ACTIVE => '1',
                        'rcTmmsField1Value' => '100cm',
                        'rcTmmsField1Label' => 'Laenge',
                        'rcTmmsField2Value' => '',
                        'rcTmmsField2Label' => 'Leer',
                    ],
                ],
            ],
        ]);

        $result = $this->reader->readRequestPayload($request, 'product-123');

        self::assertArrayHasKey('rcTmmsField1Value', $result);
        self::assertArrayNotHasKey('rcTmmsField2Value', $result);
        self::assertArrayNotHasKey('rcTmmsField2Label', $result);
    }

    #[Test]
    public function readRequestPayloadStripsHtmlTags(): void
    {
        $request = new Request(request: [
            'lineItems' => [
                'product-123' => [
                    'payload' => [
                        TmmsConstants::PAYLOAD_TMMS_ACTIVE => '1',
                        'rcTmmsField1Value' => '<script>alert("xss")</script>100cm',
                        'rcTmmsField1Label' => '<b>Laenge</b>',
                    ],
                ],
            ],
        ]);

        $result = $this->reader->readRequestPayload($request, 'product-123');

        self::assertSame('alert("xss")100cm', $result['rcTmmsField1Value']);
        self::assertSame('Laenge', $result['rcTmmsField1Label']);
    }

    #[Test]
    public function readRequestPayloadReturnsEmptyForWrongProductId(): void
    {
        $request = new Request(request: [
            'lineItems' => [
                'product-999' => [
                    'payload' => [
                        TmmsConstants::PAYLOAD_TMMS_ACTIVE => '1',
                        'rcTmmsField1Value' => '100cm',
                    ],
                ],
            ],
        ]);

        $result = $this->reader->readRequestPayload($request, 'product-123');

        self::assertSame([], $result);
    }

    // --- readSessionData ---

    #[Test]
    public function readSessionDataReturnsEmptyWhenNoSessionKeys(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->method('has')->willReturn(false);

        $result = $this->reader->readSessionData($session, 'SW10001');

        self::assertSame([], $result);
    }

    #[Test]
    public function readSessionDataExtractsFieldsCorrectly(): void
    {
        $sessionData = [
            'tmms_customer_input_1_SW10001' => [
                TmmsConstants::SESSION_VALUE_KEY => '100cm',
                TmmsConstants::SESSION_LABEL_KEY => 'Laenge',
                TmmsConstants::SESSION_PLACEHOLDER_KEY => 'z.B. 100cm',
                TmmsConstants::SESSION_FIELDTYPE_KEY => 'text',
            ],
            'tmms_customer_input_2_SW10001' => [
                TmmsConstants::SESSION_VALUE_KEY => 'rot',
                TmmsConstants::SESSION_LABEL_KEY => 'Farbe',
            ],
        ];

        $session = $this->createMock(SessionInterface::class);
        $session->method('has')->willReturnCallback(
            fn (string $key): bool => isset($sessionData[$key])
        );
        $session->method('get')->willReturnCallback(
            fn (string $key, mixed $default = null): mixed => $sessionData[$key] ?? $default
        );

        $result = $this->reader->readSessionData($session, 'SW10001');

        self::assertCount(2, $result);
        self::assertSame('100cm', $result[1][TmmsConstants::SESSION_VALUE_KEY]);
        self::assertSame('rot', $result[2][TmmsConstants::SESSION_VALUE_KEY]);
    }

    #[Test]
    public function readSessionDataSkipsEmptyValues(): void
    {
        $sessionData = [
            'tmms_customer_input_1_SW10001' => [
                TmmsConstants::SESSION_VALUE_KEY => '',
                TmmsConstants::SESSION_LABEL_KEY => 'Laenge',
            ],
            'tmms_customer_input_2_SW10001' => [
                TmmsConstants::SESSION_VALUE_KEY => 'rot',
                TmmsConstants::SESSION_LABEL_KEY => 'Farbe',
            ],
        ];

        $session = $this->createMock(SessionInterface::class);
        $session->method('has')->willReturnCallback(
            fn (string $key): bool => isset($sessionData[$key])
        );
        $session->method('get')->willReturnCallback(
            fn (string $key, mixed $default = null): mixed => $sessionData[$key] ?? $default
        );

        $result = $this->reader->readSessionData($session, 'SW10001');

        self::assertCount(1, $result);
        self::assertArrayNotHasKey(1, $result);
        self::assertArrayHasKey(2, $result);
    }
}
