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
use Psr\Log\NullLogger;
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

/**
 * Prüft, woher der TMMS-Provider die Eingaben nimmt: zuerst aus dem Request, sonst aus der
 * Session über die Produktnummer. Außerdem, dass er bei fehlender Produktnummer, fremden Kennungen
 * und Datenbankfehlern leer zurückkommt, statt zu werfen.
 *
 * Der Provider hängt im Add-to-Cart-Weg. Eine Ausnahme dort bricht das Hinzufügen ab, ein
 * falscher Vorrang zeigte getrennten Positionen wieder dieselben Session-Werte.
 */
#[CoversClass(TmmsCartInputProvider::class)]
final class TmmsCartInputProviderTest extends TestCase
{
    private RequestStack $requestStack;
    private Connection&MockObject $connection;
    private TmmsPayloadReader $payloadReader;
    private LoggerInterface $logger;
    private TmmsCartInputProvider $provider;

    protected function setUp(): void
    {
        $this->requestStack = new RequestStack();
        $this->connection = $this->createMock(Connection::class);
        $this->payloadReader = new TmmsPayloadReader();
        // Kein Test prüft die Warnung bei Datenbankfehlern; der NullLogger nimmt sie auf.
        $this->logger = new NullLogger();

        $this->provider = new TmmsCartInputProvider(
            $this->requestStack,
            $this->connection,
            $this->payloadReader,
            $this->logger,
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
                        TmmsConstants::payloadValueKey(1) => '100cm',
                        TmmsConstants::payloadLabelKey(1) => 'Länge',
                    ],
                ],
            ],
        ]);
        $this->requestStack->push($request);

        $lineItem = $this->createLineItem($productId);
        $event = $this->createEvent($productId, $lineItem);

        // Reicht der Request, wird die Datenbank nicht gefragt.
        $this->connection->expects(self::never())->method('fetchOne');

        $result = $this->provider->provide($event);

        self::assertSame('1', $result[TmmsConstants::PAYLOAD_TMMS_ACTIVE]);
        self::assertSame('100cm', $result[TmmsConstants::payloadValueKey(1)]);
        self::assertSame('Länge', $result[TmmsConstants::payloadLabelKey(1)]);
    }

    #[Test]
    public function provideFallsBackToSessionWhenRequestPayloadEmpty(): void
    {
        $productHexId = Uuid::randomHex();
        $request = new Request();
        $session = new Session(new MockArraySessionStorage());
        $session->set(TmmsConstants::sessionKey(1, 'SW10001'), [
            TmmsConstants::SESSION_VALUE_KEY => '50cm',
            TmmsConstants::SESSION_LABEL_KEY => 'Länge',
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

        // Der Session-Weg liefert dieselbe Form wie das Storefront-Skript: rcTmmsActive und
        // Einzelfelder. Sonst sähen Twig-Template und Anzeigekorrektur die Werte nicht, und alle
        // getrennten Positionen zeigten denselben Session-Wert.
        self::assertSame('1', $result[TmmsConstants::PAYLOAD_TMMS_ACTIVE]);
        self::assertSame('50cm', $result[TmmsConstants::payloadValueKey(1)]);
        self::assertSame('Länge', $result[TmmsConstants::payloadLabelKey(1)]);

        // Der Sammelschlüssel steht zusätzlich im Ergebnis.
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

        // Ein Datenbankfehler darf das Hinzufügen nicht abbrechen: leere Antwort, keine Ausnahme.
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

    /**
     * Ein Gutschein-Platzhalter trägt in `referencedId` den Code, keine UUID
     * (Kern: `PromotionItemBuilder::buildPlaceholderItem()`). Gelangte "Sommer2026" an
     * `Uuid::fromHexToBytes()`, stiege eine `InvalidUuidException` bis in den Storefront-Controller,
     * und kein Gutscheincode wäre mehr einlösbar.
     *
     * Der Request hier hat keine Session, der Provider kehrt deshalb schon vor der UUID-Prüfung
     * zurück. Den Weg mit Session deckt provideDoesNotThrowWhenReferencedIdIsNoUuid ab; die
     * Typprüfung, die Gutscheine gar nicht erst zum Provider lässt, sitzt im CartInputCaptureSubscriber.
     */
    #[Test]
    public function provideIgnoresPromotionPlaceholderWithNonUuidReferencedId(): void
    {
        $lineItem = new LineItem('promotion-1', LineItem::PROMOTION_LINE_ITEM_TYPE);
        $lineItem->setReferencedId('Sommer2026');

        $this->requestStack->push(new Request());
        $this->connection->expects(self::never())->method('fetchOne');

        self::assertSame([], $this->provider->provide($this->createEvent('promotion-1', $lineItem)));
    }

    /**
     * Auch eine Produktposition kann eine `referencedId` tragen, die keine UUID ist, etwa aus
     * einem fremden Plugin mit eigener Kennung. Der Provider darf darauf nicht mit einer Ausnahme
     * reagieren, denn er hängt im Add-to-Cart-Weg.
     */
    #[Test]
    public function provideDoesNotThrowWhenReferencedIdIsNoUuid(): void
    {
        $lineItem = $this->createLineItem('kein-uuid-wert');

        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $this->requestStack->push($request);

        $this->connection->expects(self::never())->method('fetchOne');

        self::assertSame([], $this->provider->provide($this->createEvent('kein-uuid-wert', $lineItem)));
    }

    /**
     * Der `catch` fängt jede Ausnahme, nicht nur `DbalException`; ein Fehler beim Nachschlagen
     * darf das Hinzufügen nicht abbrechen. Stellvertretend steht hier eine `RuntimeException`.
     */
    #[Test]
    public function provideSurvivesAnyDatabaseFailureNotJustDbalExceptions(): void
    {
        $productId = Uuid::randomHex();
        $lineItem = $this->createLineItem($productId);

        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $this->requestStack->push($request);

        $this->connection->method('fetchOne')->willThrowException(new \RuntimeException('Verbindung weg'));

        self::assertSame([], $this->provider->provide($this->createEvent($productId, $lineItem)));
    }

    /**
     * Gegenprobe zu den Tests mit fremden Kennungen: Eine gültige Produktkennung mit Marker im
     * Request liefert weiterhin Werte. Eine zu scharfe Prüfung schaltete sonst unbemerkt den
     * eigentlichen Zweck des Plugins ab. Der Request-Weg erreicht die UUID-Prüfung dabei nicht.
     */
    #[Test]
    public function provideStillReadsTheProductPathAfterTheGuard(): void
    {
        $productId = Uuid::randomHex();
        $lineItem = $this->createLineItem($productId);

        $request = new Request();
        $request->request->set('lineItems', [
            $productId => ['payload' => [TmmsConstants::PAYLOAD_TMMS_ACTIVE => '1']],
        ]);
        $this->requestStack->push($request);

        $ergebnis = $this->provider->provide($this->createEvent($productId, $lineItem));

        self::assertNotSame([], $ergebnis, 'Der Produktpfad muss weiterhin Werte liefern.');
    }
}
