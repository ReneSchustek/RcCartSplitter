<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter\Service;

use Shopware\Core\Content\Category\CategoryCollection;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

/**
 * Lädt eine Kategorie und alle ihre Vorfahren mit zwei Repository-Abfragen.
 *
 * Shopware hält die Vorfahren jeder Kategorie in `category.path` vor (`|root|…|parent|`, ohne die
 * Kategorie selbst). Daraus ergeben sich alle Kennungen auf einmal; ein Hochhangeln über
 * `parentId` bräuchte eine Abfrage je Ebene.
 */
final class CategoryChainLoader implements CategoryChainLoaderInterface
{
    /** @param EntityRepository<CategoryCollection> $categoryRepository */
    public function __construct(
        private readonly EntityRepository $categoryRepository,
    ) {
    }

    public function loadChain(string $primaryCategoryId, Context $context): array
    {
        $primary = $this->loadCategory($primaryCategoryId, $context);
        if ($primary === null) {
            return [];
        }

        $ancestorIds = $this->parsePathIds($primary->getPath() ?? '');
        if ($ancestorIds === []) {
            return [$this->toEntry($primary)];
        }

        $ancestors = $this->loadCategories($ancestorIds, $context);

        $chain = [$this->toEntry($primary)];
        // Der Pfad läuft von der Wurzel zum Blatt; der Resolver braucht den nächsten Vorfahren zuerst.
        // Fehlt ein Vorfahr im Ergebnis, etwa weil er im Kontext nicht lesbar ist, fällt er aus der Kette.
        foreach (array_reverse($ancestorIds) as $ancestorId) {
            $entity = $ancestors[$ancestorId] ?? null;
            if ($entity instanceof CategoryEntity) {
                $chain[] = $this->toEntry($entity);
            }
        }

        return $chain;
    }

    private function loadCategory(string $id, Context $context): ?CategoryEntity
    {
        $criteria = new Criteria([$id]);
        $criteria->setLimit(1);

        $entity = $this->categoryRepository->search($criteria, $context)->getEntities()->first();

        return $entity instanceof CategoryEntity ? $entity : null;
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, CategoryEntity>
     */
    private function loadCategories(array $ids, Context $context): array
    {
        if ($ids === []) {
            return [];
        }

        $criteria = new Criteria($ids);
        // Mehr Treffer als angefragte Kennungen kann es nicht geben; das Limit schreibt diese
        // Obergrenze ausdrücklich in die Abfrage.
        $criteria->setLimit(\count($ids));

        $result = $this->categoryRepository->search($criteria, $context);

        $map = [];
        foreach ($result->getEntities() as $category) {
            $map[$category->getId()] = $category;
        }

        return $map;
    }

    /**
     * Zerlegt den Shopware-Kategorie-Pfad `|id1|id2|` in eine geordnete Liste von IDs.
     *
     * @return list<string>
     */
    private function parsePathIds(string $path): array
    {
        if ($path === '') {
            return [];
        }

        return array_values(array_filter(
            explode('|', $path),
            static fn (string $segment): bool => $segment !== '',
        ));
    }

    /**
     * @return array{id: string, customFields: array<string, mixed>}
     */
    private function toEntry(CategoryEntity $category): array
    {
        return [
            'id' => $category->getId(),
            'customFields' => $category->getCustomFields() ?? [],
        ];
    }
}
