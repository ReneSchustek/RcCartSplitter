<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Custom-Field-Set `rc_cart_splitter_category` an der Kategorie mit Feld
 * `rc_cart_splitter_cat_tmms_info_message`. Globaler `custom_field.name`-
 * UNIQUE-Index erzwingt eigenen Namensraum gegenüber dem Produktfeld.
 */
final class Migration1747000010CreateCategoryTmmsInfoMessageField extends MigrationStep
{
    private const SET_NAME = 'rc_cart_splitter_category';
    private const FIELD_NAME = 'rc_cart_splitter_cat_tmms_info_message';

    public function getCreationTimestamp(): int
    {
        return 1747000010;
    }

    public function update(Connection $connection): void
    {
        $setId = $this->ensureCustomFieldSet($connection);
        $this->ensureCustomFieldSetRelation($connection, $setId);
        $this->ensureCustomField($connection, $setId);
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function ensureCustomFieldSet(Connection $connection): string
    {
        $existingId = $connection->fetchOne(
            'SELECT `id` FROM `custom_field_set` WHERE `name` = :name',
            ['name' => self::SET_NAME]
        );

        if ($existingId !== false) {
            return (string) $existingId;
        }

        $setId = Uuid::randomBytes();

        $connection->executeStatement(
            'INSERT INTO `custom_field_set` (`id`, `name`, `config`, `active`, `global`, `position`, `created_at`)
             VALUES (:id, :name, :config, 1, 0, 1, NOW())',
            [
                'id'     => $setId,
                'name'   => self::SET_NAME,
                'config' => json_encode([
                    'label' => [
                        'de-DE' => 'Warenkorb-Positionstrennung (Kategorie)',
                        'en-GB' => 'Cart Position Splitter (Category)',
                    ],
                    'translated' => true,
                ], \JSON_THROW_ON_ERROR),
            ]
        );

        return $setId;
    }

    private function ensureCustomFieldSetRelation(Connection $connection, string $setId): void
    {
        $exists = $connection->fetchOne(
            'SELECT 1 FROM `custom_field_set_relation` WHERE `set_id` = :setId AND `entity_name` = :entity',
            ['setId' => $setId, 'entity' => 'category']
        );

        if ($exists !== false) {
            return;
        }

        $connection->executeStatement(
            'INSERT INTO `custom_field_set_relation` (`id`, `set_id`, `entity_name`, `created_at`)
             VALUES (:id, :setId, :entity, NOW())',
            [
                'id'     => Uuid::randomBytes(),
                'setId'  => $setId,
                'entity' => 'category',
            ]
        );
    }

    private function ensureCustomField(Connection $connection, string $setId): void
    {
        $exists = $connection->fetchOne(
            'SELECT 1 FROM `custom_field` WHERE `name` = :name',
            ['name' => self::FIELD_NAME]
        );

        if ($exists !== false) {
            return;
        }

        $connection->executeStatement(
            'INSERT INTO `custom_field` (`id`, `name`, `type`, `config`, `active`, `set_id`, `created_at`)
             VALUES (:id, :name, :type, :config, 1, :setId, NOW())',
            [
                'id'     => Uuid::randomBytes(),
                'name'   => self::FIELD_NAME,
                'type'   => 'text',
                'config' => json_encode([
                    'label' => [
                        'de-DE' => 'TMMS-Hinweistext (Kategorie)',
                        'en-GB' => 'TMMS hint text (category)',
                    ],
                    'helpText' => [
                        'de-DE' => 'Fallback-Hinweistext für alle Produkte dieser Kategorie. '
                            . 'Wird durch ein Produkt-Override überstimmt. Leer = Plugin-Default greift.',
                        'en-GB' => 'Fallback hint text for all products in this category. '
                            . 'Overridden by a product-level value. Empty = plugin default applies.',
                    ],
                    'componentName'       => 'sw-textarea-field',
                    'customFieldType'     => 'textarea',
                    'type'                => 'textarea',
                    'customFieldPosition' => 1,
                ], \JSON_THROW_ON_ERROR),
                'setId' => $setId,
            ]
        );
    }
}
