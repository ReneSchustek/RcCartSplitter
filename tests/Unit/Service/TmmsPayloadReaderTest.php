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
                        self::payloadValueKey(1) => 'Wert',
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
                        self::payloadValueKey(1) => ' 100cm ',
                        self::payloadLabelKey(1) => ' Laenge ',
                        self::payloadValueKey(2) => 'rot',
                        self::payloadLabelKey(2) => 'Farbe',
                    ],
                ],
            ],
        ]);

        $result = $this->reader->readRequestPayload($request, 'product-123');

        self::assertSame('1', $result[TmmsConstants::PAYLOAD_TMMS_ACTIVE]);
        self::assertSame('100cm', $result[self::payloadValueKey(1)]);
        self::assertSame('Laenge', $result[self::payloadLabelKey(1)]);
        self::assertSame('rot', $result[self::payloadValueKey(2)]);
        self::assertSame('Farbe', $result[self::payloadLabelKey(2)]);
    }

    #[Test]
    public function readRequestPayloadSkipsEmptyValues(): void
    {
        $request = new Request(request: [
            'lineItems' => [
                'product-123' => [
                    'payload' => [
                        TmmsConstants::PAYLOAD_TMMS_ACTIVE => '1',
                        self::payloadValueKey(1) => '100cm',
                        self::payloadLabelKey(1) => 'Laenge',
                        self::payloadValueKey(2) => '',
                        self::payloadLabelKey(2) => 'Leer',
                    ],
                ],
            ],
        ]);

        $result = $this->reader->readRequestPayload($request, 'product-123');

        self::assertArrayHasKey(self::payloadValueKey(1), $result);
        self::assertArrayNotHasKey(self::payloadValueKey(2), $result);
        self::assertArrayNotHasKey(self::payloadLabelKey(2), $result);
    }

    #[Test]
    public function readRequestPayloadStripsHtmlTags(): void
    {
        $request = new Request(request: [
            'lineItems' => [
                'product-123' => [
                    'payload' => [
                        TmmsConstants::PAYLOAD_TMMS_ACTIVE => '1',
                        self::payloadValueKey(1) => '<script>alert("xss")</script>100cm',
                        self::payloadLabelKey(1) => '<b>Laenge</b>',
                    ],
                ],
            ],
        ]);

        $result = $this->reader->readRequestPayload($request, 'product-123');

        self::assertSame('alert("xss")100cm', $result[self::payloadValueKey(1)]);
        self::assertSame('Laenge', $result[self::payloadLabelKey(1)]);
    }

    #[Test]
    public function readRequestPayloadReturnsEmptyForWrongProductId(): void
    {
        $request = new Request(request: [
            'lineItems' => [
                'product-999' => [
                    'payload' => [
                        TmmsConstants::PAYLOAD_TMMS_ACTIVE => '1',
                        self::payloadValueKey(1) => '100cm',
                    ],
                ],
            ],
        ]);

        $result = $this->reader->readRequestPayload($request, 'product-123');

        self::assertSame([], $result);
    }

    #[Test]
    public function readRequestPayloadReturnsEmptyWhenItemDataNotArray(): void
    {
        // Manipulierter Request: lineItems[productId] ist String statt Array
        $request = new Request(request: [
            'lineItems' => [
                'product-123' => 'manipulated-string',
            ],
        ]);

        $result = $this->reader->readRequestPayload($request, 'product-123');

        self::assertSame([], $result);
    }

    #[Test]
    public function readRequestPayloadReturnsEmptyWhenPayloadNotArray(): void
    {
        $request = new Request(request: [
            'lineItems' => [
                'product-123' => [
                    'payload' => 'not-an-array',
                ],
            ],
        ]);

        $result = $this->reader->readRequestPayload($request, 'product-123');

        self::assertSame([], $result);
    }

    #[Test]
    public function readRequestPayloadIgnoresNonScalarFieldValues(): void
    {
        $request = new Request(request: [
            'lineItems' => [
                'product-123' => [
                    'payload' => [
                        TmmsConstants::PAYLOAD_TMMS_ACTIVE => '1',
                        self::payloadValueKey(1) => ['nested' => 'array'],
                        self::payloadValueKey(2) => '50cm',
                        self::payloadLabelKey(2) => ['also' => 'array'],
                    ],
                ],
            ],
        ]);

        $result = $this->reader->readRequestPayload($request, 'product-123');

        self::assertArrayNotHasKey(self::payloadValueKey(1), $result);
        self::assertSame('50cm', $result[self::payloadValueKey(2)]);
        self::assertSame('', $result[self::payloadLabelKey(2)]);
    }

    #[Test]
    public function readRequestPayloadCapsOverlongValues(): void
    {
        $longValue = str_repeat('a', 5000);
        $request = new Request(request: [
            'lineItems' => [
                'product-123' => [
                    'payload' => [
                        TmmsConstants::PAYLOAD_TMMS_ACTIVE => '1',
                        self::payloadValueKey(1) => $longValue,
                        self::payloadLabelKey(1) => 'Laenge',
                    ],
                ],
            ],
        ]);

        $result = $this->reader->readRequestPayload($request, 'product-123');

        self::assertArrayHasKey(self::payloadValueKey(1), $result);
        // Sanitization-Layer kappt bei MAX_VALUE_LENGTH (2000)
        self::assertSame(2000, mb_strlen($result[self::payloadValueKey(1)]));
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
            self::sessionKey(1, 'SW10001') => [
                TmmsConstants::SESSION_VALUE_KEY => '100cm',
                TmmsConstants::SESSION_LABEL_KEY => 'Laenge',
                TmmsConstants::SESSION_PLACEHOLDER_KEY => 'z.B. 100cm',
                TmmsConstants::SESSION_FIELDTYPE_KEY => 'text',
            ],
            self::sessionKey(2, 'SW10001') => [
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
            self::sessionKey(1, 'SW10001') => [
                TmmsConstants::SESSION_VALUE_KEY => '',
                TmmsConstants::SESSION_LABEL_KEY => 'Laenge',
            ],
            self::sessionKey(2, 'SW10001') => [
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

    private static function payloadValueKey(int $i): string
    {
        return TmmsConstants::PAYLOAD_FIELD_PREFIX . $i . TmmsConstants::PAYLOAD_FIELD_VALUE_SUFFIX;
    }

    private static function payloadLabelKey(int $i): string
    {
        return TmmsConstants::PAYLOAD_FIELD_PREFIX . $i . TmmsConstants::PAYLOAD_FIELD_LABEL_SUFFIX;
    }

    private static function sessionKey(int $i, string $productNumber): string
    {
        return TmmsConstants::SESSION_KEY_PREFIX . $i . '_' . $productNumber;
    }
}
