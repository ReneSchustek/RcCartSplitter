<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Ruhrcoder\RcCartSplitter\TmmsConstants;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Framework\Uuid\Uuid;

/** Korrigiert TMMS-Kundeneingaben in den custom_fields der Bestellpositionen */
final class OrderInputCorrectionService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Korrigiert alle LineItems einer Bestellung anhand der gesicherten Payload-Daten.
     *
     * DBAL-Update umgeht DAL-Events, damit TMMS unsere Korrektur
     * nicht per EntityWrittenEvent wieder überschreiben kann.
     */
    public function correctLineItems(
        OrderLineItemCollection $freshItems,
        ?OrderLineItemCollection $memoryItems,
    ): void {
        foreach ($freshItems as $lineItem) {
            $corrected = $this->correctSingleItem($lineItem);

            if ($corrected === null) {
                continue;
            }

            $this->connection->executeStatement(
                'UPDATE order_line_item SET custom_fields = :cf WHERE id = :id',
                [
                    'cf' => json_encode($corrected, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
                    'id' => Uuid::fromHexToBytes($lineItem->getId()),
                ],
            );

            $this->logger->debug('TMMS-Eingaben korrigiert', [
                'lineItemId' => $lineItem->getId(),
            ]);

            // In-Memory-Entities korrigieren für nachfolgende Subscriber / Templates
            $lineItem->setCustomFields($corrected);
            $memoryItems?->get($lineItem->getId())?->setCustomFields($corrected);
        }
    }

    /** @return array<string, mixed>|null */
    public function correctSingleItem(OrderLineItemEntity $lineItem): ?array
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
    public function buildFromPayloadKeys(array $payload, array $customFields): ?array
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
    public function buildFromSessionData(array $payload, array $customFields): ?array
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
