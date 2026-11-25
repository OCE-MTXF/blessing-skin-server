<?php

namespace App\Services\Textures;

use GdImage;
use Illuminate\Http\UploadedFile;

final class TextureImages
{
    public const MAX_BYTES = 4 * 1024 * 1024;
    public const MAX_EDGE = 2048;
    public const MAX_PIXELS = 1024 * 1024;
    public const MAX_RENDER_PIXELS = 256 * 1024;
    public const AVATAR_SIZES = [32, 36, 40, 45, 48, 50, 64, 80, 96, 100, 128, 150, 160, 192, 200, 256];
    public const CAPE_HEIGHTS = [64, 100, 128, 150, 200, 256, 300, 320, 400];

    private const PNG = "\x89PNG\r\n\x1a\n";
    private const MAX_CHUNKS = 1024;

    public function uploaded(UploadedFile $file): TextureImage
    {
        if (!$file->isValid()) {
            throw new InvalidTextureImage('Invalid image upload.');
        }
        $stream = @fopen($file->getPathname(), 'rb');
        if (!is_resource($stream)) {
            throw new InvalidTextureImage('Cannot read image upload.');
        }

        return $this->read($stream);
    }

    /** Read at most the global byte budget, including when remote metadata is stale. */
    public function read($stream): TextureImage
    {
        try {
            $bytes = stream_get_contents($stream, self::MAX_BYTES + 1);
        } finally {
            fclose($stream);
        }

        return $this->inspect(is_string($bytes) ? $bytes : '');
    }

    public function inspect(string $bytes): TextureImage
    {
        $length = strlen($bytes);
        if ($length < 20 || $length > self::MAX_BYTES) {
            throw new InvalidTextureImage('Image is outside the byte budget.');
        }

        if (str_starts_with($bytes, self::PNG)) {
            $format = 'png';
            $decode = $this->pngForDecoder($bytes);
        } elseif (substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') {
            $format = 'webp';
            $decode = $this->webpForDecoder($bytes);
        } else {
            throw new InvalidTextureImage('Only PNG and WebP are supported.');
        }

        $size = @getimagesizefromstring($decode);
        if (!$size || $size[2] !== ($format === 'png' ? IMAGETYPE_PNG : IMAGETYPE_WEBP)) {
            throw new InvalidTextureImage('Invalid image header.');
        }
        [$width, $height] = $size;
        if ($width < 1 || $height < 1 || $width > self::MAX_EDGE || $height > self::MAX_EDGE || $width * $height > self::MAX_PIXELS) {
            throw new InvalidTextureImage('Image is outside the decoded pixel budget.');
        }

        return new TextureImage($bytes, $format, $width, $height, $decode);
    }

    public function matches(TextureImage $image, string $type): bool
    {
        $width = $image->width;
        $height = $image->height;
        if ($width % 64 !== 0 || $height % 32 !== 0) {
            return false;
        }

        return match ($type) {
            'steve' => $width === $height || $width === 2 * $height,
            'alex' => $width === $height,
            'cape' => $width === 2 * $height,
            default => false,
        };
    }

    public function decode(TextureImage $source): GdImage
    {
        // GD may issue a warning and return a partial image. Never consider it valid.
        $warned = false;
        set_error_handler(static function () use (&$warned): bool {
            $warned = true;

            return true;
        }, E_WARNING | E_NOTICE);
        try {
            $image = imagecreatefromstring($source->decodeBytes);
        } finally {
            restore_error_handler();
        }
        if ($warned || !$image || imagesx($image) !== $source->width || imagesy($image) !== $source->height) {
            throw new InvalidTextureImage('Image decoder rejected the source.');
        }
        if (!imageistruecolor($image) && !imagepalettetotruecolor($image)) {
            throw new InvalidTextureImage('Cannot normalize image colors.');
        }
        imagealphablending($image, false);
        imagesavealpha($image, true);

        return $image;
    }

    public function encode(GdImage $image, string $format): string
    {
        ob_start();
        try {
            $ok = match ($format) {
                'png' => @imagepng($image, null, 6),
                'webp' => @imagewebp($image, null, 90),
                default => throw new \InvalidArgumentException('Unsupported output format.'),
            };
            $bytes = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        if (!$ok || !is_string($bytes) || strlen($bytes) > self::MAX_BYTES
            || !str_starts_with($bytes, $format === 'png' ? self::PNG : 'RIFF')) {
            throw new InvalidTextureImage('Image encoder could not produce bounded output.');
        }

        return $bytes;
    }

    public function outputFormat(bool $png): string
    {
        return !$png && (imagetypes() & IMG_WEBP) !== 0 ? 'webp' : 'png';
    }

    public function canvas(int $width, int $height): GdImage
    {
        if ($width < 1 || $height < 1 || $width * $height > self::MAX_RENDER_PIXELS) {
            throw new \LogicException('Render dimensions exceed the pixel budget.');
        }
        $image = imagecreatetruecolor($width, $height);
        if (!$image) {
            throw new InvalidTextureImage('Cannot allocate rendition.');
        }
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, 0x7f000000);

        return $image;
    }

