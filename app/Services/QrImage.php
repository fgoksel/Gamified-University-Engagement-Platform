<?php

namespace App\Services;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use RuntimeException;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * Turns the text of a QR code into an image on the server, so the code never
 * has to be drawn by a third-party service or by the browser.
 *
 * SVG is the print format: it stays sharp at any size. PNG is drawn with GD
 * from the same QR matrix, so it needs no Imagick extension.
 */
class QrImage
{
    public const MIN_SIZE = 128;

    public const MAX_SIZE = 1024;

    public const DEFAULT_SIZE = 512;

    /** Quiet zone, in modules, around the code (4 is the QR standard). */
    private const QUIET_ZONE = 4;

    public function svg(string $text, int $size = self::DEFAULT_SIZE): string
    {
        return (string) QrCode::format('svg')
            ->size($this->clamp($size))
            ->margin(self::QUIET_ZONE)
            ->errorCorrection('M')
            ->encoding('UTF-8')
            ->generate($text);
    }

    public function png(string $text, int $size = self::DEFAULT_SIZE): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('The GD extension is needed to draw PNG QR codes.');
        }

        $matrix = Encoder::encode($text, ErrorCorrectionLevel::M(), 'UTF-8')->getMatrix();
        $modules = $matrix->getWidth();
        $cells = $modules + 2 * self::QUIET_ZONE;
        $cell = max(1, intdiv($this->clamp($size), $cells));
        $pixels = $cell * $cells;

        // A two-colour palette image uses a quarter of the memory of a true-colour one.
        $image = imagecreate($pixels, $pixels);
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefilledrectangle($image, 0, 0, $pixels - 1, $pixels - 1, $white);

        for ($y = 0; $y < $modules; $y++) {
            for ($x = 0; $x < $modules; $x++) {
                if ($matrix->get($x, $y) === 1) {
                    $left = ($x + self::QUIET_ZONE) * $cell;
                    $top = ($y + self::QUIET_ZONE) * $cell;
                    imagefilledrectangle($image, $left, $top, $left + $cell - 1, $top + $cell - 1, $black);
                }
            }
        }

        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        unset($image);

        return $png;
    }

    private function clamp(int $size): int
    {
        return max(self::MIN_SIZE, min(self::MAX_SIZE, $size));
    }
}
