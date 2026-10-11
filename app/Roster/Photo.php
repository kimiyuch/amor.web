<?php

namespace App\Roster;

use Imagick;

/**
 * The full-resolution roster photo. Claude sees a downscaled overview and asks for crops
 * of the original through the zoom tool.
 */
final class Photo
{
    public const MAX_SIDE = 2000;

    public const MAX_UPSCALE = 2;

    private Imagick $image;

    public function __construct(string $path)
    {
        $this->image = new Imagick($path);
        $this->image->autoOrient();
        $this->image->setImagePage(0, 0, 0, 0);
    }

    public function width(): int
    {
        return $this->image->getImageWidth();
    }

    public function height(): int
    {
        return $this->image->getImageHeight();
    }

    /**
     * Crops a box normalized to the whole photo (0..1) as JPEG, at most $maxSide px on the
     * long side and at most MAX_UPSCALE× the original pixels.
     *
     * @param  array{x0: float, y0: float, x1: float, y1: float}  $box
     * @return array{jpeg: string, width: int, height: int}
     */
    public function crop(array $box, int $maxSide = self::MAX_SIDE): array
    {
        $x = (int) round($box['x0'] * $this->width());
        $y = (int) round($box['y0'] * $this->height());
        $w = max(1, (int) round(($box['x1'] - $box['x0']) * $this->width()));
        $h = max(1, (int) round(($box['y1'] - $box['y0']) * $this->height()));

        $crop = clone $this->image;
        $crop->cropImage($w, $h, $x, $y);
        $crop->setImagePage(0, 0, 0, 0);
        $scale = min($maxSide / max($w, $h), self::MAX_UPSCALE);
        $crop->resizeImage(max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)), Imagick::FILTER_LANCZOS, 1);
        $crop->setImageFormat('jpeg');
        $crop->setImageCompressionQuality(88);
        $crop->stripImage();

        return ['jpeg' => $crop->getImageBlob(), 'width' => $crop->getImageWidth(), 'height' => $crop->getImageHeight()];
    }

    /**
     * Clamps and orders a normalized box; null when it is unusable.
     *
     * @return array{x0: float, y0: float, x1: float, y1: float}|null
     */
    public static function cleanBox(mixed $box): ?array
    {
        if (! is_array($box)) {
            return null;
        }
        $values = [$box['x0'] ?? null, $box['y0'] ?? null, $box['x1'] ?? null, $box['y1'] ?? null];
        foreach ($values as $v) {
            if (! is_int($v) && ! is_float($v)) {
                return null;
            }
        }
        $clamp = fn ($v) => min(1.0, max(0.0, (float) $v));
        [$x0, $x1] = [$clamp(min($values[0], $values[2])), $clamp(max($values[0], $values[2]))];
        [$y0, $y1] = [$clamp(min($values[1], $values[3])), $clamp(max($values[1], $values[3]))];
        if ($x1 - $x0 < 0.002 || $y1 - $y0 < 0.002) {
            return null;
        }

        return compact('x0', 'y0', 'x1', 'y1');
    }
}
