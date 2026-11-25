<?php

// Compatibility for plugins which inspect the former image driver setting.
// The application uses the bounded GD adapter in App\Services\Textures.
return ['driver' => 'gd'];
