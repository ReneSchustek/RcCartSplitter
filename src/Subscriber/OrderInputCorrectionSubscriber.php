<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter\Subscriber;

use Ruhrcoder\RcCartSplitter\TmmsConstants;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Korrigiert die TMMS-Kundeneingaben pro Bestellposition.
 *
 * TMMS schreibt alle Positionen desselben Produkts mit den gleichen Session-Daten.
 * Dieser Subscriber läuft NACH TMMS (niedrigere Priorität) und überschreibt
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
            CheckoutOrderPlacedEvent::class => ['onOrderPlaced', -100],
        ];
    }

    public function onOrderPlaced(CheckoutOrderPlacedEvent $event): void
    {
        $order = $event->getOrder();
        $lineItems = $order->getLineItems();

        if ($lineItems === null) {
            return;
        }

        $updates = [];

        foreach ($lineItems as $lineItem) {
            $payload = $lineItem->getPayload();

            // Quelle 1: Neue Payload-Keys (vom JS injiziert)
            $customFields = $this->buildFromPayloadKeys($payload, $lineItem->getCustomFields() ?? []);

            // Quelle 2: Fallback auf alte Session-basierte Daten
            if ($customFields === null) {
                $customFields = $this->buildFromSessionData($payload, $lineItem->getCustomFields() ?? []);
            }

            if ($customFields === null) {
                continue;
            }

            $updates[] = [
                'id' => $lineItem->getId(),
                'customFields' => $customFields,
            ];
        }

        if ($updates !== []) {
            $this->orderLineItemRepository->update($updates, $event->getContext());
        }
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
