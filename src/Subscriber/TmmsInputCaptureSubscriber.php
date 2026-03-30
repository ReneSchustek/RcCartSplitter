<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter\Subscriber;

use Ruhrcoder\RcCartSplitter\TmmsConstants;
use Shopware\Core\Checkout\Cart\Event\BeforeLineItemAddedEvent;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

final class TmmsInputCaptureSubscriber implements EventSubscriberInterface
{
    /** @param EntityRepository<ProductCollection> $productRepository */
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly EntityRepository $productRepository,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            BeforeLineItemAddedEvent::class => ['onBeforeLineItemAdded', 100],
        ];
    }

    /**
     * Liest die TMMS-Kundeneingaben und speichert sie im LineItem-Payload.
     *
     * Quelle 1 (bevorzugt): Hidden-Felder aus dem Request-Payload (vom JS injiziert)
     * Quelle 2 (Fallback): TMMS-Session-Daten
     */
    public function onBeforeLineItemAdded(BeforeLineItemAddedEvent $event): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return;
        }

        $lineItem = $event->getCart()->get($event->getLineItem()->getId()) ?? $event->getLineItem();
        $productId = $lineItem->getReferencedId();
        if ($productId === null) {
            return;
        }

        // Quelle 1: Hidden-Felder aus dem Request-Payload
        $requestInputs = $this->readRequestPayload($request, $productId);
        if ($requestInputs !== []) {
            foreach ($requestInputs as $key => $value) {
                $lineItem->setPayloadValue($key, $value);
            }

            return;
        }

        // Quelle 2: Fallback auf TMMS-Session-Daten
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session === null) {
            return;
        }

        $productNumber = $this->getProductNumber($productId, $event->getSalesChannelContext()->getContext());
        if ($productNumber === null) {
            return;
        }

        $inputs = $this->readTmmsSessionData($session, $productNumber);
        if ($inputs === []) {
            return;
        }

        $lineItem->setPayloadValue(TmmsConstants::PAYLOAD_TMMS_INPUTS, $inputs);
    }

    /**
     * Liest rcTmmsField*-Werte aus dem Request-Payload (vom JS als Hidden-Felder injiziert).
     *
     * @return array<string, string>
     */
    private function readRequestPayload(Request $request, string $productId): array
    {
        /** @var array<string, array<string, mixed>> $lineItems */
        $lineItems = $request->request->all('lineItems');

        $itemData = $lineItems[$productId] ?? [];
        /** @var array<string, string> $payload */
        $payload = $itemData['payload'] ?? [];

        if (!isset($payload[TmmsConstants::PAYLOAD_TMMS_ACTIVE])) {
            return [];
        }

        $result = [TmmsConstants::PAYLOAD_TMMS_ACTIVE => '1'];

        for ($i = 1; $i <= TmmsConstants::INPUT_COUNT; $i++) {
            $valueKey = TmmsConstants::PAYLOAD_FIELD_PREFIX . $i . TmmsConstants::PAYLOAD_FIELD_VALUE_SUFFIX;
            $labelKey = TmmsConstants::PAYLOAD_FIELD_PREFIX . $i . TmmsConstants::PAYLOAD_FIELD_LABEL_SUFFIX;

            $value = isset($payload[$valueKey]) ? trim((string) $payload[$valueKey]) : '';
            if ($value === '') {
                continue;
            }

            $result[$valueKey] = strip_tags($value);
            $result[$labelKey] = strip_tags(trim((string) ($payload[$labelKey] ?? '')));
        }

        return $result;
    }

    private function getProductNumber(string $productId, Context $context): ?string
    {
        $criteria = new Criteria([$productId]);

        $product = $this->productRepository->search($criteria, $context)->first();

        return $product instanceof ProductEntity ? $product->getProductNumber() : null;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function readTmmsSessionData(SessionInterface $session, string $productNumber): array
    {
        $inputs = [];

        for ($i = 1; $i <= TmmsConstants::INPUT_COUNT; $i++) {
            $key = TmmsConstants::SESSION_KEY_PREFIX . $i . '_' . $productNumber;

            if (!$session->has($key)) {
                continue;
            }

            /** @var array<string, string> $data */
            $data = $session->get($key, []);
            $value = $data[TmmsConstants::SESSION_VALUE_KEY] ?? '';

            if ($value === '') {
                continue;
            }

            $inputs[$i] = $data;
        }

        return $inputs;
    }
}
