<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Psr\Log\LoggerInterface;
use Ruhrcoder\RcCartSplitter\TmmsConstants;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Framework\Uuid\Uuid;

/** Korrigiert TMMS-Kundeneingaben in den custom_fields der Bestellpositionen */
final class OrderInputCorrectionService implements OrderInputCorrectorInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    // DBAL umgeht DAL-Events, damit TMMS unsere Korrektur nicht per EntityWrittenEvent zurueckschreibt;
    // Batch-CASE-WHEN in einer Transaktion vermeidet N Roundtrips bei grossen Bestellungen.
    public function correctLineItems(
        OrderLineItemCollection $freshItems,
        ?OrderLineItemCollection $memoryItems,
    ): void {
        /** @var array<string, array<string, mixed>> $corrections */
        $corrections = [];
        foreach ($freshItems as $lineItem) {
            $corrected = $this->correctSingleItem($lineItem);
            if ($corrected === null) {
                continue;
            }
            $corrections[$lineItem->getId()] = $corrected;
        }

        if ($corrections === []) {
            return;
        }

        try {
            $this->connection->transactional(function (Connection $connection) use ($corrections): void {
                $this->batchUpdateCustomFields($connection, $corrections);
            });
        } catch (DbalException|\JsonException $e) {
            // Cosmetic-Fix darf den Checkout nicht killen — Fehler aggregiert loggen und abbrechen.
            // Exception-Objekt statt -message: Monolog ergaenzt Stack-Trace fuer Root-Cause.
            $this->logger->error('TMMS-Korrektur fehlgeschlagen', [
                'lineItemIds' => array_keys($corrections),
                'count' => count($corrections),
                'exception' => $e,
            ]);
            return;
        }

        $this->logger->debug('TMMS-Eingaben korrigiert', [
            'count' => count($corrections),
        ]);

        foreach ($corrections as $hexId => $customFields) {
            $freshItems->get($hexId)?->setCustomFields($customFields);
            $memoryItems?->get($hexId)?->setCustomFields($customFields);
        }
    }

    /** @return array<string, mixed>|null */
    private function correctSingleItem(OrderLineItemEntity $lineItem): ?array
    {
        $payload = $lineItem->getPayload() ?? [];

        // Bevorzugt JS-Payload — Session-Fallback nur fuer Altbestellungen ohne Hidden-Felder
        $customFields = $this->buildFromPayloadKeys($payload, $lineItem->getCustomFields() ?? []);
        if ($customFields === null) {
            $customFields = $this->buildFromSessionData($payload, $lineItem->getCustomFields() ?? []);
        }

        return $customFields;
    }

    /**
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
            $customFields[TmmsConstants::customFieldValueKey($i)] = $payload[TmmsConstants::payloadValueKey($i)] ?? '';
            $customFields[TmmsConstants::customFieldLabelKey($i)] = $payload[TmmsConstants::payloadLabelKey($i)] ?? '';
        }

        return $customFields;
    }

    /**
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
            $customFields[TmmsConstants::customFieldValueKey($count)] = $data[TmmsConstants::SESSION_VALUE_KEY] ?? '';
            $customFields[TmmsConstants::customFieldLabelKey($count)] = $data[TmmsConstants::SESSION_LABEL_KEY] ?? '';
            $customFields[TmmsConstants::customFieldPlaceholderKey($count)] = $data[TmmsConstants::SESSION_PLACEHOLDER_KEY] ?? '';
            $customFields[TmmsConstants::customFieldFieldtypeKey($count)] = $data[TmmsConstants::SESSION_FIELDTYPE_KEY] ?? '';
        }

        return $customFields;
    }

    /**
     * @param array<string, array<string, mixed>> $corrections hexId => customFields
     * @throws DbalException|\JsonException
     */
    private function batchUpdateCustomFields(Connection $connection, array $corrections): void
    {
        $caseSql = '';
        $idPlaceholders = [];
        $params = [];
        $i = 0;

        foreach ($corrections as $hexId => $customFields) {
            $idKey = 'id_' . $i;
            $cfKey = 'cf_' . $i;
            $caseSql .= ' WHEN :' . $idKey . ' THEN :' . $cfKey;
            $idPlaceholders[] = ':' . $idKey;
            $params[$idKey] = Uuid::fromHexToBytes($hexId);
            $params[$cfKey] = json_encode(
                $customFields,
                \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
            );
            $i++;
        }

        $sql = sprintf(
            'UPDATE order_line_item SET custom_fields = (CASE id%s END) WHERE id IN (%s)',
            $caseSql,
            implode(', ', $idPlaceholders),
        );

        $connection->executeStatement($sql, $params);
    }
}
