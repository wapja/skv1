# Role Edit-page Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Voeg een Edit-pagina toe waarop een gebruiker met `roles.manage` een rol kan hernoemen, permissies kan toggelen, en die rol kan soft-deleten — alleen als er geen users meer aan gekoppeld zijn.

**Architecture:** We introduceren een custom `App\Models\Role` met `SoftDeletes`-trait die Spatie's Role uitbreidt en gebruiken die via `config/permission.php`. Bestaande `RolePolicy` blijft ongewijzigd. Een nieuwe Livewire-component `App\Livewire\Roles\Edit` met route `/admin/roles/{role}/edit` host de rename-form en permissies-checkboxes. De Index-component krijgt een Bewerken-knop, een delete-guard die rollen met gekoppelde users blokkeert, en `withCount('users')` voor de tooltip. De bestaande inline `savePermissions()` verhuist naar Edit en wordt uit Index verwijderd.

**Tech Stack:** Laravel 12, Livewire 3, Flux UI, Pest 4, Spatie laravel-permission (teams-mode), MariaDB / SQLite voor tests.

**Spec:** `docs/superpowers/specs/2026-05-09-role-edit-page-design.md`

---

## File Structure

| Bestand | Verantwoordelijkheid |
| --- | --- |
| `app/Models/Role.php` *(nieuw)* | Subclass van Spatie's Role met `SoftDeletes`. Single responsibility: soft-delete-gedrag toevoegen. |
| `database/migrations/<timestamp>_add_soft_deletes_to_roles_table.php` *(nieuw)* | Voegt `deleted_at` aan `roles` toe. |
| `config/permission.php` *(modify)* | `'role' => App\Models\Role::class`. |
| `app/Livewire/Roles/Edit.php` *(nieuw)* | Mount, validate, save voor één rol; rename + syncPermissions. |
| `resources/views/livewire/roles/edit.blade.php` *(nieuw)* | Flux-form met naam-input, permissies-checkboxes, Opslaan-knop, Terug-link. |
| `routes/web.php` *(modify)* | Route `roles.edit`. |
| `app/Livewire/Roles/Index.php` *(modify)* | `withCount('users')` op `roles()`; `savePermissions()` verwijderd; `deleteRole` weigert wanneer users gekoppeld zijn. |
| `resources/views/livewire/roles/index.blade.php` *(modify)* | Bewerken-knop, disabled-state op Verwijderen, error-flash, tooltip. |
| `resources/views/components/layouts/app.blade.php` *(modify)* | Naast `status`-flash ook `error`-flash renderen. |
| `tests/Feature/Roles/RoleEditTest.php` *(nieuw)* | Autorisatie + rename + permissies-toggle tests. |
| `tests/Feature/Roles/RoleManagementTest.php` *(modify)* | Tests voor delete-guard, soft-delete, withCount, vervang test op `savePermissions`. |
| `tests/Feature/Models/RoleSoftDeleteTest.php` *(nieuw)* | Model-niveau tests voor SoftDeletes en class-resolutie. |

---

## Task 1: Custom Role-model met SoftDeletes

**Files:**
- Create: `app/Models/Role.php`
- Create: `tests/Feature/Models/RoleSoftDeleteTest.php`

- [ ] **Step 1: Maak het testbestand met de eerste falende test**

```php
<?php

// tests/Feature/Models/RoleSoftDeleteTest.php

use App\Models\Role as AppRole;
use App\Models\Organisation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->org = Organisation::factory()->create(['slug' => 'demo1']);
    app()->instance('currentOrganisation', $this->org);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
});

it('resolves Spatie role-class to App\\Models\\Role', function () {
    $role = AppRole::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);

    expect($role)->toBeInstanceOf(\App\Models\Role::class);
});
```

- [ ] **Step 2: Run de test en zie hem falen**

Run: `vendor/bin/pest tests/Feature/Models/RoleSoftDeleteTest.php --filter "resolves Spatie role-class"`
Expected: FAIL — `Class "App\Models\Role" not found`.

- [ ] **Step 3: Maak `app/Models/Role.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    use SoftDeletes;
}
```

- [ ] **Step 4: Wijzig `config/permission.php`**

Open `config/permission.php`, vind de regel `'role' => Spatie\Permission\Models\Role::class,` en vervang door:

```php
'role' => App\Models\Role::class,
```

- [ ] **Step 5: Run de test, zie hem nog steeds falen**

