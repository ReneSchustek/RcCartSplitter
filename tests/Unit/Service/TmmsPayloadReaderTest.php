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

/**
 * Prüft, dass der Leser Kundeneingaben aus Request und Session gleich behandelt: Tags entfernt,
 * Länge gekappt, Nicht-Skalare und leere Werte verworfen, kaputte Strukturen ohne Ausnahme
 * übergangen. Ohne diese Prüfung landeten ungefilterte Eingaben im Warenkorb und in der Bestellung.
 */
#[CoversClass(TmmsPayloadReader::class)]
final class TmmsPayloadReaderTest extends TestCase
{
    private TmmsPayloadReader $reader;

    protected function setUp(): void
    {
        $this->reader = new TmmsPayloadReader();
    }

    #[Test]
    public function readRequestPayloadReturnsEmptyWhenNoLineItems(): void
    {
        $request = new Request();

        $result = $this->reader->readRequestPayload($request, 'product-123');

        self::assertSame([], $result);
    }

    /**
     * Die Store-API nimmt dieselben Positionen unter `items` entgegen. Ohne diesen Weg fiele die
     * Kundeneingabe über die Schnittstelle still aus: Nichts bricht ab, es kommt nur nichts an.
     * Der Test schlüsselt `items` nach Produktkennung; eine Liste mit fortlaufenden Indizes deckt er
     * nicht ab.
     */
    #[Test]
    public function readRequestPayloadReadsTheStoreApiParameterName(): void
    {
        $request = new Request(request: [
            'items' => [
                'product-123' => [
                    'payload' => [
                        TmmsConstants::PAYLOAD_TMMS_ACTIVE => '1',
                        TmmsConstants::payloadValueKey(1) => 'Wert aus der Schnittstelle',
                    ],
                ],
            ],
        ]);

        $result = $this->reader->readRequestPayload($request, 'product-123');

        self::assertNotSame([], $result, 'Über die Store-API muss die Eingabe ankommen.');
        self::assertContains('Wert aus der Schnittstelle', $result);
    }

    /**
     * Ein skalarer Wert unter dem Parameternamen darf keine 400 erzeugen — `all()` mit Schlüssel
     * würfe dort eine BadRequestException.
     */
    #[Test]
    public function readRequestPayloadSurvivesAScalarParameter(): void
    {
        $request = new Request(request: ['lineItems' => 'kaputt', 'items' => 'auch kaputt']);

        self::assertSame([], $this->reader->readRequestPayload($request, 'product-123'));
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
                        TmmsConstants::payloadLabelKey(1) => ' Länge ',
                        TmmsConstants::payloadValueKey(2) => 'rot',
                        TmmsConstants::payloadLabelKey(2) => 'Farbe',
                    ],
                ],
            ],
        ]);

        $result = $this->reader->readRequestPayload($request, 'product-123');

        self::assertSame('1', $result[TmmsConstants::PAYLOAD_TMMS_ACTIVE]);
        self::assertSame('100cm', $result[TmmsConstants::payloadValueKey(1)]);
        self::assertSame('Länge', $result[TmmsConstants::payloadLabelKey(1)]);
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
                        TmmsConstants::payloadLabelKey(1) => 'Länge',
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
                        TmmsConstants::payloadLabelKey(1) => '<b>Länge</b>',
                    ],
                ],
            ],
        ]);

        $result = $this->reader->readRequestPayload($request, 'product-123');

        self::assertSame('alert("xss")100cm', $result[TmmsConstants::payloadValueKey(1)]);
        self::assertSame('Länge', $result[TmmsConstants::payloadLabelKey(1)]);
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
                        TmmsConstants::payloadLabelKey(1) => 'Länge',
                    ],
                ],
            ],
        ]);

        $result = $this->reader->readRequestPayload($request, 'product-123');

        self::assertArrayHasKey(TmmsConstants::payloadValueKey(1), $result);
        // Gekappt wird bei MAX_VALUE_LENGTH (2000).
        self::assertSame(2000, mb_strlen($result[TmmsConstants::payloadValueKey(1)]));
    }

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
                TmmsConstants::SESSION_LABEL_KEY => 'Länge',
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
        // Fehlende Schlüssel werden zum leeren Text, nicht zu null.
        self::assertSame('', $result[2][TmmsConstants::SESSION_PLACEHOLDER_KEY]);
        self::assertSame('', $result[2][TmmsConstants::SESSION_FIELDTYPE_KEY]);
    }

    #[Test]
    public function readSessionDataSkipsEmptyValues(): void
    {
        $session = $this->buildSession([
            TmmsConstants::sessionKey(1, 'SW10001') => [
                TmmsConstants::SESSION_VALUE_KEY => '',
                TmmsConstants::SESSION_LABEL_KEY => 'Länge',
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
        // TMMS legt die Eingabe ungefiltert in der Session ab. Dieselbe Bereinigung wie im
        // Request-Weg hält Markup und Überlängen aus Payload und Bestellung.
        $session = $this->buildSession([
            TmmsConstants::sessionKey(1, 'SW10001') => [
                TmmsConstants::SESSION_VALUE_KEY => '<script>alert("xss")</script>100cm',
                TmmsConstants::SESSION_LABEL_KEY => '<b>Länge</b>',
                TmmsConstants::SESSION_PLACEHOLDER_KEY => '<i>z.B. 100cm</i>',
                TmmsConstants::SESSION_FIELDTYPE_KEY => 'text',
            ],
        ]);

        $result = $this->reader->readSessionData($session, 'SW10001');

        self::assertSame('alert("xss")100cm', $result[1][TmmsConstants::SESSION_VALUE_KEY]);
        self::assertSame('Länge', $result[1][TmmsConstants::SESSION_LABEL_KEY]);
        self::assertSame('z.B. 100cm', $result[1][TmmsConstants::SESSION_PLACEHOLDER_KEY]);
        self::assertSame('text', $result[1][TmmsConstants::SESSION_FIELDTYPE_KEY]);
    }

    #[Test]
    public function readSessionDataCapsOverlongValues(): void
    {
        $session = $this->buildSession([
            TmmsConstants::sessionKey(1, 'SW10001') => [
                TmmsConstants::SESSION_VALUE_KEY => str_repeat('a', 5000),
                TmmsConstants::SESSION_LABEL_KEY => 'Länge',
            ],
        ]);

        $result = $this->reader->readSessionData($session, 'SW10001');

        // Auch der Session-Weg kappt bei MAX_VALUE_LENGTH (2000).
        self::assertSame(2000, mb_strlen($result[1][TmmsConstants::SESSION_VALUE_KEY]));
    }

    #[Test]
    public function readSessionDataIgnoresNonStringFieldValues(): void
    {
        // Ein Array als Wert gilt als leer, das Feld fällt weg.
        $session = $this->buildSession([
            TmmsConstants::sessionKey(1, 'SW10001') => [
                TmmsConstants::SESSION_VALUE_KEY => ['nested' => 'array'],
                TmmsConstants::SESSION_LABEL_KEY => 'Länge',
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
