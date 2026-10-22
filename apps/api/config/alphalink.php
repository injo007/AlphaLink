<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Contact lookup key
    |--------------------------------------------------------------------------
    |
    | Contact addresses are encrypted at rest. This separate secret creates a
    | blind index so the app can detect duplicates without storing plaintext.
    | Keep stable across deployments and rotate with a dual-index migration.
    |
    */
    'contact_lookup_key' => env('CONTACT_LOOKUP_KEY'),
    'contact_lookup_key_version' => (int) env('CONTACT_LOOKUP_KEY_VERSION', 1),
];
