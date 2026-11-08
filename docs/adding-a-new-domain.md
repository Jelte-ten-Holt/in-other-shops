# Adding a New Domain

Checklist for introducing a new domain under `src/`.

**First, place it in a tier** (see `CLAUDE.md` §The Four Tiers). Decide up front whether the new domain is a generic leaf, middle band, shop core, or integration-tier domain — it sets what it's allowed to depend on. A leaf must stay domain-neutral (that's the one seam worth protecting); shop-core additions may couple freely to the rest of the shop core; integration-tier additions may depend on many domains. If a "leaf" wants to import shop concepts, it isn't a leaf.

1. Create `src/{Domain}/` with: service provider (extend `Support\DomainServiceProvider` unless there's a documented reason not to), config (with the `'enabled' => true` domain switch first; no `models` key — see CLAUDE.md *Registry pattern*), contracts, concerns, models, migrations. Put the provider's extras (listeners, routes, observers, schedules) in `bootDomain()` after `parent::bootDomain()`, never in `boot()`: only `bootDomain()` is behind `{key}.enabled`.
2. Add the PSR-4 namespace to `composer.json` autoload.
3. Add the service provider to `composer.json` `extra.laravel.providers`.
4. Add a `README.md` to the domain directory.
5. Update the tier-grouped dependency graph in `CLAUDE.md` and the table in `README.md` — put the new domain in its tier and list its deps (including any config-key reads, which the `use`-statement graph misses).
6. Ship a `Filament/{Domain}Schema.php` if the domain has form components.

Keep the symmetry: a new domain should have a README, the `enabled` switch in its config, and a provider extending the base (or an explicit, documented reason it doesn't). A test that switches the domain off and finds nothing registered is the cheap way to prove an extra landed in `bootDomain()`. An architecture test to enforce this mechanically is planned (audit DES-M5). See `CLAUDE.md` for the rest of the conventions (registry pattern, Has*/InteractsWith* naming, config-driven models, log subscribers, factories).