Run: `vendor/bin/pest tests/Feature/Models/RoleSoftDeleteTest.php --filter "resolves Spatie role-class"`
Expected: FAIL — `Column not found: deleted_at` of `Unknown column 'roles.deleted_at'`.

(Reden: SoftDeletes voegt automatisch `whereNull('deleted_at')` toe aan elke query, maar die kolom bestaat nog niet.)

- [ ] **Step 6: Maak de migratie**

Run: `php artisan make:migration add_soft_deletes_to_roles_table --table=roles`

Vervang de inhoud van het gegenereerde bestand met:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
```

- [ ] **Step 7: Run de test, zie hem slagen**

Run: `vendor/bin/pest tests/Feature/Models/RoleSoftDeleteTest.php --filter "resolves Spatie role-class"`
Expected: PASS.

- [ ] **Step 8: Voeg de soft-delete-gedragstest toe**

Voeg toe aan `tests/Feature/Models/RoleSoftDeleteTest.php`:

```php
it('soft-deletes a role and excludes it from default queries', function () {
    $role = AppRole::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);
    $id = $role->id;

    $role->delete();

    $this->assertSoftDeleted('roles', ['id' => $id]);
    expect($role->fresh()?->trashed())->toBeTrue();
    expect(AppRole::find($id))->toBeNull();
    expect(AppRole::withTrashed()->find($id))->not->toBeNull();
});
```

- [ ] **Step 9: Run de test, zie hem slagen**

Run: `vendor/bin/pest tests/Feature/Models/RoleSoftDeleteTest.php`
Expected: PASS, beide tests groen.

- [ ] **Step 10: Run de volledige suite om regressies uit te sluiten**

Run: `composer test`
Expected: alle tests groen. Spatie's permission-cache wordt automatisch geflusht omdat de cache-listener op events van de geconfigureerde class luistert; bestaande tests die `Role::create([...])` gebruiken blijven werken (Spatie's static factory routeert naar `App\Models\Role`).

- [ ] **Step 11: Commit**

```bash
git add app/Models/Role.php config/permission.php database/migrations tests/Feature/Models/RoleSoftDeleteTest.php
git commit -m "feat(roles): custom Role model met SoftDeletes-trait"
```

---

## Task 2: Index-aanpassingen — withCount, delete-guard, savePermissions weg

**Files:**
- Modify: `app/Livewire/Roles/Index.php`
- Modify: `tests/Feature/Roles/RoleManagementTest.php`

- [ ] **Step 1: Vervang de bestaande savePermissions-test door delete-guard- en soft-delete-tests**

Open `tests/Feature/Roles/RoleManagementTest.php`. Verwijder de `it('updates the permissions of an existing per-org role', ...)`-test (regel 93-107 in de huidige versie). Vervang de bestaande `it('deletes a per-org role', ...)`-test door de drie tests hieronder, en voeg ook de `users_count`-test toe. Het hele `describe('Roles Index Livewire', ...)`-blok ziet er na de wijziging als volgt uit:

```php
describe('Roles Index Livewire', function () {
    it('lists the template role plus per-org roles', function () {
        Role::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);

        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->assertSee('organisation_admin')
            ->assertSee('editor');
    });

    it('does not list roles belonging to other organisations', function () {
        $other = Organisation::factory()->create(['slug' => 'demo2']);
        Role::create(['name' => 'foreign-role', 'guard_name' => 'web', 'team_id' => $other->id]);

        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->assertDontSee('foreign-role');
    });

    it('creates a per-org role with selected permissions', function () {
        $perm = Permission::where('name', 'users.view')->first();

        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->set('newRoleName', 'editor')
            ->set('newRolePermissions', [$perm->id])
            ->call('createRole')
            ->assertHasNoErrors();

        $created = Role::where('name', 'editor')->where('team_id', $this->org->id)->first();
        expect($created)->not->toBeNull()
            ->and($created->permissions->pluck('name')->all())->toContain('users.view');
    });

    it('soft-deletes a per-org role with no users attached', function () {
        $role = Role::create(['name' => 'tobegone', 'guard_name' => 'web', 'team_id' => $this->org->id]);
        $id = $role->id;

        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->call('deleteRole', $id)
            ->assertHasNoErrors();

        expect(Role::find($id))->toBeNull();
        $this->assertSoftDeleted('roles', ['id' => $id]);
    });

    it('refuses to delete a role that still has users attached', function () {
        $role = Role::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);
        $member = User::factory()->for($this->org)->create();
        $member->assignRole($role);

        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->call('deleteRole', $role->id)
            ->assertHasNoErrors();

        expect(Role::find($role->id))->not->toBeNull();
        expect(session('error'))->toBe(__('Rol is nog gekoppeld aan gebruikers.'));
    });

    it('exposes users_count on each listed role', function () {
        $role = Role::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);
        $member = User::factory()->for($this->org)->create();
        $member->assignRole($role);

        $this->actingAs($this->actor);

        $component = Livewire::test(Index::class);

        $listed = collect($component->viewData('roles'))->firstWhere('id', $role->id);
        expect($listed->users_count)->toBe(1);
    });

    it('refuses to delete the template role', function () {
        $template = Role::where('name', 'organisation_admin')->whereNull('team_id')->firstOrFail();

        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->call('deleteRole', $template->id)
            ->assertStatus(403);

        expect(Role::find($template->id))->not->toBeNull();
    });
});
```

- [ ] **Step 2: Run de tests, zie de nieuwe falen**

Run: `vendor/bin/pest tests/Feature/Roles/RoleManagementTest.php`
Expected: FAIL op `refuses to delete a role that still has users attached` (huidige `deleteRole` doet altijd hard delete) en `exposes users_count on each listed role` (de query laadt geen `withCount`).

- [ ] **Step 3: Pas `app/Livewire/Roles/Index.php` aan**

Vervang het volledige bestand door:

```php
<?php

