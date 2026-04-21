# Adding a New Domain

Checklist for introducing a new domain under `src/`.

1. Create `src/{Domain}/` with: service provider, config (with `models` key), contracts, concerns, models, migrations.
2. Add the PSR-4 namespace to `composer.json` autoload.
3. Add the service provider to `composer.json` `extra.laravel.providers`.
4. Add a `README.md` to the domain directory.
5. Update the dependency table in both `CLAUDE.md` and `README.md`.
6. Ship a `Filament/{Domain}Schema.php` if the domain has form components.

See `CLAUDE.md` for the architectural conventions (registry pattern, Has*/InteractsWith* naming, config-driven models, log subscribers, factories).
