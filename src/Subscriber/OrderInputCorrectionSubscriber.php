<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter\Subscriber;

use Ruhrcoder\RcCartSplitter\TmmsConstants;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Storefront\Page\Checkout\Finish\CheckoutFinishPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Korrigiert die TMMS-Kundeneingaben pro Bestellposition.
 *
 * TMMS schreibt alle Positionen desselben Produkts mit den gleichen Session-Daten,
 * sowohl bei CheckoutOrderPlaced als auch bei CheckoutFinishPageLoaded.
 * Dieser Subscriber läuft NACH TMMS auf beiden Events und überschreibt
 * die custom_fields mit den per-LineItem gesicherten Daten aus dem Payload.
 */
final class OrderInputCorrectionSubscriber implements EventSubscriberInterface
{
    /** @param EntityRepository<OrderLineItemCollection> $orderLineItemRepository */
    public function __construct(
        private readonly EntityRepository $orderLineItemRepository,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutOrderPlacedEvent::class => ['onOrderPlaced', -500],
            CheckoutFinishPageLoadedEvent::class => ['onCheckoutFinish', -500],
        ];
    }

    public function onOrderPlaced(CheckoutOrderPlacedEvent $event): void
    {
        $lineItems = $event->getOrder()->getLineItems();

        if ($lineItems === null) {
            return;
        }

        $this->correctLineItems($lineItems, $event->getContext());
    }

    public function onCheckoutFinish(CheckoutFinishPageLoadedEvent $event): void
    {
        $lineItems = $event->getPage()->getOrder()->getLineItems();

        if ($lineItems === null) {
            return;
        }

        $this->correctLineItems($lineItems, $event->getContext());
    }

    private function correctLineItems(OrderLineItemCollection $lineItems, \Shopware\Core\Framework\Context $context): void
    {
        $updates = [];

        foreach ($lineItems as $lineItem) {
            $corrected = $this->correctSingleItem($lineItem);

            if ($corrected === null) {
                continue;
            }

            $updates[] = [
                'id' => $lineItem->getId(),
                'customFields' => $corrected,
            ];

            // Auch das Entity-Objekt im Speicher korrigieren, damit nachfolgende
            // Subscriber oder Template-Rendering die richtigen Werte sehen
            $lineItem->setCustomFields($corrected);
        }

        if ($updates !== []) {
            $this->orderLineItemRepository->update($updates, $context);
        }
    }

    private function correctSingleItem(OrderLineItemEntity $lineItem): ?array
    {
        $payload = $lineItem->getPayload();

        // Quelle 1: Neue Payload-Keys (vom JS injiziert)
        $customFields = $this->buildFromPayloadKeys($payload, $lineItem->getCustomFields() ?? []);

        // Quelle 2: Fallback auf alte Session-basierte Daten
        if ($customFields === null) {
            $customFields = $this->buildFromSessionData($payload, $lineItem->getCustomFields() ?? []);
        }

        return $customFields;
    }

    /**
     * Liest aus den neuen rcTmmsField{i}Value/Label-Keys.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $customFields
     * @return array<string, mixed>|null
     */
    private function buildFromPayloadKeys(array $payload, array $customFields): ?array
    {
        if (!isset($payload[TmmsConstants::PAYLOAD_TMMS_ACTIVE])) {
            return null;
        }

        for ($i = 1; $i <= TmmsConstants::INPUT_COUNT; $i++) {
            $valueKey = TmmsConstants::PAYLOAD_FIELD_PREFIX . $i . TmmsConstants::PAYLOAD_FIELD_VALUE_SUFFIX;
            $labelKey = TmmsConstants::PAYLOAD_FIELD_PREFIX . $i . TmmsConstants::PAYLOAD_FIELD_LABEL_SUFFIX;

            $value = $payload[$valueKey] ?? '';

            $customFields['tmms_customer_input_' . $i . '_value'] = $value;
            $customFields['tmms_customer_input_' . $i . '_label'] = $payload[$labelKey] ?? '';
        }

        return $customFields;
    }

    /**
     * Fallback: Liest aus dem alten rc_tmms_inputs-Key (Session-basiert).
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $customFields
     * @return array<string, mixed>|null
     */
    private function buildFromSessionData(array $payload, array $customFields): ?array
    {
        $tmmsInputs = $payload[TmmsConstants::PAYLOAD_TMMS_INPUTS] ?? null;

        if (!is_array($tmmsInputs) || $tmmsInputs === []) {
            return null;
        }

        foreach ($tmmsInputs as $count => $data) {
            $customFields['tmms_customer_input_' . $count . '_value'] = $data[TmmsConstants::SESSION_VALUE_KEY] ?? '';
            $customFields['tmms_customer_input_' . $count . '_label'] = $data[TmmsConstants::SESSION_LABEL_KEY] ?? '';
            $customFields['tmms_customer_input_' . $count . '_placeholder'] = $data[TmmsConstants::SESSION_PLACEHOLDER_KEY] ?? '';
            $customFields['tmms_customer_input_' . $count . '_fieldtype'] = $data[TmmsConstants::SESSION_FIELDTYPE_KEY] ?? '';
        }

        return $customFields;
    }
}
