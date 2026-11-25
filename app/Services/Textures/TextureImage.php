<?php

namespace App\Services\Textures;

/** A bounded, single-frame PNG or WebP container. */
final readonly class TextureImage
{
    public function __construct(
        public string $bytes,
        public string $format,
        public int $width,
        public int $height,
        public string $decodeBytes,
    ) {
    }
}
