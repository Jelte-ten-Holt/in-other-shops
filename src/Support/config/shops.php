<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Admin panel locale
    |--------------------------------------------------------------------------
    |
    | The locale the Filament admin renders in, independent of the storefront
    | (app) locale. The storefront may run one language while operators run the
    | admin in another — bianka's shop is Spanish-first but its admin can be
    | either. Package admin strings ship `en` + `es`; `en` is the
    | source-of-truth and fallback, so a missing `es` key degrades to English
    | rather than showing a raw `shops-*::` key.
    |
    | Set per deployment. Consumers register Support\Http\Middleware\
    | SetPanelLocale as a persistent panel middleware to apply it.
    |
    */
    'admin_locale' => env('SHOPS_ADMIN_LOCALE', 'en'),
];
