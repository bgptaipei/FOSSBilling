<?php

declare(strict_types=1);
/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace Box\Mod\Product\Repository;

use Box\Mod\Product\Entity\ProductOptionGroup;
use Box\Mod\Product\Entity\ProductOptionValue;
use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<ProductOptionGroup>
 */
class ProductOptionGroupRepository extends EntityRepository
{
    /**
     * @return array<string, mixed>
     */
    public function catalogForProduct(int $productId): array
    {
        /** @var list<ProductOptionGroup> $groups */
        $groups = $this->findBy(['productId' => $productId, 'active' => true], ['sortOrder' => 'ASC', 'id' => 'ASC']);
        if ($groups === []) {
            return [];
        }

        $groupIds = array_map(static fn (ProductOptionGroup $group): int => (int) $group->getId(), $groups);
        /** @var list<ProductOptionValue> $values */
        $values = $this->getEntityManager()->getRepository(ProductOptionValue::class)->findBy(
            ['groupId' => $groupIds, 'active' => true],
            ['sortOrder' => 'ASC', 'id' => 'ASC']
        );
        $valuesByGroup = [];
        foreach ($values as $value) {
            $valuesByGroup[$value->getGroupId()][] = $value;
        }

        $catalogGroups = [];
        foreach ($groups as $group) {
            $groupValues = [];
            foreach ($valuesByGroup[(int) $group->getId()] ?? [] as $value) {
                $groupValues[] = [
                    'id' => (int) $value->getId(),
                    'code' => $value->getCode(),
                    'label' => $value->getLabel(),
                    'monthly_cents' => $value->getMonthlyPriceCents(),
                    'setup_cents' => $value->getSetupPriceCents(),
                    'is_default' => $value->isDefault(),
                    'active' => $value->isActive(),
                    'stock_control' => $value->isStockControl(),
                    'quantity_in_stock' => $value->getQuantityInStock(),
                ];
            }
            $periods = array_values(array_filter(array_map('trim', explode(',', $group->getPeriods()))));
            $catalogGroups[] = [
                'id' => (int) $group->getId(),
                'code' => $group->getCode(),
                'label' => $group->getLabel(),
                'required' => $group->isRequired(),
                'min_count' => $group->getMinCount(),
                'max_count' => $group->getMaxCount(),
                'currency' => $group->getCurrency(),
                'periods' => $periods,
                'verified' => $group->isVerified(),
                'active' => $group->isActive(),
                'values' => $groupValues,
            ];
        }

        return ['groups' => $catalogGroups];
    }
}
