<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Custom-Field-Set `rc_cart_splitter` am Produkt mit Feld
 * `rc_cart_splitter_tmms_info_message`. Überschreibt den TMMS-Hinweistext
 * positionsspezifisch (höchste Scope-Priorität).
 */
final class Migration1747000000CreateProductTmmsInfoMessageField extends MigrationStep
{
    private const SET_NAME = 'rc_cart_splitter';
    private const FIELD_NAME = 'rc_cart_splitter_tmms_info_message';

    public function getCreationTimestamp(): int
    {
        return 1747000000;
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
                        'de-DE' => 'Warenkorb-Positionstrennung',
                        'en-GB' => 'Cart Position Splitter',
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
            ['setId' => $setId, 'entity' => 'product']
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
                'entity' => 'product',
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
                        'de-DE' => 'TMMS-Hinweistext (Produkt)',
                        'en-GB' => 'TMMS hint text (product)',
                    ],
                    'helpText' => [
                        'de-DE' => 'Überschreibt den TMMS-Hinweis unter den Eingabefeldern für dieses Produkt. '
                            . 'Zeilenumbrüche werden im Storefront übernommen. Leer = Kategorie-/Plugin-Default.',
                        'en-GB' => 'Overrides the TMMS hint below the customer input fields for this product. '
                            . 'Line breaks are preserved in the storefront. Empty = category / plugin default.',
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