    private function pngForDecoder(string $bytes): string
    {
        $end = strlen($bytes);
        $offset = 8;
        $idat = false;
        $decode = self::PNG;
        for ($i = 0; $i < self::MAX_CHUNKS && $offset + 12 <= $end; ++$i) {
            $length = unpack('N', $bytes, $offset)[1];
            $type = substr($bytes, $offset + 4, 4);
            $next = $offset + 12 + $length;
            if ($next > $end || ($i === 0 && ($type !== 'IHDR' || $length !== 13))
                || ($i !== 0 && $type === 'IHDR') || in_array($type, ['acTL', 'fcTL', 'fdAT'], true)) {
                break;
            }
            // Do not pass compressed metadata, profiles or unknown ancillary chunks to GD.
            if (in_array($type, ['IHDR', 'PLTE', 'tRNS', 'IDAT', 'IEND'], true)) {
                if (hash('crc32b', substr($bytes, $offset + 4, $length + 4), true) !== substr($bytes, $next - 4, 4)) {
                    break;
                }
                $decode .= substr($bytes, $offset, $length + 12);
            } elseif (!preg_match('/\A[a-z][A-Za-z]{3}\z/', $type)) {
                break;
            }
            if ($type === 'IDAT') {
                $idat = true;
            }
            if ($type === 'IEND') {
                if ($length === 0 && $idat && $next === $end) {
                    return $decode;
                }
                break;
            }
            $offset = $next;
        }

        throw new InvalidTextureImage('Invalid or animated PNG container.');
    }

    private function webpForDecoder(string $bytes): string
    {
        $end = strlen($bytes);
        if (unpack('V', $bytes, 4)[1] !== $end - 8) {
            throw new InvalidTextureImage('Invalid WebP container length.');
        }
        $offset = 12;
        $image = false;
        $alpha = false;
        $canvas = null;
        $chunks = '';
        for ($i = 0; $i < self::MAX_CHUNKS && $offset + 8 <= $end; ++$i) {
            $type = substr($bytes, $offset, 4);
            $length = unpack('V', $bytes, $offset + 4)[1];
            $next = $offset + 8 + $length + ($length % 2);
            if ($next > $end || in_array($type, ['ANIM', 'ANMF'], true)) {
                break;
            }
            if ($type === 'VP8X') {
                if ($i !== 0 || $length !== 10 || (ord($bytes[$offset + 8]) & 0x02) !== 0) {
                    break;
                }
                $canvas = [unpack('V', substr($bytes, $offset + 12, 3)."\x00")[1] + 1,
                    unpack('V', substr($bytes, $offset + 15, 3)."\x00")[1] + 1];
                // Strip ICC, EXIF and XMP flags when removing those chunks.
                $chunks .= substr($bytes, $offset, 8).chr(ord($bytes[$offset + 8]) & 0x10).substr($bytes, $offset + 9, 9);
            } elseif ($type === 'VP8 ' || $type === 'VP8L') {
                if ($image || $length < ($type === 'VP8 ' ? 10 : 5)) {
                    break;
                }
                // GD needs a budget for the actual bitstream, not only the optional canvas header.
                $payload = $offset + 8;
                if ($type === 'VP8 ') {
                    if ((ord($bytes[$payload]) & 1) !== 0 || substr($bytes, $payload + 3, 3) !== "\x9d\x01\x2a") {
                        break;
                    }
                    [$w, $h] = array_values(unpack('v2', $bytes, $payload + 6));
                    $width = $w & 0x3fff;
                    $height = $h & 0x3fff;
                } else {
                    if ($bytes[$payload] !== "\x2f") {
                        break;
                    }
                    $bits = unpack('V', $bytes, $payload + 1)[1];
                    $width = ($bits & 0x3fff) + 1;
                    $height = (($bits >> 14) & 0x3fff) + 1;
                }
                if ($width < 1 || $height < 1 || $width > self::MAX_EDGE || $height > self::MAX_EDGE || $width * $height > self::MAX_PIXELS
                    || ($canvas !== null && $canvas !== [$width, $height]) || ($alpha && $type === 'VP8L')) {
                    break;
                }
                $image = true;
                $chunks .= substr($bytes, $offset, $next - $offset);
            } elseif ($type === 'ALPH') {
                if (!$canvas || $alpha || $image || $length < 1) {
                    break;
                }
                $alpha = true;
                $chunks .= substr($bytes, $offset, $next - $offset);
            }
            if ($next === $end && $image) {
                return 'RIFF'.pack('V', strlen($chunks) + 4).'WEBP'.$chunks;
            }
            $offset = $next;
        }

        throw new InvalidTextureImage('Invalid or animated WebP container.');
    }
}
