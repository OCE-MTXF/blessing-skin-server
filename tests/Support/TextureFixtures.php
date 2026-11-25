<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Storage;

final class TextureFixtures
{
    public static function bytes(string $format = 'png', int $width = 64, int $height = 64): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocate($image, 38, 95, 173));
        imagefilledrectangle($image, 0, 0, intdiv($width, 2), intdiv($height, 2), imagecolorallocate($image, 220, 160, 90));
        ob_start();
        try {
            if ($format === 'webp') {
                imagewebp($image, null, IMG_WEBP_LOSSLESS);
            } else {
                imagepng($image);
            }

            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    public static function put(string $format = 'png', int $width = 64, int $height = 64): string
    {
        $bytes = self::bytes($format, $width, $height);
        $hash = hash('sha256', $bytes);
        Storage::disk('textures')->put($hash, $bytes);

        return $hash;
    }
}
