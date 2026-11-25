<?php

return [
    // Absolute deploy-owned paths. The application never creates or writes them.
    'directory' => env('PLUGINS_DIR'),
    'approvals_file' => env('PLUGINS_APPROVALS_FILE'),
    'approvals_sha256' => env('PLUGINS_APPROVALS_SHA256'),
    'catalog_file' => env('PLUGINS_CATALOG_FILE'),
];
