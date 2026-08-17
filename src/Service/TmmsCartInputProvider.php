<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Psr\Log\LoggerInterface;
use Ruhrcoder\RcCartSplitter\TmmsConstants;
use Shopware\Core\Checkout\Cart\Event\BeforeLineItemAddedEvent;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\HttpFoundation\RequestStack;

// Bevorzugt JS-Payload; Session-Fallback liest productNumber per Native-SQL,
// um teure ProductEntity-Hydration pro AddToCart zu vermeiden.
final class TmmsCartInputProvider implements CartInputProviderInterface
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly Connection $connection,
        private readonly TmmsPayloadReader $payloadReader,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function provide(BeforeLineItemAddedEvent $event): array
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return [];
        }

        $lineItem = $event->getCart()->get($event->getLineItem()->getId()) ?? $event->getLineItem();
        $productId = $lineItem->getReferencedId();
        if ($productId === null) {
            return [];
        }

        $requestInputs = $this->payloadReader->readRequestPayload($request, $productId);
        if ($requestInputs !== []) {
            return $requestInputs;
        }

        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session === null) {
            return [];
        }

        $productNumber = $this->fetchProductNumber($productId);
        if ($productNumber === null) {
            return [];
        }

        $sessionInputs = $this->payloadReader->readSessionData($session, $productNumber);
        if ($sessionInputs === []) {
            return [];
        }

        return $this->buildUnifiedFromSession($sessionInputs);
    }

    /**
     * Session-Fallback muss dieselbe Payload-Form wie der JS-Pfad erzeugen.
     * Sonst sehen Twig-Template (`rcTmmsField<N>Value`) und Display-Korrektur die Daten
     * nicht und alle Split-Positionen eines Produktes zeigen dieselben Session-Werte.
     *
     * @param array<int, array<string, string>> $sessionInputs
     * @return array<string, mixed>
     */
    private function buildUnifiedFromSession(array $sessionInputs): array
    {
        $unified = [TmmsConstants::PAYLOAD_TMMS_ACTIVE => '1'];

        foreach ($sessionInputs as $count => $data) {
            $unified[TmmsConstants::payloadValueKey($count)] = $data[TmmsConstants::SESSION_VALUE_KEY] ?? '';
            $unified[TmmsConstants::payloadLabelKey($count)] = $data[TmmsConstants::SESSION_LABEL_KEY] ?? '';
        }

        // Altbestellungen ohne rcTmmsActive nutzen weiterhin den Sammel-Key — Order-Korrektur bleibt kompatibel.
        $unified[TmmsConstants::PAYLOAD_TMMS_INPUTS] = $sessionInputs;

        return $unified;
    }

    private function fetchProductNumber(string $productId): ?string
    {
        // Nicht darauf verlassen, dass der Aufrufer eine UUID liefert.
        //
        // `Uuid::fromHexToBytes()` wirft bei allem, was keine ist. Ein Gutschein-Platzhalter
        // trägt in `referencedId` den **Code** statt einer Kennung — die Ausnahme stieg bis in
        // den Storefront-Controller, der sie generisch abfing. Ergebnis auf Live: Kein
        // Gutscheincode war mehr einlösbar, der Kunde sah "Leider ist etwas schiefgelaufen",
        // und im Protokoll stand nichts.
        //
        // Kein Protokolleintrag hier: Der Fall ist erwartbar, sobald ein anderes Plugin eine
        // eigene Kennung setzt. Eine Warnung je Warenkorb-Zugang wäre Rauschen.
        if (!Uuid::isValid($productId)) {
            return null;
        }

        try {
            $productNumber = $this->connection->fetchOne(
                'SELECT product_number FROM product WHERE id = :id LIMIT 1',
                ['id' => Uuid::fromHexToBytes($productId)],
            );
        } catch (\Throwable $error) {
            // Bewusst `\Throwable` und nicht nur `DbalException`: Der Vorsatz war immer "ein
            // Fehler darf den Warenkorb-Zugang nicht killen". Die engere Fassung hielt das
            // nicht — sie ließ genau die Ausnahme durch, die den Gutschein-Fehler auslöste.
            $this->logger->warning('TMMS-Cart-Provider konnte product_number nicht laden', [
                'productId' => $productId,
                'exception' => $error,
            ]);

            return null;
        }

        return is_string($productNumber) && $productNumber !== '' ? $productNumber : null;
    }
}