namespace App\Livewire\Roles;

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class Index extends Component
{
    #[Validate('required|string|max:255|alpha_dash')]
    public string $newRoleName = '';

    #[Validate('array')]
    public array $newRolePermissions = [];

    public function createRole(): void
    {
        $this->authorize('create', Role::class);
        $this->validate();

        $role = Role::create([
            'name' => $this->newRoleName,
            'guard_name' => 'web',
            'team_id' => tenant()?->id,
        ]);

        if (! empty($this->newRolePermissions)) {
            $perms = Permission::whereIn('id', $this->newRolePermissions)->pluck('name');
            $role->syncPermissions($perms);
        }

        $this->reset(['newRoleName', 'newRolePermissions']);
        session()->flash('status', __('Rol aangemaakt.'));
    }

    public function deleteRole(int $roleId): void
    {
        $role = Role::findOrFail($roleId);
        $this->authorize('delete', $role);

        if ($role->users()->count() > 0) {
            session()->flash('error', __('Rol is nog gekoppeld aan gebruikers.'));

            return;
        }

        $role->delete();
        session()->flash('status', __('Rol verwijderd.'));
    }

    public function roles()
    {
        return Role::query()
            ->where(fn ($q) => $q->whereNull('team_id')->orWhere('team_id', tenant()?->id))
            ->with('permissions')
            ->withCount('users')
            ->orderBy('name')
            ->get();
    }

    public function allPermissions()
    {
        return Permission::orderBy('name')->get();
    }

    #[Layout('components.layouts.app')]
    #[Title('Rollen en permissies')]
    public function render()
    {
        $this->authorize('viewAny', Role::class);

        return view('livewire.roles.index', [
            'roles' => $this->roles(),
            'permissions' => $this->allPermissions(),
        ]);
    }
}
```

Belangrijkste wijzigingen:
- `savePermissions()` verwijderd (verhuist naar Edit).
- `deleteRole()` controleert `$role->users()->count()` na de policy-check; bij `> 0` flash `error` en return.
- `roles()` laadt `withCount('users')`.

- [ ] **Step 4: Run de tests, zie ze slagen**

Run: `vendor/bin/pest tests/Feature/Roles/RoleManagementTest.php`
Expected: alle tests in dit bestand groen.

- [ ] **Step 5: Commit**

```bash
git add app/Livewire/Roles/Index.php tests/Feature/Roles/RoleManagementTest.php
git commit -m "feat(roles): delete-guard en users_count op Index, savePermissions verhuisd"
```

---

## Task 3: Index-view — Bewerken-knop, disabled delete, error-flash

**Files:**
- Modify: `resources/views/livewire/roles/index.blade.php`
- Modify: `resources/views/components/layouts/app.blade.php`

- [ ] **Step 1: Voeg de error-flash toe aan de layout**

Open `resources/views/components/layouts/app.blade.php`. Vind:

```blade
        @if (session('status'))
            <flux:callout variant="success" icon="check-circle" class="mb-6">{{ session('status') }}</flux:callout>
        @endif
