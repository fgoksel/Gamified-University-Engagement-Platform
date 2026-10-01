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
            self::Academic => 'Academic',
            self::Scientific => 'Scientific',
            self::Sports => 'Sports',
            self::Community => 'Community / Student Life',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::Academic->value => 'Academic',
            self::Scientific->value => 'Scientific',
            self::Sports->value => 'Sports',
            self::Community->value => 'Community / Student Life',
        ];
    }
}
