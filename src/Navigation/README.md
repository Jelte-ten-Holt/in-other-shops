# Navigation Domain

Configurable navigation menus for the storefront (and potentially other frontends). Replaces the current hardcoded category-based navbar with manageable menu structures.

## Problem

The storefront layout currently queries top-level categories and renders them as the navbar. This works for simple shops but doesn't cover common needs: linking to static pages, custom ordering, mixing categories with non-category links, or having multiple distinct menus (header, footer, mobile).

## Design

### Models

- **`Menu`** — a named menu (e.g., "Main Navigation", "Footer Links"). Fields: `name`, `slug`, `location` (enum or string key like `header`, `footer`).
- **`MenuItem`** — a single link within a menu. Fields: `menu_id`, `parent_id` (nullable, for nesting), `label`, `url` (nullable), `linkable_type`/`linkable_id` (nullable, polymorphic — point to a Category, a Page, or any model), `position`, `open_in_new_tab`.

A MenuItem has either a hardcoded `url` OR a polymorphic `linkable` reference (which resolves its URL at render time). Not both.

### Contracts

- **`Linkable`** — any model that can be linked from a menu item. Methods: `getLinkableUrl(): string`, `getLinkableLabel(): string`. Category would implement this. So would a future Page model.

### Filament

- **`MenuResource`** — manage menus and their items. Nested drag-and-drop ordering for menu items (tree builder or repeater with parent selection).

### Integration

- The storefront layout queries `Menu::where('location', 'header')` instead of querying categories directly.
- A `NavigationComposer` (or the existing `StorefrontComposer`) provides the resolved menu to the layout.
- Projects define which locations exist and where they render.

### Not in scope

- Visual menu builder (drag-and-drop tree) — a Filament repeater with parent/position is sufficient.
- Caching strategy — add when performance demands it. Menus change rarely.
- Access control on menu items (show/hide based on auth state) — handle in the blade template, not the domain.

## Migration from current approach

1. Build the domain (models, migration, service provider).
2. Seed a "header" menu from existing categories.
3. Swap the storefront composer to query menus instead of categories.
4. The category sidebar on the product list page stays as-is — that's product filtering, not navigation.