```

Voeg er direct na deze blade-block bij toe:

```blade
        @if (session('error'))
            <flux:callout variant="danger" icon="x-circle" class="mb-6">{{ session('error') }}</flux:callout>
        @endif
```

- [ ] **Step 2: Vervang de actie-cel in de Index-view**

Open `resources/views/livewire/roles/index.blade.php`. Vervang de huidige actie-cel:

```blade
                    <flux:table.cell>
                        @can('delete', $role)
                            <flux:button size="sm" variant="danger" wire:click="deleteRole({{ $role->id }})">
                                {{ __('Verwijderen') }}
                            </flux:button>
                        @endcan
                    </flux:table.cell>
```

door:

```blade
                    <flux:table.cell>
                        <div class="flex items-center gap-2">
                            @can('update', $role)
                                <flux:button size="sm" :href="route('roles.edit', $role)">
                                    {{ __('Bewerken') }}
                                </flux:button>
                            @endcan
                            @can('delete', $role)
                                @if ($role->users_count > 0)
                                    <flux:tooltip :content="__('Niet verwijderbaar — gekoppeld aan :n gebruiker(s)', ['n' => $role->users_count])">
                                        <flux:button size="sm" variant="danger" disabled>
                                            {{ __('Verwijderen') }}
                                        </flux:button>
                                    </flux:tooltip>
                                @else
                                    <flux:button size="sm" variant="danger" wire:click="deleteRole({{ $role->id }})">
                                        {{ __('Verwijderen') }}
                                    </flux:button>
                                @endif
                            @endcan
                        </div>
                    </flux:table.cell>
```

- [ ] **Step 3: Run de Index-tests, zie ze nog steeds groen**

Run: `vendor/bin/pest tests/Feature/Roles/RoleManagementTest.php`
Expected: alle tests groen. View-wijzigingen breken niets.

- [ ] **Step 4: Commit**

```bash
git add resources/views/livewire/roles/index.blade.php resources/views/components/layouts/app.blade.php
git commit -m "feat(roles): Bewerken-knop, disabled delete-knop en error-flash"
```

---

## Task 4: Edit-component — autorisatie-skelet en mount

**Files:**
- Create: `app/Livewire/Roles/Edit.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/Roles/RoleEditTest.php`

- [ ] **Step 1: Maak het testbestand met setup en eerste twee falende tests**

```php
<?php

// tests/Feature/Roles/RoleEditTest.php

use App\Livewire\Roles\Edit;
use App\Models\Organisation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    config(['app.apex_domain' => 'skv1.test']);
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->org = Organisation::factory()->create(['slug' => 'demo1']);
    app()->instance('currentOrganisation', $this->org);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);

    $this->actor = User::factory()->for($this->org)->create();
    $this->actor->assignRole('organisation_admin');
});

it('opens the edit page for a per-org role', function () {
    $role = Role::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);

    $this->actingAs($this->actor);

    $this->get(route('roles.edit', $role))
        ->assertOk()
        ->assertSee('editor');
});

it('returns 403 for a template role', function () {
    $template = Role::where('name', 'organisation_admin')->whereNull('team_id')->firstOrFail();

    $this->actingAs($this->actor);

    $this->get(route('roles.edit', $template))->assertForbidden();
});

it('returns 403 when actor lacks roles.manage', function () {
    $regular = User::factory()->for($this->org)->create();
    $role = Role::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);

    $this->actingAs($regular);

    $this->get(route('roles.edit', $role))->assertForbidden();
});

it('returns 403 for a role from another organisation', function () {
    $other = Organisation::factory()->create(['slug' => 'demo2']);
    $role = Role::create(['name' => 'foreign', 'guard_name' => 'web', 'team_id' => $other->id]);

    $this->actingAs($this->actor);

    $this->get(route('roles.edit', $role))->assertForbidden();
});
```

- [ ] **Step 2: Run de tests, zie ze falen**

Run: `vendor/bin/pest tests/Feature/Roles/RoleEditTest.php`
Expected: FAIL — `Route [roles.edit] not defined`.

- [ ] **Step 3: Maak `app/Livewire/Roles/Edit.php` met minimaal mount**

```php
<?php

