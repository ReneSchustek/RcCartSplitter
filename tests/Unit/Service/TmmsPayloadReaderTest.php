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
                        TmmsConstants::payloadValueKey(1) => 'Wert',
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
                        TmmsConstants::payloadValueKey(1) => ' 100cm ',
                        TmmsConstants::payloadLabelKey(1) => ' Laenge ',
                        TmmsConstants::payloadValueKey(2) => 'rot',
                        TmmsConstants::payloadLabelKey(2) => 'Farbe',
                    ],
                ],
            ],
        ]);

        $result = $this->reader->readRequestPayload($request, 'product-123');

        self::assertSame('1', $result[TmmsConstants::PAYLOAD_TMMS_ACTIVE]);
        self::assertSame('100cm', $result[TmmsConstants::payloadValueKey(1)]);
        self::assertSame('Laenge', $result[TmmsConstants::payloadLabelKey(1)]);
        self::assertSame('rot', $result[TmmsConstants::payloadValueKey(2)]);
        self::assertSame('Farbe', $result[TmmsConstants::payloadLabelKey(2)]);
    }

    #[Test]
    public function readRequestPayloadSkipsEmptyValues(): void
    {
        $request = new Request(request: [
            'lineItems' => [
                'product-123' => [
                    'payload' => [
                        TmmsConstants::PAYLOAD_TMMS_ACTIVE => '1',
                        TmmsConstants::payloadValueKey(1) => '100cm',
                        TmmsConstants::payloadLabelKey(1) => 'Laenge',
                        TmmsConstants::payloadValueKey(2) => '',
                        TmmsConstants::payloadLabelKey(2) => 'Leer',
                    ],
                ],
            ],
        ]);

        $result = $this->reader->readRequestPayload($request, 'product-123');

        self::assertArrayHasKey(TmmsConstants::payloadValueKey(1), $result);
        self::assertArrayNotHasKey(TmmsConstants::payloadValueKey(2), $result);
        self::assertArrayNotHasKey(TmmsConstants::payloadLabelKey(2), $result);
    }

    #[Test]
    public function readRequestPayloadStripsHtmlTags(): void
    {
        $request = new Request(request: [
            'lineItems' => [
                'product-123' => [
                    'payload' => [
                        TmmsConstants::PAYLOAD_TMMS_ACTIVE => '1',
                        TmmsConstants::payloadValueKey(1) => '<script>alert("xss")</script>100cm',
                        TmmsConstants::payloadLabelKey(1) => '<b>Laenge</b>',
                    ],
                ],
            ],
        ]);

        $result = $this->reader->readRequestPayload($request, 'product-123');

        self::assertSame('alert("xss")100cm', $result[TmmsConstants::payloadValueKey(1)]);
        self::assertSame('Laenge', $result[TmmsConstants::payloadLabelKey(1)]);
    }

    #[Test]
    public function readRequestPayloadReturnsEmptyForWrongProductId(): void
    {
        $request = new Request(request: [
            'lineItems' => [
                'product-999' => [
                    'payload' => [
                        TmmsConstants::PAYLOAD_TMMS_ACTIVE => '1',
                        TmmsConstants::payloadValueKey(1) => '100cm',
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
                        TmmsConstants::payloadValueKey(1) => ['nested' => 'array'],
                        TmmsConstants::payloadValueKey(2) => '50cm',
                        TmmsConstants::payloadLabelKey(2) => ['also' => 'array'],
                    ],
                ],
            ],
        ]);

        $result = $this->reader->readRequestPayload($request, 'product-123');

        self::assertArrayNotHasKey(TmmsConstants::payloadValueKey(1), $result);
        self::assertSame('50cm', $result[TmmsConstants::payloadValueKey(2)]);
        self::assertSame('', $result[TmmsConstants::payloadLabelKey(2)]);
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
                        TmmsConstants::payloadValueKey(1) => $longValue,
                        TmmsConstants::payloadLabelKey(1) => 'Laenge',
                    ],
                ],
            ],
        ]);

        $result = $this->reader->readRequestPayload($request, 'product-123');

        self::assertArrayHasKey(TmmsConstants::payloadValueKey(1), $result);
        // Sanitization-Layer kappt bei MAX_VALUE_LENGTH (2000)
        self::assertSame(2000, mb_strlen($result[TmmsConstants::payloadValueKey(1)]));
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
        $session = $this->buildSession([
            TmmsConstants::sessionKey(1, 'SW10001') => [
                TmmsConstants::SESSION_VALUE_KEY => '100cm',
                TmmsConstants::SESSION_LABEL_KEY => 'Laenge',
                TmmsConstants::SESSION_PLACEHOLDER_KEY => 'z.B. 100cm',
                TmmsConstants::SESSION_FIELDTYPE_KEY => 'text',
            ],
            TmmsConstants::sessionKey(2, 'SW10001') => [
                TmmsConstants::SESSION_VALUE_KEY => 'rot',
                TmmsConstants::SESSION_LABEL_KEY => 'Farbe',
            ],
        ]);

        $result = $this->reader->readSessionData($session, 'SW10001');

        self::assertCount(2, $result);
        self::assertSame('100cm', $result[1][TmmsConstants::SESSION_VALUE_KEY]);
        self::assertSame('rot', $result[2][TmmsConstants::SESSION_VALUE_KEY]);
        // Sanitisierter Read-Pfad fuellt fehlende Keys mit '' — nicht mit null
        self::assertSame('', $result[2][TmmsConstants::SESSION_PLACEHOLDER_KEY]);
        self::assertSame('', $result[2][TmmsConstants::SESSION_FIELDTYPE_KEY]);
    }

    #[Test]
    public function readSessionDataSkipsEmptyValues(): void
    {
        $session = $this->buildSession([
            TmmsConstants::sessionKey(1, 'SW10001') => [
                TmmsConstants::SESSION_VALUE_KEY => '',
                TmmsConstants::SESSION_LABEL_KEY => 'Laenge',
            ],
            TmmsConstants::sessionKey(2, 'SW10001') => [
                TmmsConstants::SESSION_VALUE_KEY => 'rot',
                TmmsConstants::SESSION_LABEL_KEY => 'Farbe',
            ],
        ]);

        $result = $this->reader->readSessionData($session, 'SW10001');

        self::assertCount(1, $result);
        self::assertArrayNotHasKey(1, $result);
        self::assertArrayHasKey(2, $result);
    }

    #[Test]
    public function readSessionDataStripsHtmlTags(): void
    {
        // TMMS-Session enthaelt User-Input und ist nicht zwingend sanitisiert — gleiches
        // Sanitization-Profil wie der Request-Pfad verhindert stored-XSS und Payload-Bombs.
        $session = $this->buildSession([
            TmmsConstants::sessionKey(1, 'SW10001') => [
                TmmsConstants::SESSION_VALUE_KEY => '<script>alert("xss")</script>100cm',
                TmmsConstants::SESSION_LABEL_KEY => '<b>Laenge</b>',
                TmmsConstants::SESSION_PLACEHOLDER_KEY => '<i>z.B. 100cm</i>',
                TmmsConstants::SESSION_FIELDTYPE_KEY => 'text',
            ],
        ]);

        $result = $this->reader->readSessionData($session, 'SW10001');

        self::assertSame('alert("xss")100cm', $result[1][TmmsConstants::SESSION_VALUE_KEY]);
        self::assertSame('Laenge', $result[1][TmmsConstants::SESSION_LABEL_KEY]);
        self::assertSame('z.B. 100cm', $result[1][TmmsConstants::SESSION_PLACEHOLDER_KEY]);
        self::assertSame('text', $result[1][TmmsConstants::SESSION_FIELDTYPE_KEY]);
    }

    #[Test]
    public function readSessionDataCapsOverlongValues(): void
    {
        $session = $this->buildSession([
            TmmsConstants::sessionKey(1, 'SW10001') => [
                TmmsConstants::SESSION_VALUE_KEY => str_repeat('a', 5000),
                TmmsConstants::SESSION_LABEL_KEY => 'Laenge',
            ],
        ]);

        $result = $this->reader->readSessionData($session, 'SW10001');

        // Sanitization-Layer kappt auch im Session-Pfad bei MAX_VALUE_LENGTH (2000)
        self::assertSame(2000, mb_strlen($result[1][TmmsConstants::SESSION_VALUE_KEY]));
    }

    #[Test]
    public function readSessionDataIgnoresNonStringFieldValues(): void
    {
        // Manipulierte Session: Wert als Array → wird leer behandelt → Feld uebersprungen
        $session = $this->buildSession([
            TmmsConstants::sessionKey(1, 'SW10001') => [
                TmmsConstants::SESSION_VALUE_KEY => ['nested' => 'array'],
                TmmsConstants::SESSION_LABEL_KEY => 'Laenge',
            ],
            TmmsConstants::sessionKey(2, 'SW10001') => [
                TmmsConstants::SESSION_VALUE_KEY => 'rot',
                TmmsConstants::SESSION_LABEL_KEY => ['also' => 'array'],
            ],
        ]);

        $result = $this->reader->readSessionData($session, 'SW10001');

        self::assertArrayNotHasKey(1, $result);
        self::assertSame('rot', $result[2][TmmsConstants::SESSION_VALUE_KEY]);
        self::assertSame('', $result[2][TmmsConstants::SESSION_LABEL_KEY]);
    }

    /** @param array<string, array<string, mixed>> $sessionData */
    private function buildSession(array $sessionData): SessionInterface
    {
        $session = $this->createMock(SessionInterface::class);
        $session->method('has')->willReturnCallback(
            static fn (string $key): bool => isset($sessionData[$key])
        );
        $session->method('get')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => $sessionData[$key] ?? $default
        );

        return $session;
    }
}
