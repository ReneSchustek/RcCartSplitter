<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter\Service;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcCartSplitter\TmmsConstants;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Löst den TMMS-Hinweistext per Scope-Hierarchie auf:
 * Produkt → Kategorie-Chain → Plugin-Config → null.
 *
 * Leere Strings werden überall als „nicht gesetzt" behandelt, damit ein
 * leerer Override nicht einen vorhandenen weiter unten überstimmt.
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

        // Deterministisch erste Kategorie nehmen — bei Mehrfach-Zuweisung gewinnt
        // die alphabetisch erste UUID, was reproduzierbar bleibt.
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
        // Scope-Herkunft im Log macht Support-Anfragen „warum sieht der Kunde Text X?" trivial.
        $this->logger->info('RcCartSplitter: TMMS-Hinweistext aufgelöst', [
            'productId' => $product->getId(),
            'scope' => $scope->value,
        ]);

        return new ResolvedTmmsInfoMessage($message, $scope);
    }
}