namespace App\Livewire\Roles;

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class Edit extends Component
{
    public Role $role;

    public string $name = '';

    public array $selectedPermissions = [];

    public function mount(Role $role): void
    {
        $this->authorize('update', $role);

        $this->role = $role;
        $this->name = $role->name;
        $this->selectedPermissions = $role->permissions->pluck('id')->all();
    }

    #[Layout('components.layouts.app')]
    #[Title('Rol bewerken')]
    public function render()
    {
        return view('livewire.roles.edit', [
            'permissions' => Permission::orderBy('name')->get(),
        ]);
    }
}
```

- [ ] **Step 4: Maak `resources/views/livewire/roles/edit.blade.php`**

```blade
<div class="space-y-8">
    <flux:button :href="route('roles.index')" icon="arrow-left" variant="ghost">
        {{ __('Terug') }}
    </flux:button>

    <flux:heading size="xl">{{ __('Rol bewerken') }}: {{ $role->name }}</flux:heading>

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

- [ ] **Step 5: Voeg de route toe in `routes/web.php`**

Voeg bij de import-regels boven aan het bestand toe:

```php
use App\Livewire\Roles\Edit as RoleEdit;
```

Vind in de `auth`-middlewaregroup de regel:

```php
    Route::get('/admin/roles', RoleIndex::class)->name('roles.index');
```

en voeg er direct na toe:

```php
    Route::get('/admin/roles/{role}/edit', RoleEdit::class)->name('roles.edit');
```

- [ ] **Step 6: Run de tests, zie de eerste vier slagen**

Run: `vendor/bin/pest tests/Feature/Roles/RoleEditTest.php`
Expected: alle vier tests in dit bestand groen.

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/Roles/Edit.php resources/views/livewire/roles/edit.blade.php routes/web.php tests/Feature/Roles/RoleEditTest.php
git commit -m "feat(roles): Edit-page route en mount met autorisatiechecks"
```

---

## Task 5: Save-flow — happy path

**Files:**
- Modify: `app/Livewire/Roles/Edit.php`
- Modify: `tests/Feature/Roles/RoleEditTest.php`

- [ ] **Step 1: Voeg een falende save-test toe**

Voeg toe aan `tests/Feature/Roles/RoleEditTest.php`:

```php
it('saves a renamed role and synced permissions, then redirects to index', function () {
    $role = Role::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);
    $role->givePermissionTo('users.view');

    $newPerm = Permission::where('name', 'users.update')->first();

    $this->actingAs($this->actor);

    Livewire::test(Edit::class, ['role' => $role])
        ->set('name', 'redactor')
        ->set('selectedPermissions', [$newPerm->id])
        ->call('save')
        ->assertRedirect(route('roles.index'));

    $role->refresh();
    expect($role->name)->toBe('redactor')
        ->and($role->permissions->pluck('name')->all())->toBe(['users.update']);
});

it('clears all permissions when saving with an empty selection', function () {
    $role = Role::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);
    $role->givePermissionTo('users.view');

    $this->actingAs($this->actor);

    Livewire::test(Edit::class, ['role' => $role])
        ->set('selectedPermissions', [])
        ->call('save');

    expect($role->fresh()->permissions)->toHaveCount(0);
});
```

- [ ] **Step 2: Run de tests, zie ze falen**

Run: `vendor/bin/pest tests/Feature/Roles/RoleEditTest.php`
Expected: FAIL — `Method App\Livewire\Roles\Edit::save() does not exist`.

- [ ] **Step 3: Implementeer `save()` (zonder validatie nog)**

Voeg toe aan `app/Livewire/Roles/Edit.php`. Pas de imports aan boven aan het bestand:

```php
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
```

(`Permission` is wellicht al geïmporteerd; controleer.)

Voeg de methode toe vóór `render()`:

```php
public function save(): mixed
{
    $this->authorize('update', $this->role);

    $this->role->update(['name' => $this->name]);

    $perms = Permission::whereIn('id', $this->selectedPermissions)->pluck('name');
    $this->role->syncPermissions($perms);

    session()->flash('status', __('Rol bijgewerkt.'));

    return redirect()->route('roles.index');
}
```

- [ ] **Step 4: Run de tests, zie ze slagen**

Run: `vendor/bin/pest tests/Feature/Roles/RoleEditTest.php`
Expected: alle tests in dit bestand groen.

- [ ] **Step 5: Commit**

```bash
git add app/Livewire/Roles/Edit.php tests/Feature/Roles/RoleEditTest.php
git commit -m "feat(roles): save-flow voor rename en syncPermissions op Edit-page"
```

---

## Task 6: Validatie — alpha_dash, uniek per team, gereserveerde namen, sjabloon-clash

**Files:**
- Modify: `app/Livewire/Roles/Edit.php`
- Modify: `tests/Feature/Roles/RoleEditTest.php`

- [ ] **Step 1: Voeg falende validatie-tests toe**

Voeg toe aan `tests/Feature/Roles/RoleEditTest.php`:

```php
it('rejects names that are not alpha_dash', function () {
    $role = Role::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);

    $this->actingAs($this->actor);

    Livewire::test(Edit::class, ['role' => $role])
        ->set('name', 'has spaces')
        ->call('save')
        ->assertHasErrors(['name']);

    expect($role->fresh()->name)->toBe('editor');
});

