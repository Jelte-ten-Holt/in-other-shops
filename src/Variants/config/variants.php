<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Variants
|--------------------------------------------------------------------------
|
| Options and their values (bianka's Family axis). The `models` registry went
| with the Variant model (2026-11, complexity cleanup release 2): neither
| consumer ever overrode it, so `Option` and `OptionValue` are referenced
| directly.
|
*/

return [
    /*
    |--------------------------------------------------------------------------
    | Domain switch
    |--------------------------------------------------------------------------
    |
    | false switches the whole domain off: no migrations, translations or
    | views load, its log subscriber, listeners, commands and schedules are
    | not registered (an app schedule line naming one of its commands then
    | fails). The morph aliases and its tables stay. Meant for a domain the
    | shop does not use, such as Purchasing: a domain the enabled ones build
    | on (Translation, Location, Media, Logging, Taxonomy) is not safe to
    | switch off. Config only; there is no env variable.
    |
    */

    'enabled' => true,
];
