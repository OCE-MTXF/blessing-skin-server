<?php

namespace App\Services\Textures;

use GdImage;

final class TextureRenderer
{
    public function __construct(private TextureImages $images)
    {
    }

    public function preview(TextureImage $source, string $type, int $height): GdImage
    {
        $image = $this->images->decode($source);
        if ($type === 'cape') {
            $ratio = intdiv($source->width, 64);
            $width = intdiv(10 * $height, 16);
            $canvas = $this->images->canvas($width + 80, $height + 40);
            imagecopyresampled($canvas, $image, 40, 20, $ratio, $ratio, $width, $height, 10 * $ratio, 16 * $ratio);

            return $canvas;
        }

        $atlas = $this->atlas($image, $source);
        $canvas = $this->images->canvas(400, 312);
        $modern = $source->width === $source->height;
        $this->body($canvas, $atlas, 86, $modern, $type === 'alex', false);
        $this->body($canvas, $atlas, 286, $modern, $type === 'alex', true);

        return $canvas;
    }

    public function avatar(?TextureImage $source, int $size, bool $threeDimensional): GdImage
    {
        if ($source === null) {
            $mode = $threeDimensional ? '3d' : '2d';
            $image = $this->images->decode($this->images->inspect(file_get_contents(resource_path("misc/textures/avatar$mode.png"))));
        } else {
            $image = $this->images->decode($source);
            $atlas = $this->atlas($image, $source);
            if ($threeDimensional) {
                $image = $this->images->canvas(100, 100);
                imagealphablending($image, true);
                $this->box($image, $atlas, 2, 32, 8, [0, 0, 8, 8, 8], [32, 0]);
            } else {
                $image = $this->images->canvas(8, 8);
                imagecopy($image, $atlas, 0, 0, 8, 8, 8, 8);
                imagealphablending($image, true);
                imagecopy($image, $atlas, 0, 0, 40, 8, 8, 8);
            }
        }
        $canvas = $this->images->canvas($size, $size);
        imagecopyresized($canvas, $image, 0, 0, 0, 0, $size, $size, imagesx($image), imagesy($image));

        return $canvas;
    }

    private function atlas(GdImage $image, TextureImage $source): GdImage
    {
        $height = $source->width === $source->height ? 64 : 32;
        $atlas = $this->images->canvas(64, $height);
        imagecopyresampled($atlas, $image, 0, 0, 0, 0, 64, $height, $source->width, $source->height);

        return $atlas;
    }

    private function body(GdImage $canvas, GdImage $atlas, int $center, bool $modern, bool $slim, bool $back): void
    {
        imagealphablending($canvas, true);
        $scale = 8;
        $arm = $slim ? 3 : 4;
        $this->box($canvas, $atlas, $center - 4 * $scale, 184, $scale, $modern && $back ? [16, 48, 4, 12, 4] : [0, 16, 4, 12, 4], $modern ? ($back ? [0, 48] : [0, 32]) : null, $back, !$modern && $back);
        $this->box($canvas, $atlas, $center, 184, $scale, $modern && !$back ? [16, 48, 4, 12, 4] : [0, 16, 4, 12, 4], $modern ? ($back ? [0, 32] : [0, 48]) : null, $back, !$modern && !$back);
        $this->box($canvas, $atlas, $center - (4 + $arm) * $scale, 88, $scale, $modern && $back ? [32, 48, $arm, 12, 4] : [40, 16, $arm, 12, 4], $modern ? ($back ? [48, 48] : [40, 32]) : null, $back, !$modern && $back);
        $this->box($canvas, $atlas, $center - 4 * $scale, 88, $scale, [16, 16, 8, 12, 4], $modern ? [16, 32] : null, $back);
        $this->box($canvas, $atlas, $center + 4 * $scale, 88, $scale, $modern && !$back ? [32, 48, $arm, 12, 4] : [40, 16, $arm, 12, 4], $modern ? ($back ? [40, 32] : [48, 48]) : null, $back, !$modern && !$back);
        $this->box($canvas, $atlas, $center - 4 * $scale, 24, $scale, [0, 0, 8, 8, 8], [32, 0], $back);
    }

    private function box(GdImage $dest, GdImage $atlas, int $x, int $y, int $scale, array $uv, ?array $layer, bool $back = false, bool $mirror = false): void
    {
        [$u, $v, $width, $height, $depth] = $uv;
        $sideX = intdiv($scale, 2);
        $sideY = -intdiv($scale, 3);
        $faceU = $back ? $width + 2 * $depth : $depth;
        $sideU = $back ? 0 : $width + $depth;
        $this->face($dest, $atlas, $u + $depth, $v, $width, $depth, $x, $y, $scale, 0, $sideX, $sideY, $mirror, $layer ? [$layer[0] + $depth, $layer[1]] : null);
        $this->face($dest, $atlas, $u + $sideU, $v + $depth, $depth, $height, $x + $width * $scale, $y, $sideX, $sideY, 0, $scale, $mirror, $layer ? [$layer[0] + $sideU, $layer[1] + $depth] : null);
        $this->face($dest, $atlas, $u + $faceU, $v + $depth, $width, $height, $x, $y, $scale, 0, 0, $scale, $mirror, $layer ? [$layer[0] + $faceU, $layer[1] + $depth] : null);
    }

    /** Draw faces from a fixed 64×64-or-smaller atlas; no per-source-pixel PHP work. */
    private function face(GdImage $dest, GdImage $atlas, int $u, int $v, int $width, int $height, int $x, int $y, int $xx, int $xy, int $yx, int $yy, bool $mirror, ?array $layer): void
    {
        for ($j = 0; $j < $height; ++$j) {
            for ($i = 0; $i < $width; ++$i) {
                $a = $x + $i * $xx + $j * $yx;
                $b = $y + $i * $xy + $j * $yy;
                $p = $mirror ? $width - 1 - $i : $i;
                $points = [$a, $b, $a + $xx, $b + $xy, $a + $xx + $yx, $b + $xy + $yy, $a + $yx, $b + $yy];
                $color = imagecolorat($atlas, $u + $p, $v + $j);
                if (($color >> 24) < 127) {
                    imagefilledpolygon($dest, $points, $color);
                }
                if ($layer) {
                    $color = imagecolorat($atlas, $layer[0] + $p, $layer[1] + $j);
                    if (($color >> 24) < 127) {
                        imagefilledpolygon($dest, $points, $color);
                    }
                }
            }
        }
    }
}