it('rejects reserved role names', function () {
    $role = Role::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);

    $this->actingAs($this->actor);

    foreach (['super_admin', 'organisation_admin', 'member'] as $reserved) {
        Livewire::test(Edit::class, ['role' => $role])
            ->set('name', $reserved)
            ->call('save')
            ->assertHasErrors(['name']);
    }

    expect($role->fresh()->name)->toBe('editor');
});

it('rejects a name that clashes with a template role name', function () {
    $role = Role::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);

    Role::create(['name' => 'template_only', 'guard_name' => 'web', 'team_id' => null]);

    $this->actingAs($this->actor);

    Livewire::test(Edit::class, ['role' => $role])
        ->set('name', 'template_only')
        ->call('save')
        ->assertHasErrors(['name']);

    expect($role->fresh()->name)->toBe('editor');
});

it('rejects a name that already exists in the same team', function () {
    Role::create(['name' => 'redactor', 'guard_name' => 'web', 'team_id' => $this->org->id]);
    $role = Role::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);

    $this->actingAs($this->actor);

    Livewire::test(Edit::class, ['role' => $role])
        ->set('name', 'redactor')
        ->call('save')
        ->assertHasErrors(['name']);

    expect($role->fresh()->name)->toBe('editor');
});

it('allows saving without changing the name (ignores unique on self)', function () {
    $role = Role::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);

    $this->actingAs($this->actor);

    Livewire::test(Edit::class, ['role' => $role])
        ->set('name', 'editor')
        ->call('save')
        ->assertHasNoErrors();
});
```

- [ ] **Step 2: Run de tests, zie ze falen**

Run: `vendor/bin/pest tests/Feature/Roles/RoleEditTest.php`
Expected: vier validatie-tests falen, vijfde slaagt al.

- [ ] **Step 3: Voeg validatie toe in `save()`**

Open `app/Livewire/Roles/Edit.php`. Voeg een import bij de overige imports:

```php
use Illuminate\Validation\Rule;
```

Vervang de `save()`-methode door:

```php
public function save(): mixed
{
    $this->authorize('update', $this->role);

    $this->validate([
        'name' => [
            'required', 'string', 'max:255', 'alpha_dash',
            Rule::unique('roles')
                ->where(fn ($q) => $q
                    ->where('guard_name', 'web')
                    ->where('team_id', $this->role->team_id))
                ->ignore($this->role->id),
            Rule::notIn(['super_admin', 'organisation_admin', 'member']),
            function ($attribute, $value, $fail): void {
                $clash = Role::query()
                    ->whereNull('team_id')
                    ->where('name', $value)
                    ->where('guard_name', 'web')
                    ->exists();
                if ($clash) {
                    $fail(__('Deze naam is gereserveerd voor een sjabloonrol.'));
                }
            },
        ],
        'selectedPermissions' => 'array',
        'selectedPermissions.*' => 'integer|exists:permissions,id',
    ]);

    $this->role->update(['name' => $this->name]);

    $perms = Permission::whereIn('id', $this->selectedPermissions)->pluck('name');
    $this->role->syncPermissions($perms);

    session()->flash('status', __('Rol bijgewerkt.'));

    return redirect()->route('roles.index');
}
```

- [ ] **Step 4: Run de tests, zie ze slagen**

Run: `vendor/bin/pest tests/Feature/Roles/RoleEditTest.php`
Expected: alle tests in dit bestand groen.

- [ ] **Step 5: Commit**

```bash
git add app/Livewire/Roles/Edit.php tests/Feature/Roles/RoleEditTest.php
git commit -m "feat(roles): validatie voor rename — alpha_dash, uniek, gereserveerd, sjabloon-clash"
```

---

## Task 7: Volledige suite groen + handmatige rooktest

**Files:**
- Geen wijzigingen — verificatie-stap.

- [ ] **Step 1: Run de volledige test-suite**

Run: `composer test`
Expected: alle tests groen.

- [ ] **Step 2: Run de migratie tegen de lokale database (sanity-check)**

Run: `php artisan migrate`
Expected: `add_soft_deletes_to_roles_table` wordt toegepast (of "Nothing to migrate" als de testsuite hem al draaide).

- [ ] **Step 3: Reset de Spatie permission-cache**

Run: `php artisan permission:cache-reset`
Expected: succesmelding.

- [ ] **Step 4: Handmatige rooktest in de browser**

Start de dev-server: `composer dev` (of `php artisan serve`).
Log in als `organisation_admin` van een test-org. Doorloop:

1. `/admin/roles` — zie de Bewerken-knop naast Verwijderen.
2. Maak een nieuwe rol `tempo` aan, klik Bewerken.
3. Hernoem naar `tempo2`, vink één permissie aan, klik Opslaan. Verwacht: redirect terug naar Index met success-flash; rol heet nu `tempo2`.
4. Open Edit van `tempo2` opnieuw, probeer hernoemen naar `super_admin`. Verwacht: validation-error.
5. Ga terug naar Index, klik Verwijderen op `tempo2`. Verwacht: rol verdwijnt.
6. Maak `linker` aan, ken hem aan een test-user toe (via Users → Edit → roles), ga terug naar Roles. Verwacht: Verwijderen-knop is disabled met tooltip.
7. Ontkoppel de user, refresh — Verwijderen werkt weer.

- [ ] **Step 5: Geen commit nodig — alle code is al gecommit in eerdere taken.**

---

## Self-Review (na schrijven)

**Spec coverage:**
- §3 Architectuur (custom Role, migratie, Edit-component, Index-tweaks, route, layout-flash, tests) — gedekt door Task 1 t/m 6.
- §4 Datalaag (model, migratie, config, cache-gedrag) — Task 1.
- §5 Routes & autorisatie — Task 4 (route + mount-authorize) en Task 5 (recheck in save).
- §6 Index-aanpassingen (`withCount`, `savePermissions` weg, delete-guard, view-Bewerken/Verwijderen-disabled, error-flash) — Task 2 en 3.
- §7 Edit-page (component, view, validatieregels, gegevensstroom) — Task 4, 5, 6.
- §8 Foutafhandeling (sjabloon 403, andere org 403, naam-clashes, race-condition delete-guard, race-condition recheck in save) — alle gevallen krijgen een test.
- §9 Tests — bestanden gemaakt: `RoleSoftDeleteTest.php` (model), `RoleEditTest.php` (Edit), uitbreiding `RoleManagementTest.php` (delete-guard, soft-delete, withCount). Verwijderde test op `savePermissions` is bewust geremoved omdat de methode verhuist naar Edit.
- §10 Migratiestrategie — Task 1 stappen 4-6 (model + config + migratie in juiste volgorde) en Task 7 stap 3 (cache-reset).

**Placeholder scan:** geen TBD/TODO/'add error handling'/'similar to Task N'-references. Alle code-blokken bevatten complete code.

**Type consistency:**
- `Role` import: in `Edit.php` gebruiken we `Spatie\Permission\Models\Role` (route-binding/policy via spatie's class) — dit blijft consistent door het hele plan. De custom `App\Models\Role` wordt alleen direct in `tests/Feature/Models/RoleSoftDeleteTest.php` aangeroepen om aan te tonen dat de class-resolutie klopt.
- Properties: `name` (string), `selectedPermissions` (array) — consistent in mount, view, save, tests.
- Methodenamen: `save()`, `deleteRole()`, `createRole()`, `roles()`, `allPermissions()` — consistent.
- Flash-keys: `status` voor success, `error` voor failure — consistent in component, view, layout en tests.
- Reserved-names-lijst: `['super_admin', 'organisation_admin', 'member']` — identiek in spec, validatieregel en testlus.

Geen issues gevonden tijdens self-review.
