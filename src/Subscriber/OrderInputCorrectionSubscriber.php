<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter\Subscriber;

use Doctrine\DBAL\Connection;
use Ruhrcoder\RcCartSplitter\TmmsConstants;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Storefront\Page\Checkout\Finish\CheckoutFinishPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Korrigiert die TMMS-Kundeneingaben pro Bestellposition.
 *
 * TMMS schreibt alle Positionen desselben Produkts mit den gleichen Session-Daten.
 * Dieser Subscriber läuft NACH TMMS und überschreibt die custom_fields mit den
 * per-LineItem gesicherten Daten aus dem Payload.
 *
 * Line-Items werden frisch aus der DB geladen (gegen In-Memory-Manipulation durch TMMS).
 * Das Update erfolgt per DBAL (gegen DAL-Event-Kaskade durch TMMS).
 */
final class OrderInputCorrectionSubscriber implements EventSubscriberInterface
{
    /** @param EntityRepository<OrderLineItemCollection> $orderLineItemRepository */
    public function __construct(
        private readonly EntityRepository $orderLineItemRepository,
        private readonly Connection $connection,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Muss nach TMMS laufen (Priorität ~0), das alle Positionen
            // desselben Produkts mit den gleichen Session-Daten überschreibt
            CheckoutOrderPlacedEvent::class => ['onOrderPlaced', -500],
            CheckoutFinishPageLoadedEvent::class => ['onCheckoutFinish', -500],
        ];
    }

    public function onOrderPlaced(CheckoutOrderPlacedEvent $event): void
    {
        $order = $event->getOrder();
        $this->correctOrder($order->getId(), $event->getContext(), $order->getLineItems());
    }

    public function onCheckoutFinish(CheckoutFinishPageLoadedEvent $event): void
    {
        $order = $event->getPage()->getOrder();
        $this->correctOrder($order->getId(), $event->getSalesChannelContext()->getContext(), $order->getLineItems());
    }

    private function correctOrder(string $orderId, Context $context, ?OrderLineItemCollection $memoryItems): void
    {
        // Frisch aus DB laden, damit TMMS-Modifikationen an In-Memory-Entities
        // unsere Payload-Keys nicht verdecken
        $freshItems = $this->loadLineItemsFromDb($orderId, $context);

        if ($freshItems->count() === 0) {
            return;
        }

        $this->correctLineItems($freshItems, $memoryItems);
    }

    private function loadLineItemsFromDb(string $orderId, Context $context): OrderLineItemCollection
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderId', $orderId));

        /** @var OrderLineItemCollection $collection */
        $collection = $this->orderLineItemRepository->search($criteria, $context)->getEntities();

        return $collection;
    }

    private function correctLineItems(
        OrderLineItemCollection $freshItems,
        ?OrderLineItemCollection $memoryItems,
    ): void {
        foreach ($freshItems as $lineItem) {
            $corrected = $this->correctSingleItem($lineItem);

            if ($corrected === null) {
                continue;
            }

            // DBAL-Update: umgeht DAL-Events, damit TMMS unsere Korrektur
            // nicht per EntityWrittenEvent wieder überschreiben kann
            $this->connection->executeStatement(
                'UPDATE order_line_item SET custom_fields = :cf WHERE id = :id',
                [
                    'cf' => json_encode($corrected, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
                    'id' => Uuid::fromHexToBytes($lineItem->getId()),
                ],
            );

            // In-Memory-Entities korrigieren für nachfolgende Subscriber / Templates
            $lineItem->setCustomFields($corrected);
            $memoryItems?->get($lineItem->getId())?->setCustomFields($corrected);
        }
    }

    private function correctSingleItem(OrderLineItemEntity $lineItem): ?array
    {
        $payload = $lineItem->getPayload() ?? [];

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
