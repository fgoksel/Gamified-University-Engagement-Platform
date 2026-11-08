<?php

namespace App\Support;

/**
 * A made-up language for finding text that is not in the dictionary (Task #95).
 *
 * With APP_LOCALE=qps every text that goes through Laravel's translator comes out accented and
 * wrapped in ⟦ ⟧: ours, and also the ones of Laravel and of the admin panel. A screen then shows at
 * a glance which text is still typed into the code: it has neither brackets nor accents.
 *
 * The texts are made from the English ones while the request runs (see PseudoLoader), so there is
 * no file to keep up to date. Placeholders (:name), HTML tags and entities, and the numbers of the
 * plural forms ({0}, [2,*]) stay as they are, so the made-up texts still work. The Vue side does
 * the same in resources/js/pseudoLocale.js. Keep the two alike.
 */
class PseudoLocale
{
    /** The code of the made-up language, as used in APP_LOCALE and in <html lang>. */
    public const CODE = 'qps';

    private const LETTERS = [
        'a' => 'á', 'b' => 'ƀ', 'c' => 'ç', 'd' => 'đ', 'e' => 'é', 'f' => 'ƒ', 'g' => 'ĝ', 'h' => 'ĥ',
        'i' => 'í', 'j' => 'ĵ', 'k' => 'ķ', 'l' => 'ł', 'm' => 'ɱ', 'n' => 'ñ', 'o' => 'ó', 'p' => 'ƥ',
        'q' => 'ɋ', 'r' => 'ŕ', 's' => 'š', 't' => 'ţ', 'u' => 'ú', 'v' => 'ṽ', 'w' => 'ŵ', 'x' => 'ẋ',
        'y' => 'ý', 'z' => 'ž',
        'A' => 'Á', 'B' => 'Ɓ', 'C' => 'Ç', 'D' => 'Đ', 'E' => 'É', 'F' => 'Ƒ', 'G' => 'Ĝ', 'H' => 'Ĥ',
        'I' => 'Í', 'J' => 'Ĵ', 'K' => 'Ķ', 'L' => 'Ł', 'M' => 'Ṁ', 'N' => 'Ñ', 'O' => 'Ó', 'P' => 'Ƥ',
        'Q' => 'Ɋ', 'R' => 'Ŕ', 'S' => 'Š', 'T' => 'Ţ', 'U' => 'Ú', 'V' => 'Ṽ', 'W' => 'Ŵ', 'X' => 'Ẋ',
        'Y' => 'Ý', 'Z' => 'Ž',
    ];

    /**
     * The made-up version of one text. A text with plural forms ("{0} none|{1} one|[2,*] :count")
     * is converted form by form, each keeping the numbers it starts with.
     */
    public static function convert(string $text): string
    {
        return implode('|', array_map(self::form(...), explode('|', $text)));
    }

    /**
     * The made-up version of a whole dictionary, or of a group of texts that holds other groups
     * (the files of Laravel and of the admin panel are arrays inside arrays).
     *
     * @param  array<array-key, mixed>  $lines
     * @return array<array-key, mixed>
     */
    public static function lines(array $lines): array
    {
        return array_map(fn ($line) => match (true) {
            is_string($line) => self::convert($line),
            is_array($line) => self::lines($line),
            default => $line,
        }, $lines);
    }

    private static function form(string $form): string
    {
        $numbers = '';
        $gap = '';

        if (preg_match('/^([{\[][^\[\]{}]*[}\]])(\s*)(.*)$/s', $form, $found)) {
            [, $numbers, $gap, $form] = $found;
        }

        // What must keep its shape stays readable; only the words around it change.
        $parts = preg_split('/(:[A-Za-z_][A-Za-z0-9_]*|<[^>]*>|&[#A-Za-z0-9]+;)/', $form, -1, PREG_SPLIT_DELIM_CAPTURE);
        $converted = '';

        foreach ($parts as $index => $part) {
            $converted .= $index % 2 === 1 ? $part : strtr($part, self::LETTERS);
        }

        return $numbers.$gap.'⟦'.$converted.'⟧';
    }
}
