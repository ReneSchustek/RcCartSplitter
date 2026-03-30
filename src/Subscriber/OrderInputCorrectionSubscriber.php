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
            $tmmsInputs = $payload[TmmsConstants::PAYLOAD_TMMS_INPUTS] ?? null;

            if (!is_array($tmmsInputs) || $tmmsInputs === []) {
                continue;
            }

            $customFields = $lineItem->getCustomFields() ?? [];

            // TMMS-Eingaben aus dem gesicherten Payload in die custom_fields schreiben
            foreach ($tmmsInputs as $count => $data) {
                $customFields['tmms_customer_input_' . $count . '_value'] = $data[TmmsConstants::SESSION_VALUE_KEY] ?? '';
                $customFields['tmms_customer_input_' . $count . '_label'] = $data[TmmsConstants::SESSION_LABEL_KEY] ?? '';
                $customFields['tmms_customer_input_' . $count . '_placeholder'] = $data[TmmsConstants::SESSION_PLACEHOLDER_KEY] ?? '';
                $customFields['tmms_customer_input_' . $count . '_fieldtype'] = $data[TmmsConstants::SESSION_FIELDTYPE_KEY] ?? '';
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
}
