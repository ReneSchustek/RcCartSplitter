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

/**
 * Schreibt die TMMS-Eingaben jeder Bestellposition aus ihrem eigenen Payload in die custom_fields.
 *
 * TMMS füllt die custom_fields aus der Session, und die kennt je Produktnummer nur einen Wert.
 * Bei mehreren Positionen desselben Artikels stünde sonst überall die zuletzt eingegebene Angabe.
 */
final class OrderInputCorrectionService implements OrderInputCorrectorInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    // Geschrieben wird per DBAL am DAL vorbei: Die Korrektur ist rein kosmetisch und soll keine
    // Write-Events, Indexer oder Flows auslösen. Ein UPDATE mit CASE für alle Positionen spart
    // bei großen Bestellungen eine Abfrage je Position.
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
            // Die Bestellung steht bereits; eine gescheiterte Korrektur darf den Checkout nicht
            // abbrechen. Das Ausnahme-Objekt unter `exception` bringt den Stack-Trace ins Protokoll.
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

        // Erst nach dem erfolgreichen UPDATE: Die Objekte im Speicher sollen nie etwas zeigen, das
        // nicht in der Datenbank steht. TMMS hat die Positionen des Ereignisses zuvor selbst
        // überschrieben, deshalb werden beide Sammlungen angeglichen.
        foreach ($corrections as $hexId => $customFields) {
            $freshItems->get($hexId)?->setCustomFields($customFields);
            $memoryItems?->get($hexId)?->setCustomFields($customFields);
        }
    }

    /** @return array<string, mixed>|null */
    private function correctSingleItem(OrderLineItemEntity $lineItem): ?array
    {
        $payload = $lineItem->getPayload() ?? [];

        // Die Einzelfelder haben Vorrang. Der Sammelschlüssel zählt nur bei Positionen, die keinen
        // `rcTmmsActive`-Marker tragen.
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

        // Alle fünf Felder werden geschrieben, auch leere: Ein Wert, den TMMS aus der Session einer
        // anderen Position eingetragen hat, muss überschrieben werden.
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
