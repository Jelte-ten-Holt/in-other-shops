<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled currencies
    |--------------------------------------------------------------------------
    |
    | Restrict the available currencies to a subset of the Currency enum,
    | listed by ISO 4217 code (e.g. ['EUR', 'USD']). Set to null (or an
    | empty array) to allow all currencies defined in the enum.
    |
    */

    'enabled' => null,

    /*
    |--------------------------------------------------------------------------
    | Money display locale
    |--------------------------------------------------------------------------
    |
    | Escape-hatch override for the locale whose conventions money display
    | strings use (separators and symbol placement). Leave null — the right
    | default for every current consumer — and Currency::format() follows
    | the application locale: 'en' renders "€12.50", 'de'/'es' render
    | "12,50 €". Set a fixed BCP-47 locale only when a project's content
    | language and number convention must diverge. Admin panels override
    | per-request via the SetMoneyDisplayLocale middleware.
    |
    */

    'display_locale' => null,

];
