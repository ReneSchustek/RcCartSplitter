<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcCartSplitter\Service\CategoryChainLoaderInterface;
use Ruhrcoder\RcCartSplitter\Service\TmmsInfoMessageScope;
use Ruhrcoder\RcCartSplitter\Service\TmmsInformationMessageResolver;
use Ruhrcoder\RcCartSplitter\TmmsConstants;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SystemConfig\SystemConfigService;

#[CoversClass(TmmsInformationMessageResolver::class)]
final class TmmsInformationMessageResolverTest extends TestCase
{
    private const SALES_CHANNEL_ID = '0188b3f0c6437f9aaeac0a83cdb1f0a0';
    private const PRIMARY_CATEGORY_ID = 'c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1';
    private const PARENT_CATEGORY_ID = 'p1p1p1p1p1p1p1p1p1p1p1p1p1p1p1p1';

    private CategoryChainLoaderInterface&MockObject $chainLoader;
    private SystemConfigService&MockObject $systemConfig;
    private TmmsInformationMessageResolver $resolver;

    protected function setUp(): void
    {
        $this->chainLoader = $this->createMock(CategoryChainLoaderInterface::class);
        $this->systemConfig = $this->createMock(SystemConfigService::class);

        $this->resolver = new TmmsInformationMessageResolver(
            $this->chainLoader,
            $this->systemConfig,
            new NullLogger(),
        );
    }

    #[Test]
    public function productScopeWinsOverEverything(): void
    {
        $product = $this->productWithCustomFields([
            TmmsConstants::PRODUCT_TMMS_INFO_MESSAGE_FIELD => 'produktspezifisch',
            'categoryIds' => [self::PRIMARY_CATEGORY_ID],
        ]);

        $this->chainLoader->expects(self::never())->method('loadChain');
        $this->systemConfig->expects(self::never())->method('getString');

        $resolved = $this->resolver->resolveForProduct($product, self::SALES_CHANNEL_ID, Context::createDefaultContext());

        self::assertSame('produktspezifisch', $resolved->message);
        self::assertSame(TmmsInfoMessageScope::Product, $resolved->scope);
    }

    #[Test]
    public function categoryScopeUsedWhenProductFieldEmpty(): void
    {
        $product = $this->productWithCategoryIds([self::PRIMARY_CATEGORY_ID]);

        $this->chainLoader
            ->method('loadChain')
            ->with(self::PRIMARY_CATEGORY_ID, self::isInstanceOf(Context::class))
            ->willReturn([
                ['id' => self::PRIMARY_CATEGORY_ID, 'customFields' => []],
                ['id' => self::PARENT_CATEGORY_ID, 'customFields' => [
                    TmmsConstants::CATEGORY_TMMS_INFO_MESSAGE_FIELD => 'aus Eltern-Kategorie',
                ]],
            ]);
        $this->systemConfig->expects(self::never())->method('getString');

        $resolved = $this->resolver->resolveForProduct($product, self::SALES_CHANNEL_ID, Context::createDefaultContext());

        self::assertSame('aus Eltern-Kategorie', $resolved->message);
        self::assertSame(TmmsInfoMessageScope::Category, $resolved->scope);
    }

    #[Test]
    public function pluginConfigUsedWhenProductAndCategoryEmpty(): void
    {
        $product = $this->productWithCategoryIds([self::PRIMARY_CATEGORY_ID]);

        $this->chainLoader
            ->method('loadChain')
            ->willReturn([
                ['id' => self::PRIMARY_CATEGORY_ID, 'customFields' => []],
            ]);
        $this->systemConfig
            ->method('getString')
            ->with(TmmsConstants::CONFIG_TMMS_INFO_MESSAGE, self::SALES_CHANNEL_ID)
            ->willReturn('aus Plugin-Config');

        $resolved = $this->resolver->resolveForProduct($product, self::SALES_CHANNEL_ID, Context::createDefaultContext());

        self::assertSame('aus Plugin-Config', $resolved->message);
        self::assertSame(TmmsInfoMessageScope::PluginConfig, $resolved->scope);
    }

    #[Test]
    public function defaultScopeWhenNothingSet(): void
    {
        $product = $this->productWithCategoryIds([self::PRIMARY_CATEGORY_ID]);

        $this->chainLoader
            ->method('loadChain')
            ->willReturn([['id' => self::PRIMARY_CATEGORY_ID, 'customFields' => []]]);
        $this->systemConfig
            ->method('getString')
            ->willReturn('');

        $resolved = $this->resolver->resolveForProduct($product, self::SALES_CHANNEL_ID, Context::createDefaultContext());

        self::assertNull($resolved->message);
        self::assertSame(TmmsInfoMessageScope::Default, $resolved->scope);
    }

    #[Test]
    public function emptyStringIsTreatedAsNotSetAtAllLevels(): void
    {
        // Leerer String an jeder Stelle darf einen tieferen Scope nicht überstimmen.
        $product = $this->productWithCustomFields([
            TmmsConstants::PRODUCT_TMMS_INFO_MESSAGE_FIELD => '   ',
            'categoryIds' => [self::PRIMARY_CATEGORY_ID],
        ]);

        $this->chainLoader
            ->method('loadChain')
            ->willReturn([
                ['id' => self::PRIMARY_CATEGORY_ID, 'customFields' => [
                    TmmsConstants::CATEGORY_TMMS_INFO_MESSAGE_FIELD => '',
                ]],
            ]);
        $this->systemConfig
            ->method('getString')
            ->willReturn('aus Plugin-Config');

        $resolved = $this->resolver->resolveForProduct($product, self::SALES_CHANNEL_ID, Context::createDefaultContext());

        self::assertSame('aus Plugin-Config', $resolved->message);
        self::assertSame(TmmsInfoMessageScope::PluginConfig, $resolved->scope);
    }

    #[Test]
    public function productWithoutCategoryStillFallsBackToPluginConfig(): void
    {
        $product = $this->productWithCategoryIds(null);

        $this->chainLoader->expects(self::never())->method('loadChain');
        $this->systemConfig
            ->method('getString')
            ->willReturn('aus Plugin-Config');

        $resolved = $this->resolver->resolveForProduct($product, self::SALES_CHANNEL_ID, Context::createDefaultContext());

        self::assertSame('aus Plugin-Config', $resolved->message);
        self::assertSame(TmmsInfoMessageScope::PluginConfig, $resolved->scope);
    }

    /**
     * @param array<string, mixed> $customFields
     */
    private function productWithCustomFields(array $customFields): ProductEntity
    {
        $categoryIds = $customFields['categoryIds'] ?? null;
        unset($customFields['categoryIds']);

        $product = new ProductEntity();
        $product->setId('p0p0p0p0p0p0p0p0p0p0p0p0p0p0p0p0');
        $product->setCustomFields($customFields);

        if (\is_array($categoryIds)) {
            $product->setCategoryIds($categoryIds);
        }

        return $product;
    }

    /**
     * @param list<string>|null $categoryIds
     */
    private function productWithCategoryIds(?array $categoryIds): ProductEntity
    {
        $product = new ProductEntity();
        $product->setId('p0p0p0p0p0p0p0p0p0p0p0p0p0p0p0p0');
        $product->setCustomFields([]);

        if ($categoryIds !== null) {
            $product->setCategoryIds($categoryIds);
        }

        return $product;
    }
}
