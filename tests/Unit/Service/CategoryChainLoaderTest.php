<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCartSplitter\Service\CategoryChainLoader;
use Shopware\Core\Content\Category\CategoryCollection;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;

final class CategoryChainLoaderTest extends TestCase
{
    public function testReturnsEmptyChainWhenPrimaryCategoryNotFound(): void
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('search')->willReturn($this->emptyResult());

        $loader = new CategoryChainLoader($repo);
        self::assertSame([], $loader->loadChain('missing-id', Context::createDefaultContext()));
    }

    public function testReturnsSingleEntryWhenNoAncestors(): void
    {
        $primary = $this->createCategory('cat-1', '', ['unit' => 'm']);

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('search')->willReturn($this->resultWith([$primary]));

        $loader = new CategoryChainLoader($repo);
        $chain = $loader->loadChain('cat-1', Context::createDefaultContext());

        self::assertCount(1, $chain);
        self::assertSame('cat-1', $chain[0]['id']);
        self::assertSame(['unit' => 'm'], $chain[0]['customFields']);
    }

    public function testReturnsChainWithReversedAncestors(): void
    {
        $primary = $this->createCategory('cat-leaf', '|cat-root|cat-mid|', ['leaf' => 1]);
        $root = $this->createCategory('cat-root', '', ['root' => 1]);
        $mid = $this->createCategory('cat-mid', '|cat-root|', ['mid' => 1]);

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('search')->willReturnOnConsecutiveCalls(
            $this->resultWith([$primary]),
            $this->resultWith([$root, $mid]),
        );

        $loader = new CategoryChainLoader($repo);
        $chain = $loader->loadChain('cat-leaf', Context::createDefaultContext());

        // Erwartet: leaf (primary) zuerst, dann mid (naechster Vorfahr), dann root
        self::assertCount(3, $chain);
        self::assertSame('cat-leaf', $chain[0]['id']);
        self::assertSame('cat-mid', $chain[1]['id']);
        self::assertSame('cat-root', $chain[2]['id']);
    }

    public function testSkipsMissingAncestorEntities(): void
    {
        $primary = $this->createCategory('cat-leaf', '|cat-root|cat-mid|', []);
        $root = $this->createCategory('cat-root', '', []);
        // cat-mid wird nicht geliefert (z.B. weil deaktiviert)

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('search')->willReturnOnConsecutiveCalls(
            $this->resultWith([$primary]),
            $this->resultWith([$root]),
        );

        $loader = new CategoryChainLoader($repo);
        $chain = $loader->loadChain('cat-leaf', Context::createDefaultContext());

        // Erwartet: leaf + root, mid uebersprungen
        self::assertCount(2, $chain);
        self::assertSame('cat-leaf', $chain[0]['id']);
        self::assertSame('cat-root', $chain[1]['id']);
    }

    public function testHandlesNullCustomFields(): void
    {
        $primary = new CategoryEntity();
        $primary->setUniqueIdentifier('cat-x');
        $primary->setId('cat-x');
        $primary->setPath('');
        // customFields bewusst nicht gesetzt

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('search')->willReturn($this->resultWith([$primary]));

        $loader = new CategoryChainLoader($repo);
        $chain = $loader->loadChain('cat-x', Context::createDefaultContext());

        self::assertSame([], $chain[0]['customFields']);
    }

    /**
     * @param array<string, mixed> $customFields
     */
    private function createCategory(string $id, string $path, array $customFields): CategoryEntity
    {
        $category = new CategoryEntity();
        $category->setUniqueIdentifier($id);
        $category->setId($id);
        $category->setPath($path);
        $category->setCustomFields($customFields);

        return $category;
    }

    private function emptyResult(): EntitySearchResult
    {
        $result = $this->createMock(EntitySearchResult::class);
        $result->method('first')->willReturn(null);
        $result->method('getEntities')->willReturn(new CategoryCollection());

        return $result;
    }

    /** @param list<CategoryEntity> $entities */
    private function resultWith(array $entities): EntitySearchResult
    {
        $result = $this->createMock(EntitySearchResult::class);
        $result->method('first')->willReturn($entities[0] ?? null);
        $result->method('getEntities')->willReturn(new CategoryCollection($entities));

        return $result;
    }
}
