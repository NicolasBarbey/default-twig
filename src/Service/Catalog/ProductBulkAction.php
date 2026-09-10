<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace BackOfficeDefaultTwigBundle\Service\Catalog;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Product\ProductAddAssociationEvent;
use Thelia\Core\Event\Product\ProductAddCategoryEvent;
use Thelia\Core\Event\Product\ProductAddContentEvent;
use Thelia\Core\Event\Product\ProductDeleteAssociationEvent;
use Thelia\Core\Event\Product\ProductDeleteCategoryEvent;
use Thelia\Core\Event\Product\ProductDeleteContentEvent;
use Thelia\Core\Event\Product\ProductDeleteEvent;
use Thelia\Core\Event\Product\ProductSetTemplateEvent;
use Thelia\Core\Event\Product\ProductToggleVisibilityEvent;
use Thelia\Core\Event\Product\ProductUpdateEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\Product;
use Thelia\Model\ProductAssociationType;

/**
 * Applies one edit to a set of products by dispatching the same events the
 * single-product screens use, so listeners, hooks and audit logs behave exactly
 * as they do for a one-by-one edit.
 */
final readonly class ProductBulkAction
{
    public function __construct(
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    /**
     * @param list<Product> $products
     *
     * @return int number of products actually changed
     */
    public function setVisibility(array $products, bool $online): int
    {
        $changed = 0;
        foreach ($products as $product) {
            if ((bool) $product->getVisible() === $online) {
                continue;
            }

            // Toggle is the only visibility event; skipping already-correct rows
            // keeps the batch idempotent.
            $this->dispatcher->dispatch(new ProductToggleVisibilityEvent($product), TheliaEvents::PRODUCT_TOGGLE_VISIBILITY);
            ++$changed;
        }

        return $changed;
    }

    /**
     * @param list<Product> $products
     */
    public function setDefaultCategory(array $products, int $categoryId, string $locale): int
    {
        return $this->updateEach($products, $locale, static function (ProductUpdateEvent $event) use ($categoryId): void {
            $event->setDefaultCategory($categoryId);
        });
    }

    /**
     * @param list<Product> $products
     */
    public function setBrand(array $products, ?int $brandId, string $locale): int
    {
        return $this->updateEach($products, $locale, static function (ProductUpdateEvent $event) use ($brandId): void {
            $event->setBrandId($brandId);
        });
    }

    /**
     * @param list<Product> $products
     */
    public function setTemplate(array $products, ?int $templateId): int
    {
        $currency = CurrencyQuery::create()->findOneByByDefault(1) ?? CurrencyQuery::create()->findOne();
        $currencyId = $currency !== null ? (int) $currency->getId() : null;

        $changed = 0;
        foreach ($products as $product) {
            if ((int) $product->getTemplateId() === (int) $templateId) {
                continue;
            }

            $this->dispatcher->dispatch(
                new ProductSetTemplateEvent($product, $templateId, $currencyId),
                TheliaEvents::PRODUCT_SET_TEMPLATE,
            );
            ++$changed;
        }

        return $changed;
    }

    /**
     * @param list<Product> $products
     * @param list<int>     $contentIds
     */
    public function addContents(array $products, array $contentIds): int
    {
        return $this->relate($products, $contentIds, static fn (Product $product, int $id): array => [
            new ProductAddContentEvent($product, $id), TheliaEvents::PRODUCT_ADD_CONTENT,
        ]);
    }

    /**
     * @param list<Product> $products
     * @param list<int>     $contentIds
     */
    public function removeContents(array $products, array $contentIds): int
    {
        return $this->relate($products, $contentIds, static fn (Product $product, int $id): array => [
            new ProductDeleteContentEvent($product, $id), TheliaEvents::PRODUCT_REMOVE_CONTENT,
        ]);
    }

    /**
     * Kept for compatibility: relates under the accessory type.
     *
     * @param list<Product> $products
     * @param list<int>     $accessoryIds
     */
    public function addAccessories(array $products, array $accessoryIds): int
    {
        return $this->addAssociations($products, $accessoryIds, ProductAssociationType::CODE_ACCESSORY);
    }

    /**
     * Kept for compatibility: unrelates under the accessory type.
     *
     * @param list<Product> $products
     * @param list<int>     $accessoryIds
     */
    public function removeAccessories(array $products, array $accessoryIds): int
    {
        return $this->removeAssociations($products, $accessoryIds, ProductAssociationType::CODE_ACCESSORY);
    }

    /**
     * @param list<Product> $products
     * @param list<int>     $associatedProductIds
     */
    public function addAssociations(array $products, array $associatedProductIds, string $typeCode): int
    {
        return $this->relate($products, $associatedProductIds, static fn (Product $product, int $id): array => [
            new ProductAddAssociationEvent($product, $id, $typeCode), TheliaEvents::PRODUCT_ADD_ASSOCIATION,
        ], skipSelf: true);
    }

    /**
     * @param list<Product> $products
     * @param list<int>     $associatedProductIds
     */
    public function removeAssociations(array $products, array $associatedProductIds, string $typeCode): int
    {
        return $this->relate($products, $associatedProductIds, static fn (Product $product, int $id): array => [
            new ProductDeleteAssociationEvent($product, $id, $typeCode), TheliaEvents::PRODUCT_REMOVE_ASSOCIATION,
        ], skipSelf: true);
    }

    /**
     * @param list<Product> $products
     * @param list<int>     $categoryIds
     */
    public function addCategories(array $products, array $categoryIds): int
    {
        return $this->relate($products, $categoryIds, static fn (Product $product, int $id): array => [
            new ProductAddCategoryEvent($product, $id), TheliaEvents::PRODUCT_ADD_CATEGORY,
        ]);
    }

    /**
     * @param list<Product> $products
     * @param list<int>     $categoryIds
     */
    public function removeCategories(array $products, array $categoryIds): int
    {
        return $this->relate($products, $categoryIds, static fn (Product $product, int $id): array => [
            new ProductDeleteCategoryEvent($product, $id), TheliaEvents::PRODUCT_REMOVE_CATEGORY,
        ]);
    }

    /**
     * @param list<Product> $products
     */
    public function delete(array $products): int
    {
        $deleted = 0;
        foreach ($products as $product) {
            $this->dispatcher->dispatch(new ProductDeleteEvent((int) $product->getId()), TheliaEvents::PRODUCT_DELETE);
            ++$deleted;
        }

        return $deleted;
    }

    /**
     * PRODUCT_UPDATE overwrites every editable field, so each event is rehydrated
     * from the product itself and only the targeted field is changed.
     *
     * @param list<Product>                      $products
     * @param callable(ProductUpdateEvent): void $mutate
     */
    private function updateEach(array $products, string $locale, callable $mutate): int
    {
        $changed = 0;
        foreach ($products as $product) {
            $product->setLocale($locale);

            $event = new ProductUpdateEvent((int) $product->getId());
            $event
                ->setRef((string) $product->getRef())
                ->setLocale($locale)
                ->setTitle((string) $product->getTitle())
                ->setChapo((string) $product->getChapo())
                ->setDescription((string) $product->getDescription())
                ->setPostscriptum((string) $product->getPostscriptum())
                ->setVisible((bool) $product->getVisible())
                ->setVirtual((bool) $product->getVirtual())
                ->setBrandId($product->getBrandId() !== null ? (int) $product->getBrandId() : null)
                ->setDefaultCategory((int) $product->getDefaultCategoryId());

            $mutate($event);

            $this->dispatcher->dispatch($event, TheliaEvents::PRODUCT_UPDATE);
            ++$changed;
        }

        return $changed;
    }

    /**
     * @param list<Product>                                       $products
     * @param list<int>                                           $relatedIds
     * @param callable(Product, int): array{0: object, 1: string} $factory
     */
    private function relate(array $products, array $relatedIds, callable $factory, bool $skipSelf = false): int
    {
        $applied = 0;
        foreach ($products as $product) {
            foreach ($relatedIds as $relatedId) {
                if ($skipSelf && (int) $product->getId() === $relatedId) {
                    continue;
                }

                [$event, $eventName] = $factory($product, $relatedId);
                $this->dispatcher->dispatch($event, $eventName);
                ++$applied;
            }
        }

        return $applied;
    }
}
