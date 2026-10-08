<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter\Service;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcCartSplitter\TmmsConstants;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Ermittelt den Hinweistext unter den TMMS-Eingabefeldern in der Reihenfolge
 * Produkt → Kategoriekette → Plugin-Konfiguration → null (dann greift das Snippet im Template).
 *
 * Ein leerer oder nur aus Leerzeichen bestehender Text gilt überall als „nicht gesetzt", damit ein
 * geleertes Feld am Produkt den Text der Kategorie nicht mit nichts überstimmt.
 */
final class TmmsInformationMessageResolver implements TmmsInformationMessageResolverInterface
{
    public function __construct(
        private readonly CategoryChainLoaderInterface $categoryChainLoader,
        private readonly SystemConfigService $systemConfigService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function resolveForProduct(
        ProductEntity $product,
        string $salesChannelId,
        Context $context,
    ): ResolvedTmmsInfoMessage {
        $productMessage = $this->stringFromCustomFields(
            $product->getCustomFields() ?? [],
            TmmsConstants::PRODUCT_TMMS_INFO_MESSAGE_FIELD,
        );
        if ($productMessage !== null) {
            return $this->loggedResolution($product, $productMessage, TmmsInfoMessageScope::Product);
        }

        $categoryMessage = $this->resolveFromCategoryChain($product, $context);
        if ($categoryMessage !== null) {
            return $this->loggedResolution($product, $categoryMessage, TmmsInfoMessageScope::Category);
        }

        $configMessage = $this->stringFromConfig($salesChannelId);
        if ($configMessage !== null) {
            return $this->loggedResolution($product, $configMessage, TmmsInfoMessageScope::PluginConfig);
        }

        return new ResolvedTmmsInfoMessage(null, TmmsInfoMessageScope::Default);
    }

    private function resolveFromCategoryChain(ProductEntity $product, Context $context): ?string
    {
        $primaryCategoryId = $this->primaryCategoryId($product);

        if ($primaryCategoryId === null) {
            return null;
        }

        foreach ($this->categoryChainLoader->loadChain($primaryCategoryId, $context) as $entry) {
            $value = $this->stringFromCustomFields(
                $entry['customFields'],
                TmmsConstants::CATEGORY_TMMS_INFO_MESSAGE_FIELD,
            );
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function primaryCategoryId(ProductEntity $product): ?string
    {
        // Die Produktseite lädt `mainCategories` mit. Genommen wird die erste geladene
        // Hauptkategorie, ohne Abgleich mit dem Verkaufskanal. Fehlt sie, bleibt die Kategorieliste.
        $mainCategories = $product->getMainCategories();
        if ($mainCategories !== null) {
            $firstMain = $mainCategories->first();
            if ($firstMain !== null) {
                $categoryId = $firstMain->getCategoryId();
                if ($categoryId !== '') {
                    return $categoryId;
                }
            }
        }

        $categoryIds = $product->getCategoryIds();
        if ($categoryIds === null || $categoryIds === []) {
            return null;
        }

        // Bei mehreren Kategorien gewinnt die kleinste Kennung. Fachlich ist das beliebig, aber
        // stabil: Derselbe Artikel zeigt bei jedem Aufruf denselben Text.
        sort($categoryIds);

        return $categoryIds[0];
    }

    /**
     * @param array<string, mixed> $customFields
     */
    private function stringFromCustomFields(array $customFields, string $key): ?string
    {
        $value = $customFields[$key] ?? null;
        if (!\is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function stringFromConfig(string $salesChannelId): ?string
    {
        $value = $this->systemConfigService->getString(TmmsConstants::CONFIG_TMMS_INFO_MESSAGE, $salesChannelId);
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function loggedResolution(
        ProductEntity $product,
        string $message,
        TmmsInfoMessageScope $scope,
    ): ResolvedTmmsInfoMessage {
        // Mit der Herkunft im Protokoll lässt sich die Frage „Warum sieht der Kunde diesen Text?"
        // ohne Nachrechnen beantworten.
        $this->logger->info('RcCartSplitter: TMMS-Hinweistext aufgelöst', [
            'productId' => $product->getId(),
            'scope' => $scope->value,
        ]);

        return new ResolvedTmmsInfoMessage($message, $scope);
    }
}
