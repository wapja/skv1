# Roles-pagina redesign — gelijktrekken met users-pagina, aparte create-pagina

**Datum:** 2026-05-09
**Status:** Concept — wacht op review

## Doel

De roles-overzichtspagina (`/admin/roles`) krijgt dezelfde header- en lijstopbouw als
de users-overzichtspagina, en het aanmaken van een rol verhuist naar een eigen
pagina (`/admin/roles/create`). De inline create-form op de index verdwijnt.

## Motivatie

- **Visuele consistentie:** users, organisations en roles zijn alle drie
  admin-overzichten. Users en organisations gebruiken al hetzelfde header-patroon
  (titel + omschrijving links, primary action-knop rechts). Roles wijkt af met
  een inline create-form als eerste blok onder de heading.
- **Single responsibility per component:** `Roles\Index` doet nu zowel
  "lijst tonen" als "nieuwe rol aanmaken" met eigen state (`$newRoleName`,
  `$newRolePermissions`) en gedrag (`createRole()`). Door de create-flow naar
  `Roles\Edit` te verplaatsen wordt `Index` puur een lijstcomponent en kan
  `Edit` één gedeelde validatie/save-implementatie aanbieden voor zowel maken
  als bewerken.
- **Bestaand patroon hergebruiken:** `Organisations\Edit` is al dual-mode (zie
  `app/Livewire/Organisations/Edit.php` + `routes/web.php` regels voor
  `organisations.create` / `organisations.edit`). Die blauwdruk wordt 1-op-1
  gevolgd voor roles, geen nieuw patroon.

## Scope

### In scope

1. `resources/views/livewire/roles/index.blade.php` — header in flex met
   primary-knop "Nieuwe rol" rechts; inline create-form verwijderen;
   empty-state callout toevoegen.
2. `routes/web.php` — extra route `roles.create` die naar `Roles\Edit` wijst.
3. `app/Livewire/Roles/Edit.php` — dual-mode: `mount(?Role $role = null)`
   gevolgd door create- of update-pad in `save()`. Validatie geldt voor beide
   modi.
4. `app/Livewire/Roles/Index.php` — properties `$newRoleName`,
   `$newRolePermissions` en methodes `createRole()` en `allPermissions()`
   verwijderen. Render-data beperken tot `roles`.
5. `resources/views/livewire/roles/edit.blade.php` — dynamische heading
   ("Nieuwe rol" of "Rol bewerken: {name}"); eventueel "Terug"-knop blijft.
6. Tests — `tests/Feature/Roles/RoleManagementTest.php` regel 83-85
   (de huidige `createRole`-test op `Roles\Index`) verhuizen naar een test
   die `Roles\Edit` in create-modus aanstuurt. Bestaande dekking blijft op
   peil; gedrag verandert niet.

### Out of scope

- Filters of kolomkiezer toevoegen aan de roles-lijst (users heeft die wel,
  roles heeft ze nu niet — YAGNI).
- Verplaatsen van permissie-keuze naar wizard-stappen.
- Gedrag rondom soft-deleted rollen, sjabloonrol-bescherming, of
  per-org-overrides — die blijven exact zoals ze zijn.
- Wijzigingen aan `RolePolicy`, seeders, of `RoleBackfiller`.

## Beslissingen

| Beslissing | Keuze | Reden |
|---|---|---|
| Knoplabel in header | "Nieuwe rol" | Consistent met "Nieuwe organisatie" op organisations-pagina |
| Redirect na create | `roles.index` | Gelijk aan organisations.create-flow |
| Empty-state | `flux:callout variant="secondary" icon="shield-check"` met tekst "Geen rollen gevonden." | Consistent met users/organisations |
| Spacing-utility | `space-y-6` (zoals users), niet `space-y-8` | Harmoniseert met users-pagina |

## Architectuur

### Routes

```
GET /admin/roles                → Roles\Index    (roles.index)
GET /admin/roles/create         → Roles\Edit     (roles.create)   ← nieuw
GET /admin/roles/{role}/edit    → Roles\Edit     (roles.edit)
```

### Componenten

**`Roles\Index`** (slimmer, kleiner):
- Properties: geen wijzigingen behalve het verwijderen van de create-state.
- Methodes: alleen `roles()`, `deleteRole()`, `render()`.
- Render-data: `['roles' => …]` (permissions-lijst is alleen in Edit nodig).

