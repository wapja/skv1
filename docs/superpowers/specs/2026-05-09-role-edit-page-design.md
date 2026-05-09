# Role Edit-page: rename, permissies-toggle en soft-delete

**Status:** Design — wacht op review
**Datum:** 2026-05-09
**Doelgebied:** `app/Livewire/Roles`, `app/Models/Role.php` (nieuw), `app/Policies/RolePolicy.php`, `config/permission.php`, `database/migrations`, `routes/web.php`, tests

## 1. Doel

Drie ontbrekende beheerfuncties toevoegen voor rollen, voor gebruikers met de `roles.manage`-permissie:

1. **Permissies van een bestaande rol aan/uit zetten** (vandaag is dit alleen mogelijk bij aanmaken).
2. **Rol hernoemen.**
3. **Rol verwijderen via soft-delete**, en alleen wanneer er geen users meer aan gekoppeld zijn.

Sjabloonrollen (`team_id IS NULL`) blijven onaantastbaar — dat is al afgedwongen in `RolePolicy::update()` en `delete()`.

## 2. Niet-doelen

- Geen restore-/prullenbak-UI in deze iteratie. Een soft-deleted rol kan via tinker worden hersteld; in de Index is hij niet zichtbaar.
- Geen wijziging aan `UserRoleSyncer`, `OrganisationObserver` of de seeders.
- Geen wijziging in het sjabloonrollen-mechaniek of de propagatie-logica bij organisatie-aanmaak.
- Geen aparte `roles.show`-pagina; de Edit-page volstaat.

## 3. Architectuur en bestandsoverzicht

### Nieuw

| Bestand | Doel |
| --- | --- |
| `app/Models/Role.php` | Custom Role-model dat `Spatie\Permission\Models\Role` uitbreidt en `Illuminate\Database\Eloquent\SoftDeletes` toepast. |
| `database/migrations/2026_05_09_xxxxxx_add_soft_deletes_to_roles_table.php` | Voegt `deleted_at` toe aan `roles`. |
| `app/Livewire/Roles/Edit.php` | Full-page Livewire-component voor één rol: rename + permissies-toggle. |
| `resources/views/livewire/roles/edit.blade.php` | Flux-form met naam-input, permissies-checkboxes en één primaire **Opslaan**-knop. |
| `tests/Feature/Roles/RoleEditTest.php` | Feature-tests voor de Edit-page (autorisatie, rename, syncPermissions). |

### Aangepast

