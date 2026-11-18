<?php

namespace App\Enums;

/**
 * The colours a category can have (Task #116). Each one is a set of tokens in resources/css/app.css
 * (--cat-<colour>, -fg and -soft) for the light and the dark theme: a dot tone with at least 3:1
 * against the page, and a text tone with at least 4.5:1 on its soft tone and on the page (WCAG 2.2 AA).
 *
 * The first four are the colours the four original categories always had.
 */
enum CategoryColor: string
{
    case Violet = 'violet';

    case Green = 'green';

    case Orange = 'orange';

    case Pink = 'pink';

    case Blue = 'blue';

    case Teal = 'teal';

    case Amber = 'amber';

    case Slate = 'slate';

    public function label(): string
    {
        return match ($this) {
            self::Violet => __('categories.colors.violet'),
            self::Green => __('categories.colors.green'),
            self::Orange => __('categories.colors.orange'),
            self::Pink => __('categories.colors.pink'),
            self::Blue => __('categories.colors.blue'),
            self::Teal => __('categories.colors.teal'),
            self::Amber => __('categories.colors.amber'),
            self::Slate => __('categories.colors.slate'),
        };
    }

    /**
     * The admin panel's badge colour nearest to it, so a tag's category looks the same there.
     */
    public function badge(): string
    {
        return match ($this) {
            self::Violet, self::Blue => 'info',
            self::Green => 'primary',
            self::Orange, self::Amber => 'warning',
            self::Pink, self::Teal => 'success',
            self::Slate => 'gray',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $color) => $color->value, self::cases());
    }

    /**
     * value => label, for a picker.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_combine(self::values(), array_map(fn (self $color) => $color->label(), self::cases()));
    }
}
