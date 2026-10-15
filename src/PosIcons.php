<?php

namespace CommunityStoreQuickPos;

use Concrete\Core\Support\Facade\Application;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Icons of the POS views come from the icon_picker package (an AI pick per text, see its README): one per product
 * group, and one per product without a picture, from its name with the description as context. Until a pick is
 * made, or without the package, the fallback shows. Names belong to the active icon set (the theme's, else Font
 * Awesome); html() gives their markup for views that have no icon component of their own.
 */
class PosIcons
{
    public const FALLBACK = 'package';

    public static function isAvailable(): bool
    {
        return class_exists(\IconPicker\Picker::class);
    }

    /**
     * @param string[] $productNames a few product names of the group, as a hint
     */
    public static function category(string $groupName, array $productNames): string
    {
        $context = 'Product group in the shop of a LAN party';
        if ($productNames) {
            $context .= '; products: ' . implode(', ', array_slice($productNames, 0, 8));
        }

        return self::pick($groupName, self::FALLBACK, $context) ?? self::FALLBACK;
    }

    public static function product(string $name, string $description, string $fallback): string
    {
        $context = 'Product in the shop of a LAN party (drinks, snacks, hardware, merch)';
        if ($description !== '') {
            $context .= ': ' . $description;
        }

        return self::pick($name, $fallback, $context) ?? $fallback;
    }

    public static function pick(string $text, ?string $fallback, string $context): ?string
    {
        if (!self::isAvailable()) {
            return $fallback;
        }

        return Application::getFacadeApplication()->make(\IconPicker\Picker::class)->pick($text, $fallback, $context);
    }

    /**
     * Markup of these icons in the active set, name => HTML ('' for unknown names); empty without icon_picker.
     *
     * @param string[] $names
     *
     * @return array<string, string>
     */
    public static function html(array $names, int $size = 56): array
    {
        if (!self::isAvailable()) {
            return [];
        }
        $set = Application::getFacadeApplication()->make(\IconPicker\Picker::class)->getIconSet();
        $out = [];
        foreach (array_unique(array_filter($names)) as $name) {
            $out[$name] = $set->html($name, $size);
        }

        return $out;
    }
}
