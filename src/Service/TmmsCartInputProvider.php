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

        return [TmmsConstants::PAYLOAD_TMMS_INPUTS => $sessionInputs];
    }

    private function fetchProductNumber(string $productId): ?string
    {
        try {
            $productNumber = $this->connection->fetchOne(
                'SELECT product_number FROM product WHERE id = :id LIMIT 1',
                ['id' => Uuid::fromHexToBytes($productId)],
            );
        } catch (DbalException $e) {
            // DB-Hiccup darf AddToCart nicht killen, aber wir wollen den Fall sehen
            $this->logger->warning('TMMS-Cart-Provider konnte product_number nicht laden', [
                'productId' => $productId,
                'exception' => $e,
            ]);
            return null;
        }

        return is_string($productNumber) && $productNumber !== '' ? $productNumber : null;
    }
}
