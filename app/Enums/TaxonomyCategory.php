<?php

namespace App\Enums;

/**
 * The four core event & subject area category trees defined in UC-3.4 & Technical Specification 6.4:
 * Academic, Scientific, Sports, Community / Student Life.
 */
enum TaxonomyCategory: string
{
    case Academic = 'academic';
    case Scientific = 'scientific';
    case Sports = 'sports';
    case Community = 'community';

    public function label(): string
    {
        return match ($this) {
            self::Academic => __('categories.academic'),
            self::Scientific => __('categories.scientific'),
            self::Sports => __('categories.sports'),
            self::Community => __('categories.community'),
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_combine(
            array_map(fn (self $category) => $category->value, self::cases()),
            array_map(fn (self $category) => $category->label(), self::cases()),
        );
    }
}
