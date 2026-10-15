<?php

namespace CommunityStoreQuickPos\Sale;

use CommunityStoreQuickPos\PosIcons;
use Concrete\Core\Error\UserMessageException;
use Concrete\Package\CommunityStore\Src\CommunityStore\Group\Group;
use Concrete\Package\CommunityStore\Src\CommunityStore\Product\Product;
use Concrete\Package\CommunityStore\Src\CommunityStore\Product\ProductList;
use Concrete\Package\CommunityStore\Src\CommunityStore\Utilities\Price;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * What a POS sells: one category per store product group (the event's shop groups plus the groups chosen for the
 * kiosk page or register block), with the products that can be sold with a single tap (active, no variations, no
 * required options). Every sale is checked against this list, so a request can't buy anything else. Category icons
 * and the icons of products without a picture come from the icon picker (PosIcons).
 */
class Catalog
{
    /** @var array<int, array{id: int, name: string, icon: string, products: int[]}> */
    protected $categories = [];

    /** @var array<int, Product> */
    protected $products = [];

    /**
     * @param int[] $groupIDs in display order
     */
    public function __construct(array $groupIDs)
    {
        foreach (array_unique(array_map('intval', $groupIDs)) as $gID) {
            $group = Group::getByID($gID);
            if (!$group) {
                continue;
            }
            $list = new ProductList();
            $list->setGroupID($gID);
            $list->setActiveOnly(true);
            $list->setShowOutOfStock(true);
            $list->setSortBy('alpha');
            $list->setSortByDirection('asc');
            $list->setItemsPerPage(300);
            $ids = [];
            $names = [];
            foreach ($list->getResults() as $product) {
                if (!self::isQuickProduct($product)) {
                    continue;
                }
                $pID = (int) $product->getID();
                $this->products[$pID] = $product;
                $ids[] = $pID;
                $names[] = (string) $product->getName();
            }
            if ($ids !== []) {
                $name = (string) $group->getGroupName();
                $this->categories[$gID] = ['id' => $gID, 'name' => $name, 'icon' => PosIcons::category($name, $names), 'products' => $ids];
            }
        }
    }

    public static function isQuickProduct(Product $product): bool
    {
        if (!$product->isActive() || $product->isExclusive() || $product->hasVariations()) {
            return false;
        }
        foreach ($product->getOptions() as $option) {
            if ($option->getRequired()) {
                return false;
            }
        }

        return true;
    }

    public function isEmpty(): bool
    {
        return $this->products === [];
    }

    public function get(int $pID): ?Product
    {
        return $this->products[$pID] ?? null;
    }

    /**
     * Units left, null = unlimited.
     */
    public static function stock(Product $product): ?int
    {
        return $product->isUnlimited() ? null : max(0, (int) floor((float) $product->getStockLevel()));
    }

    /**
     * Turns a request's [pID => qty] into checked sale lines.
     *
     * @param array<int|string, int|string> $items
     *
     * @return array<int, array{product: Product, pID: int, name: string, qty: int, price: float}>
     *
     * @throws UserMessageException
     */
    public function lines(array $items): array
    {
        $lines = [];
        foreach ($items as $pID => $qty) {
            $pID = (int) $pID;
            $qty = (int) $qty;
            if ($qty <= 0) {
                continue;
            }
            $product = $this->get($pID);
            if ($product === null) {
                throw new UserMessageException(t('A product in the cart is not sold here any more. Please start again.'));
            }
            if ($qty > 99 || (!$product->allowQuantity() && $qty > 1)) {
                throw new UserMessageException(t('Too many of %s.', $product->getName()));
            }
            $stock = self::stock($product);
            if ($stock !== null && $qty > $stock) {
                throw new UserMessageException($stock === 0 ? t('%s is sold out.', $product->getName()) : t('Only %s × %s left.', $stock, $product->getName()));
            }
            $lines[] = ['product' => $product, 'pID' => $pID, 'name' => (string) $product->getName(), 'qty' => $qty, 'price' => round((float) $product->getActivePrice($qty), 2)];
        }
        if ($lines === []) {
            throw new UserMessageException(t('The cart is empty.'));
        }

        return $lines;
    }

    /**
     * Data for the POS views: the categories, the products (with a picked icon when they have no picture) and the
     * markup of every icon used, for views without an icon component.
     *
     * @return array{categories: array, products: array, icons: array<string, string>}
     */
    public function toArray(): array
    {
        $images = app('helper/image');
        $products = [];
        $icons = array_column($this->categories, 'icon');
        foreach ($this->products as $pID => $product) {
            $image = null;
            $file = $product->getImageObj();
            if ($file) {
                try {
                    $thumb = $images->getThumbnail($file, 480, 360, true);
                    $image = $thumb ? (string) $thumb->src : null;
                } catch (\Throwable $e) {
                    $image = null;
                }
            }
            $price = round((float) $product->getActivePrice(), 2);
            $description = trim(mb_substr(trim(html_entity_decode(strip_tags((string) $product->getDesc()), ENT_QUOTES, 'UTF-8')), 0, 240));
            $categories = array_values(array_filter($this->categories, static function ($category) use ($pID) {
                return in_array($pID, $category['products'], true);
            }));
            // no picture: an icon picked for the product, until then the icon of its category
            $icon = $image === null ? PosIcons::product((string) $product->getName(), $description, $categories[0]['icon'] ?? PosIcons::FALLBACK) : null;
            if ($icon !== null) {
                $icons[] = $icon;
            }
            $products[] = [
                'id' => $pID,
                'name' => (string) $product->getName(),
                'price' => $price,
                'priceLabel' => strip_tags((string) Price::format($price)),
                'image' => $image,
                'icon' => $icon,
                'description' => $description,
                'stock' => self::stock($product),
                'maxQty' => $product->allowQuantity() ? 99 : 1,
                'categories' => array_column($categories, 'id'),
            ];
        }

        return ['categories' => array_values($this->categories), 'products' => $products, 'icons' => PosIcons::html($icons)];
    }

    /**
     * Stock of some products, for refreshing the views after a sale.
     *
     * @param int[] $pIDs
     *
     * @return array<int, int|null>
     */
    public function stockOf(array $pIDs): array
    {
        $stock = [];
        foreach ($pIDs as $pID) {
            $product = Product::getByID((int) $pID);
            if ($product) {
                $stock[(int) $pID] = self::stock($product);
            }
        }

        return $stock;
    }
}