**`Roles\Edit`** (dual-mode):
- Property `?Role $role = null`.
- `mount(?Role $role = null)` — als `$role && $role->exists`: laad
  `name` + `selectedPermissions`, autoriseer `update`. Anders: autoriseer
  `create`.
- `save()` — pad-splitsing in een DB::transaction:
  - **Create:** `Role::create(['name' => …, 'guard_name' => 'web',
    'team_id' => tenant()?->id])` gevolgd door `syncPermissions(...)`.
  - **Update:** bestaande logica (update naam, sync permissions).
- Validatie identiek voor beide modi: `required|string|max:255|alpha_dash`,
  unique binnen `(guard_name, team_id)` met optionele ignore, `notIn` op
  gereserveerde namen, en de "clash met sjabloonrol"-closure. De ignore-id
  is `null` in create-modus, wat `Rule::unique(...)->ignore(null)` correct
  afhandelt.

### View `roles/index.blade.php` — nieuwe structuur

```blade
<div class="space-y-6">
    <div class="flex items-end justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Rollen en permissies') }}</flux:heading>
            <flux:text class="mt-1 ...">…</flux:text>
        </div>
        @can('create', Spatie\Permission\Models\Role::class)
            <flux:button variant="primary" :href="route('roles.create')" wire:navigate>
                {{ __('Nieuwe rol') }}
            </flux:button>
        @endcan
    </div>

    @if ($roles->isEmpty())
        <flux:callout variant="secondary" icon="...">{{ __('Geen rollen gevonden.') }}</flux:callout>
    @else
        <flux:table>… (ongewijzigd) …</flux:table>
    @endif
</div>
```

### View `roles/edit.blade.php` — minimale wijziging

- Heading wordt: `{{ $role ? __('Rol bewerken: :name', ['name' => $role->name])
  : __('Nieuwe rol') }}`.
- Form-body en velden ongewijzigd.
- "Terug"-knop blijft.

## Tests

### Test-impact

- **Te verwijderen of verplaatsen:**
  `RoleManagementTest.php` regel 83-85 — de
  `Livewire::test(Index::class)->set('newRoleName', …)->call('createRole')`-pad
  bestaat niet meer.
- **Toe te voegen:**
  - Test op `Roles\Edit` zonder bestaande rol: render via `roles.create`-route,
    `set('name', ...)->set('selectedPermissions', [...])->call('save')`,
    assert dat de rol bestaat met `team_id = tenant()->id` en de juiste
    permissies, en dat redirect naar `roles.index` plaatsvindt.
  - Authorize-test: gebruiker zonder `create`-permissie krijgt 403 op
    `roles.create`.
- **Onaangeroerd:** alle bestaande update-, validatie-, soft-delete-,
  uniqueness-, en seeder-tests.

## Risico's en aandachtspunten

- **Rule::unique met `ignore(null)`** moet correct werken voor de
  create-modus. Laravel laat dit toe — `ignore(null)` wordt genegeerd. Geen
  speciale aanpak nodig.
- **`tenant()?->id` in create-pad:** identiek aan de huidige
  `createRole()`-implementatie in `Index.php`, dus geen gedragswijziging.
- **Sjabloon-clash-validatie** moet ook bij create lopen (een nieuwe per-org
  rol mag niet dezelfde naam hebben als een sjabloonrol). De huidige
  `Index::createRole()` mist deze check — de verhuizing naar `Edit`
  versterkt dus de validatie. Dit is een wenselijke side-effect, geen
  regressie. Een test pinning dit gedrag is waardevol.
- **Localisatie:** alle nieuwe strings in `__()` wrappen. Geen aparte
  lang-files raken behalve waar al gebruikelijk.

## Verificatie

- `php artisan test --filter=Role` slaagt.
- `php artisan test` slaagt.
- `vendor/bin/pint --test` is schoon.
- Handmatig in browser: create-knop verschijnt, navigeert naar
  `/admin/roles/create`, formulier slaat op, redirect naar lijst.
  Flash-bericht: "Rol aangemaakt." in create-modus, "Rol bijgewerkt." in
  update-modus (matcht de huidige strings van respectievelijk
  `Index::createRole()` en `Edit::save()`).

## Open vragen

Geen.