| Bestand | Wijziging |
| --- | --- |
| `config/permission.php` | `'role' => App\Models\Role::class` (was Spatie's standaard). |
| `app/Livewire/Roles/Index.php` | `roles()` laadt `withCount('users')`; `savePermissions()` wordt verwijderd; `deleteRole()` weigert wanneer `users_count > 0`. |
| `resources/views/livewire/roles/index.blade.php` | **Bewerken**-knop per rij; **Verwijderen** disabled met tooltip wanneer `$role->users_count > 0`; rendering van een nieuwe `error`-flash. |
| `routes/web.php` | Route `GET /admin/roles/{role}/edit` toegevoegd, naam `roles.edit`. |
| `tests/Feature/Roles/RoleManagementTest.php` | Extra tests voor delete-guard, soft-delete-effect en `withCount`. |

### Bewust ongewijzigd

- `app/Policies/RolePolicy.php` — de bestaande `update()`/`delete()`-logica blokkeert al sjablonen en cross-org en werkt automatisch met de subclass.
- `App\Services\UserRoleSyncer`, `App\Observers\OrganisationObserver`, `Database\Seeders\RolesAndPermissionsSeeder` — deze gebruiken Spatie's static factories die via `config/permission.php` automatisch naar `App\Models\Role` routeren.
- Het bestaande create-formulier in `Index` — dat blijft inline; alleen rename + permissies-edit verhuizen naar de Edit-page.

## 4. Datalaag

### 4.1 Custom Role-model

```php
namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    use SoftDeletes;
}
```

Geen extra `$fillable`, `$dates` of relaties. SoftDeletes voegt automatisch een global scope toe die `deleted_at IS NULL` op alle queries plakt.

### 4.2 Migratie

```php
Schema::table('roles', function (Blueprint $table) {
    $table->softDeletes();
});
```

`down()` met `dropSoftDeletes()`. Geen aparte index op `deleted_at`: de tabel is klein (een handvol rollen per organisatie) en queries draaien al op `team_id`.

### 4.3 Spatie-config

In `config/permission.php`:

```php
'role' => App\Models\Role::class,
```

Geen andere wijzigingen — teams, table_names, cache blijven gelijk.

### 4.4 Cache- en pivot-gedrag

- Spatie luistert op `creating/updating/deleting`-events van de geconfigureerde role-class. Soft-delete vuurt `deleting` en `deleted` af, dus de permission-cache flusht correct.
- Een soft-deleted rol verdwijnt uit `Role::all()` en uit `$user->roles` (de `BelongsToMany`-resolver respecteert de global scope). De pivot-rijen in `model_has_roles` en `role_has_permissions` blijven staan, zodat een eventuele restore (via tinker) de koppelingen automatisch herstelt.

## 5. Routes & autorisatie

### 5.1 Route

In `routes/web.php`, in dezelfde admin-groep als `roles.index`:

```php
Route::get('/admin/roles/{role}/edit', RoleEdit::class)->name('roles.edit');
```

Standaard route-model-binding. Geen `withTrashed()` — een soft-deleted rol resulteert in 404.

### 5.2 Autorisatie

`RolePolicy` blijft ongewijzigd. De Edit-component roept `authorize('update', $role)` aan in zowel `mount()` als `save()`. Dat dekt:

- Sjabloon openen (`team_id === null`) → 403.
- Rol uit andere organisatie → 403.
- Gebruiker zonder `roles.manage` → 403.

Defense-in-depth: een formulier kan lang openstaan, dus we hercontroleren bij `save()`.

## 6. Index-aanpassingen

### 6.1 Component (`app/Livewire/Roles/Index.php`)

1. **`roles()`-query** krijgt `withCount('users')` zodat per rij `users_count` beschikbaar is. SoftDeletes filtert verwijderde rollen automatisch — geen `withTrashed()`.
2. **`savePermissions()` verwijderen.** Die methode verhuist volledig naar `Edit`. Geen achtergebleven dode code.
3. **`deleteRole($roleId)`** krijgt een tweede guard:
   ```php
   $role = Role::findOrFail($roleId);
   $this->authorize('delete', $role);

   if ($role->users()->count() > 0) {
       session()->flash('error', __('Rol is nog gekoppeld aan gebruikers.'));
       return;
   }

   $role->delete(); // soft-delete
   session()->flash('status', __('Rol verwijderd.'));
   ```
   We hertellen server-side i.p.v. de cached `users_count` te vertrouwen — race-condition-defensief.

### 6.2 View (`resources/views/livewire/roles/index.blade.php`)

- Per rij naast Verwijderen een **Bewerken**-knop:
  ```blade
  @can('update', $role)
      <flux:button size="sm" :href="route('roles.edit', $role)">
          {{ __('Bewerken') }}
      </flux:button>
  @endcan
  ```
- **Verwijderen**-knop:
  ```blade
  @can('delete', $role)
      <flux:tooltip
          :content="$role->users_count > 0
              ? __('Niet verwijderbaar — gekoppeld aan :n gebruiker(s)', ['n' => $role->users_count])
              : null">
          <flux:button
              size="sm"
              variant="danger"
              :disabled="$role->users_count > 0"
              wire:click="deleteRole({{ $role->id }})">
              {{ __('Verwijderen') }}
          </flux:button>
      </flux:tooltip>
  @endcan
  ```
- Sjabloonrollen tonen al geen actieknoppen door de bestaande `@can`-checks.
- Naast het bestaande `status`-flash-blok komt een `error`-flash-blok (Flux callout, variant `danger`).

## 7. Edit-page — component, view en gegevensstroom

### 7.1 Component (`app/Livewire/Roles/Edit.php`)

State:
- `public Role $role`
- `public string $name = ''`
- `public array $selectedPermissions = []`

`mount(Role $role)`:
1. `$this->authorize('update', $role)`.
2. `$this->role = $role`.
3. `$this->name = $role->name`.
4. `$this->selectedPermissions = $role->permissions->pluck('id')->all()`.

`save()`:
1. `$this->authorize('update', $this->role)` (recheck).
2. `$this->validate([...])` met de regels uit §7.2.
3. `$this->role->update(['name' => $this->name]);` — vuurt Spatie's `updating`-event en flusht de cache.
4. `$perms = Permission::whereIn('id', $this->selectedPermissions)->pluck('name');`
   `$this->role->syncPermissions($perms);`
5. `session()->flash('status', __('Rol bijgewerkt.'));`
6. `return $this->redirectRoute('roles.index');`

`render()`:
- `#[Title('Rol bewerken')]`, `#[Layout('components.layouts.app')]`.
- View `livewire.roles.edit` met `permissions = Permission::orderBy('name')->get()`.

### 7.2 Validatieregels

```php
'name' => [
    'required', 'string', 'max:255', 'alpha_dash',
    Rule::unique('roles')
        ->where(fn ($q) => $q
            ->where('guard_name', 'web')
            ->where('team_id', $this->role->team_id))
        ->ignore($this->role->id),
    Rule::notIn(['super_admin', 'organisation_admin', 'member']),
    function ($attribute, $value, $fail) {
        $clash = Role::query()
            ->whereNull('team_id')
            ->where('name', $value)
            ->exists();
        if ($clash) {
            $fail(__('Deze naam is gereserveerd voor een sjabloonrol.'));
        }
    },
],
'selectedPermissions' => 'array',
'selectedPermissions.*' => 'integer|exists:permissions,id',
```

De gereserveerde lijst (`super_admin`, `organisation_admin`, `member`) komt overeen met de seeded sjabloonrol-namen en voorkomt verwarring met de cross-org propagatie van `super_admin`.

### 7.3 View (`resources/views/livewire/roles/edit.blade.php`)

```blade
<div class="space-y-8">
    <flux:button :href="route('roles.index')" icon="arrow-left" variant="ghost">
        {{ __('Terug') }}
    </flux:button>

    <flux:heading size="xl">{{ __('Rol bewerken') }}</flux:heading>

    <flux:card>
        <form wire:submit="save" class="space-y-6">
            <flux:input wire:model="name" label="{{ __('Rolnaam') }}" required />

            <fieldset>
                <flux:legend>{{ __('Permissies') }}</flux:legend>
                <div class="grid grid-cols-2 gap-2 mt-2">
                    @foreach ($permissions as $permission)
                        <flux:checkbox
                            wire:model="selectedPermissions"
                            value="{{ $permission->id }}"
                            label="{{ $permission->name }}" />
                    @endforeach
                </div>
            </fieldset>

            <flux:button type="submit" variant="primary">{{ __('Opslaan') }}</flux:button>
        </form>
    </flux:card>
</div>
```

### 7.4 Gegevensstroom

1. User klikt **Bewerken** in Index → GET `/admin/roles/{role}/edit`.
2. `RoleEdit::mount()` → policy-check, hydrate state.
3. User wijzigt naam en/of permissies → Livewire houdt state in zicht.
4. Submit → `save()` → policy-recheck → validate → `update()` → `syncPermissions()` → flash → redirect.
5. Index herlaadt: nieuwe naam en permissies, success-flash zichtbaar.

## 8. Foutafhandeling

| Geval | Gedrag |
| --- | --- |
| Sjabloonrol via URL openen | 403 via `RolePolicy::update()`. |
| Rol uit andere org | 403 via policy. |
| Naam botst met andere rol in dezelfde org | Validation-error onder het naam-veld. |
| Naam botst met sjabloonrol-naam | Validation-error met expliciete melding. |
| Naam staat in gereserveerde lijst | Validation-error. |
| Verwijderen terwijl users gekoppeld zijn | Knop disabled met tooltip; server-side guard geeft `error`-flash bij directe call. |
| Race-condition: laatste user wordt aan rol gekoppeld vlak voor delete | Server-side guard in `deleteRole()` ziet `users()->count() > 0` en weigert. |
| Race-condition: actor verliest `roles.manage` tijdens edit-sessie | Recheck in `save()` geeft 403. |

## 9. Tests

### 9.1 Nieuw bestand `tests/Feature/Roles/RoleEditTest.php`

- `test_user_with_roles_manage_can_open_edit_page()`
- `test_user_without_permission_gets_403()`
- `test_template_role_edit_returns_403()`
- `test_role_from_other_organisation_returns_403()`
- `test_renaming_a_role_persists_and_redirects()`
- `test_rename_validates_alpha_dash()`
- `test_rename_rejects_reserved_names()` — `super_admin`, `organisation_admin`, `member`
- `test_rename_rejects_template_role_name_clash()`
- `test_rename_rejects_duplicate_within_same_team()`
- `test_save_syncs_permissions()`
- `test_save_with_empty_permissions_clears_them()`

### 9.2 Uitbreiding `tests/Feature/Roles/RoleManagementTest.php`

- `test_delete_blocked_when_role_has_users()` — assert flash `error`, rol bestaat nog, niet trashed.
- `test_delete_soft_deletes_role()` — `assertSoftDeleted('roles', ['id' => $role->id])` en `$role->fresh()->trashed()` is `true`.
- `test_soft_deleted_role_is_excluded_from_index_query()`
- `test_index_loads_users_count_per_role()`

### 9.3 Nieuw bestand `tests/Feature/Models/RoleSoftDeleteTest.php`

- `test_role_uses_soft_deletes()` — soft-delete + `assertSoftDeleted` + `trashed()` true.
- `test_spatie_role_factory_resolves_to_app_role()` — `Role::create([...]) instanceof \App\Models\Role`.

## 10. Migratiestrategie

1. Custom Role-class invoeren (geen runtime-effect zolang `config/permission.php` nog op de Spatie-class wijst).
2. Migratie draaien: `deleted_at`-kolom toevoegen.
3. `config/permission.php` aanzetten op `App\Models\Role::class`.
4. Cache flushen: `php artisan permission:cache-reset && php artisan config:clear`.

In productie is de stap-voor-stap-volgorde irrelevant: de migratie en config-wijziging horen in dezelfde deploy. Er is geen data-migratie of backfill nodig — bestaande `roles`-rijen krijgen `deleted_at = NULL`.

## 11. Open punten

Geen. Alle keuzes zijn gemaakt tijdens brainstorm:
- UI-locatie: aparte Edit-pagina.
- Soft-delete: SoftDeletes-trait op custom Role.
- Opslagmodel: bulk via Opslaan-knop.
- Rename-validatie: alpha_dash + reservering + sjabloon-clash + uniek per team.
- Delete-blok: knop disabled met tooltip.
- Restore-UI: niet in deze iteratie.
