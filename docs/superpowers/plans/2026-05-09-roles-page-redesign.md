# Roles-pagina redesign — implementatieplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** De roles-overzichtspagina krijgt dezelfde header- en lijstopbouw als de users-overzichtspagina, en het aanmaken van een rol verhuist naar een eigen pagina (`/admin/roles/create`).

**Architecture:** Volg het bestaande dual-mode patroon van `Organisations\Edit`. `Roles\Edit` wordt verantwoordelijk voor zowel create als update; `Roles\Index` wordt een pure lijstcomponent. De inline create-form op de index verdwijnt en wordt vervangen door een primary-knop "Nieuwe rol" rechts in de header.

**Tech Stack:** Laravel 11, Livewire 3 (volatile + class components), FluxUI, Pest 3, Spatie Permission (multi-tenant met team_id), Tailwind.

**Spec:** `docs/superpowers/specs/2026-05-09-roles-page-redesign-design.md`

---

## File map

| Bestand | Actie | Verantwoordelijkheid na taak |
|---|---|---|
| `routes/web.php` | Modify | Voegt `roles.create` toe naast bestaande `roles.index` en `roles.edit`. |
| `app/Livewire/Roles/Edit.php` | Modify | Dual-mode component: create + update via één `save()`. |
| `resources/views/livewire/roles/edit.blade.php` | Modify | Dynamische heading; verder ongewijzigd. |
| `app/Livewire/Roles/Index.php` | Modify | Pure lijstcomponent; create-state verwijderd. |
| `resources/views/livewire/roles/index.blade.php` | Modify | Header met "Nieuwe rol"-knop, empty-state, geen create-form. |
| `tests/Feature/Roles/RoleEditTest.php` | Modify | Nieuwe test-blok "create mode" toegevoegd. |
| `tests/Feature/Roles/RoleManagementTest.php` | Modify | Test op `createRole()` op `Index` verwijderd; nieuwe asserts op header-knop en empty-state-callout. |

---

## Task 1: Maak `Roles\Edit` dual-mode (mount + save)

Maak `Edit` zodanig dat ook zonder `Role`-argument gemount kan worden, en dat `save()` in dat geval een nieuwe rol aanmaakt.

**Files:**
- Modify: `app/Livewire/Roles/Edit.php`
- Test: `tests/Feature/Roles/RoleEditTest.php`

- [ ] **Step 1: Schrijf de falende test voor create-mount**

Voeg toe aan `tests/Feature/Roles/RoleEditTest.php`, onder de bestaande tests (binnen hetzelfde bestand, na de laatste `it(...)`):

```php
describe('Create mode', function () {
    it('mounts in create mode without a role argument', function () {
        $this->actingAs($this->actor);

        Livewire::test(Edit::class)
            ->assertOk()
            ->assertSet('role', null)
            ->assertSet('name', '')
            ->assertSet('selectedPermissions', []);
    });
});
```

- [ ] **Step 2: Run de test om te bevestigen dat hij faalt**

```bash
php artisan test --filter="mounts in create mode without a role argument"
```

Verwacht: FAIL — `Edit::mount()` verwacht nu een `Role` parameter (`Cannot resolve $role` of vergelijkbaar).

- [ ] **Step 3: Maak `mount()` dual-mode in `app/Livewire/Roles/Edit.php`**

Vervang de huidige property en `mount()`:

```php
    public ?Role $role = null;

    public string $name = '';

    public array $selectedPermissions = [];

    public function mount(?Role $role = null): void
    {
        if ($role && $role->exists) {
            $this->authorize('update', $role);

            $this->role = $role;
            $this->name = $role->name;
            $this->selectedPermissions = $role->permissions->pluck('id')->all();
        } else {
            $this->authorize('create', Role::class);
        }
    }
```

- [ ] **Step 4: Run de test om te bevestigen dat hij slaagt**

```bash
php artisan test --filter="mounts in create mode without a role argument"
```

Verwacht: PASS.

- [ ] **Step 5: Schrijf de falende test voor create-save**

Voeg toe binnen `describe('Create mode', …)`:

```php
    it('creates a per-org role with selected permissions and redirects to index', function () {
        $perm = Permission::where('name', 'users.view')->first();

        $this->actingAs($this->actor);

        Livewire::test(Edit::class)
            ->set('name', 'editor')
            ->set('selectedPermissions', [$perm->id])
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('roles.index'));

        $created = Role::where('name', 'editor')->where('team_id', $this->org->id)->first();
        expect($created)->not->toBeNull()
            ->and($created->permissions->pluck('name')->all())->toBe(['users.view']);
    });
```

- [ ] **Step 6: Run de test om te bevestigen dat hij faalt**

```bash
php artisan test --filter="creates a per-org role with selected permissions and redirects to index"
```

Verwacht: FAIL — `save()` probeert `$this->role->update(...)` op `null`.

- [ ] **Step 7: Maak `save()` dual-mode**

Vervang de bestaande `save()` in `app/Livewire/Roles/Edit.php` door:

```php
    public function save(): mixed
    {
        if ($this->role) {
            $this->authorize('update', $this->role);
        } else {
            $this->authorize('create', Role::class);
        }

        $this->validate([
            'name' => [
                'required', 'string', 'max:255', 'alpha_dash',
                Rule::unique('roles')
                    ->where(fn ($q) => $q
                        ->where('guard_name', 'web')
                        ->where('team_id', $this->role?->team_id ?? tenant()?->id))
                    ->ignore($this->role?->id),
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

        $wasCreate = $this->role === null;

        DB::transaction(function () {
            if ($this->role) {
                $this->role->update(['name' => $this->name]);
            } else {
                $this->role = Role::create([
                    'name' => $this->name,
                    'guard_name' => 'web',
                    'team_id' => tenant()?->id,
                ]);
            }

            // selectedPermissions arrive as string-cast ints from Livewire's wire-properties; resolve to names before syncing.
            $perms = Permission::whereIn('id', $this->selectedPermissions)->pluck('name');
            $this->role->syncPermissions($perms);
        });

        session()->flash('status', __($wasCreate ? 'Rol aangemaakt.' : 'Rol bijgewerkt.'));

        return redirect()->route('roles.index');
    }
```

- [ ] **Step 8: Run de test om te bevestigen dat hij slaagt**

```bash
php artisan test --filter="creates a per-org role with selected permissions and redirects to index"
```

Verwacht: PASS.

- [ ] **Step 9: Run alle Edit-tests om te bevestigen dat update-modus niet stuk is**

```bash
php artisan test tests/Feature/Roles/RoleEditTest.php
```

Verwacht: alle tests in dit bestand PASS.

- [ ] **Step 10: Commit**

```bash
git add app/Livewire/Roles/Edit.php tests/Feature/Roles/RoleEditTest.php
git commit -m "$(cat <<'EOF'
feat(roles): Roles\Edit dual-mode — create + update via één component

mount(?Role $role = null): create-mode autoriseert via 'create',
update-modus via 'update'. save() routeert in DB::transaction naar
Role::create() resp. update(), gevolgd door één gedeelde
syncPermissions(). Validatie geldt voor beide modi (alpha_dash, unique
binnen team, reserved names, sjabloonrol-clash).
EOF
)"
```

---

## Task 2: Voeg `roles.create` route toe

Registreer de nieuwe route die naar `Roles\Edit` wijst zonder `{role}` parameter.

**Files:**
- Modify: `routes/web.php`
- Test: `tests/Feature/Roles/RoleEditTest.php`

- [ ] **Step 1: Schrijf de falende test voor de create-route**

Voeg toe binnen `describe('Create mode', …)` in `tests/Feature/Roles/RoleEditTest.php`:

```php
    it('opens the create page for an authorized user', function () {
        $this->actingAs($this->actor);

        $this->get(route('roles.create'))
            ->assertOk()
            ->assertSee('Nieuwe rol');
    });

    it('returns 403 on the create page when actor lacks roles.manage', function () {
        $regular = User::factory()->for($this->org)->create();

        $this->actingAs($regular);

        $this->get(route('roles.create'))->assertForbidden();
    });
```

- [ ] **Step 2: Run de tests om te bevestigen dat ze falen**

```bash
php artisan test --filter="opens the create page for an authorized user"
```

Verwacht: FAIL — `Route [roles.create] not defined.`

- [ ] **Step 3: Voeg de route toe in `routes/web.php`**

Vind het bestaande blok:

```php
    Route::get('/admin/roles', RoleIndex::class)->name('roles.index');
    Route::get('/admin/roles/{role}/edit', RoleEdit::class)->name('roles.edit');
```

Vervang door:

```php
    Route::get('/admin/roles', RoleIndex::class)->name('roles.index');
    Route::get('/admin/roles/create', RoleEdit::class)->name('roles.create');
    Route::get('/admin/roles/{role}/edit', RoleEdit::class)->name('roles.edit');
```

(Volgorde: `create` vóór `{role}/edit` om route-conflicten te vermijden — al wordt `create` geen route-modelparameter geraakt, het is netter consistent met Laravel-conventies.)

- [ ] **Step 4: Run de tests om te bevestigen dat ze slagen**

```bash
php artisan test --filter="opens the create page for an authorized user"
php artisan test --filter="returns 403 on the create page when actor lacks roles.manage"
```

Verwacht: beide PASS. (De `assertSee('Nieuwe rol')` werkt nog niet — de heading toont nu "Rol bewerken: " zonder naam. Daarom zal de eerste test waarschijnlijk falen op de assertSee.)

> **Note:** als `assertSee('Nieuwe rol')` faalt op de bestaande heading, ga dan door naar Task 3 — die past de heading aan. Verwijder de `assertSee('Nieuwe rol')` regel hier *niet*; hij zal in Task 3 vanzelf groen worden.

- [ ] **Step 5: Commit**

```bash
git add routes/web.php tests/Feature/Roles/RoleEditTest.php
git commit -m "$(cat <<'EOF'
feat(roles): route /admin/roles/create -> Roles\Edit

Nieuwe rollen worden voortaan op een aparte pagina aangemaakt,
volgens hetzelfde patroon als organisations.create.
EOF
)"
```

---

## Task 3: Pas heading van `roles/edit.blade.php` aan

Maak de heading dynamisch zodat de create-modus "Nieuwe rol" toont en update-modus "Rol bewerken: {name}".

**Files:**
- Modify: `resources/views/livewire/roles/edit.blade.php`
- Test: bestaat al (de `assertSee('Nieuwe rol')` uit Task 2)

- [ ] **Step 1: Bevestig dat de assert faalt zonder code-aanpassing**

```bash
php artisan test --filter="opens the create page for an authorized user"
```

Verwacht: FAIL met "Failed asserting that the response contains 'Nieuwe rol'".
Als de test al PASS — sla Step 2-3 over en ga naar Step 4.

- [ ] **Step 2: Pas de heading aan in `resources/views/livewire/roles/edit.blade.php`**

Vervang regel 6:

```blade
    <flux:heading size="xl">{{ __('Rol bewerken') }}: {{ $role->name }}</flux:heading>
```

door:

```blade
    <flux:heading size="xl">
        {{ $role ? __('Rol bewerken: :name', ['name' => $role->name]) : __('Nieuwe rol') }}
    </flux:heading>
```

- [ ] **Step 3: Run de test om te bevestigen dat hij slaagt**

```bash
php artisan test --filter="opens the create page for an authorized user"
```

Verwacht: PASS.

- [ ] **Step 4: Run alle Edit-tests om regressie uit te sluiten**

```bash
php artisan test tests/Feature/Roles/RoleEditTest.php
```

Verwacht: alle tests PASS, inclusief "opens the edit page for a per-org role" die nog steeds `'editor'` op de pagina ziet.

- [ ] **Step 5: Commit**

```bash
git add resources/views/livewire/roles/edit.blade.php
git commit -m "feat(roles): dynamische heading — 'Nieuwe rol' vs 'Rol bewerken: {naam}'"
```

---

## Task 4: Versterk validatietests voor create-modus

De huidige validatieregels in `Edit::save()` zijn al universeel — deze taak voegt expliciete tests toe die het gedrag in create-modus pinnen. Geen code-wijzigingen, alleen tests.

**Files:**
- Test: `tests/Feature/Roles/RoleEditTest.php`

- [ ] **Step 1: Voeg validatietests toe binnen `describe('Create mode', …)`**

```php
    it('rejects empty name on create', function () {
        $this->actingAs($this->actor);

        Livewire::test(Edit::class)
            ->set('name', '')
            ->call('save')
            ->assertHasErrors(['name']);

        expect(Role::where('team_id', $this->org->id)->where('name', '')->exists())->toBeFalse();
    });

    it('rejects reserved role names on create', function () {
        $this->actingAs($this->actor);

        foreach (['super_admin', 'organisation_admin', 'member'] as $reserved) {
            Livewire::test(Edit::class)
                ->set('name', $reserved)
                ->call('save')
                ->assertHasErrors(['name']);
        }
    });

    it('rejects names that are not alpha_dash on create', function () {
        $this->actingAs($this->actor);

        Livewire::test(Edit::class)
            ->set('name', 'has spaces')
            ->call('save')
            ->assertHasErrors(['name']);
    });

    it('rejects a name that clashes with a template role on create', function () {
        Role::create(['name' => 'template_only', 'guard_name' => 'web', 'team_id' => null]);

        $this->actingAs($this->actor);

        Livewire::test(Edit::class)
            ->set('name', 'template_only')
            ->call('save')
            ->assertHasErrors(['name']);

        expect(Role::where('name', 'template_only')->where('team_id', $this->org->id)->exists())->toBeFalse();
    });

    it('rejects a name that already exists in the same team on create', function () {
        Role::create(['name' => 'redactor', 'guard_name' => 'web', 'team_id' => $this->org->id]);

        $this->actingAs($this->actor);

        Livewire::test(Edit::class)
            ->set('name', 'redactor')
            ->call('save')
            ->assertHasErrors(['name']);
    });
```

> Let op: `Role::create(...)` in de "rejects clash with template" test gebruikt het Spatie-model via `Spatie\Permission\Models\Role` of de app's `App\Models\Role`. Volg het voorbeeld in regel 124 van het bestaande bestand — dat gebruikt `Role::create(['name' => 'template_only', 'guard_name' => 'web', 'team_id' => null]);` zonder import van Spatie omdat het bestand `use Spatie\Permission\Models\Role;` mogelijk niet heeft. Check de imports bovenaan: `use App\Models\Role;` is er — dat is de juiste keuze (extends Spatie's Role).

- [ ] **Step 2: Run alle nieuwe validatietests**

```bash
php artisan test tests/Feature/Roles/RoleEditTest.php
```

Verwacht: alle nieuwe tests PASS direct (omdat `save()` ze al afhandelt).

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Roles/RoleEditTest.php
git commit -m "test(roles): pin validatie-gedrag van Roles\Edit in create-modus"
```

---

## Task 5: Update `roles/index.blade.php` — header met knop, empty-state, geen create-form

**Files:**
- Modify: `resources/views/livewire/roles/index.blade.php`
- Test: `tests/Feature/Roles/RoleManagementTest.php`

- [ ] **Step 1: Schrijf de falende tests voor de nieuwe view-structuur**

Voeg toe in `tests/Feature/Roles/RoleManagementTest.php`, binnen `describe('Roles Index Livewire', …)`:

```php
    it('renders a "Nieuwe rol" button visible to authorized users', function () {
        $this->actingAs($this->actor);

        $this->get(route('roles.index'))
            ->assertOk()
            ->assertSee('Nieuwe rol')
            ->assertSee(route('roles.create'));
    });

    it('does not render the inline create-form anymore', function () {
        $this->actingAs($this->actor);

        $this->get(route('roles.index'))
            ->assertOk()
            ->assertDontSee('Nieuwe rol aanmaken');
    });

    it('shows an empty-state callout when there are no visible roles', function () {
        // Ruim alle template-rollen op zodat de query een lege collectie oplevert.
        // (De per-org organisation_admin werd door de OrganisationObserver aangemaakt;
        // die soft-delete'n bevestigt het callout-pad.)
        Role::query()->forceDelete();

        $this->actingAs($this->actor);

        $this->get(route('roles.index'))
            ->assertOk()
            ->assertSee('Geen rollen gevonden.');
    });
```

> Let op: gebruik `Role::query()->forceDelete()` (hard delete) om alle rollen te verwijderen — soft delete zou `roles_count` op `users` corrupt laten en is hier niet nodig voor de test-isolatie (Pest gebruikt RefreshDatabase per test).

- [ ] **Step 2: Run de tests — verwacht falend**

```bash
php artisan test --filter="renders a \"Nieuwe rol\" button"
php artisan test --filter="does not render the inline create-form"
php artisan test --filter="shows an empty-state callout"
```

Verwacht:
- Eerste test: FAIL — "Nieuwe rol" niet zichtbaar (huidige tekst is "Nieuwe rol aanmaken").
- Tweede test: FAIL — "Nieuwe rol aanmaken" is wél zichtbaar.
- Derde test: FAIL — geen callout in de huidige view.

- [ ] **Step 3: Vervang de inhoud van `resources/views/livewire/roles/index.blade.php`**

Volledige nieuwe inhoud:

```blade
<div class="space-y-6">
    <div class="flex items-end justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Rollen en permissies') }}</flux:heading>
            <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
                {{ __('Beheer welke acties elke rol mag uitvoeren binnen :org.', ['org' => tenant()?->name ?? config('app.name')]) }}
            </flux:text>
        </div>
        @can('create', Spatie\Permission\Models\Role::class)
            <flux:button variant="primary" :href="route('roles.create')" wire:navigate>
                {{ __('Nieuwe rol') }}
            </flux:button>
        @endcan
    </div>

    @if ($roles->isEmpty())
        <flux:callout variant="secondary" icon="shield-check">{{ __('Geen rollen gevonden.') }}</flux:callout>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Rol') }}</flux:table.column>
                <flux:table.column>{{ __('Permissies') }}</flux:table.column>
                <flux:table.column>{{ __('Type') }}</flux:table.column>
                <flux:table.column>{{ __('Acties') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($roles as $role)
                    <flux:table.row :key="$role->id">
                        <flux:table.cell>{{ $role->name }}</flux:table.cell>
                        <flux:table.cell>{{ $role->permissions->pluck('name')->join(', ') ?: '—' }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($role->team_id === null)
                                <flux:badge>{{ __('Sjabloon') }}</flux:badge>
                            @else
                                <flux:badge variant="primary">{{ __('Aangepast') }}</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex gap-2">
                                @can('update', $role)
                                    <flux:button size="sm" variant="ghost" :href="route('roles.edit', $role)" wire:navigate>
                                        {{ __('Bewerken') }}
                                    </flux:button>
                                @endcan
                                @can('delete', $role)
                                    @if ($role->users_count > 0)
                                        <flux:tooltip :content="$role->users_count === 1
                                            ? __('Niet verwijderbaar — gekoppeld aan 1 gebruiker')
                                            : __('Niet verwijderbaar — gekoppeld aan :count gebruikers', ['count' => $role->users_count])">
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
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
</div>
```

Wijzigingen ten opzichte van de huidige view:
- `space-y-8` → `space-y-6` (consistent met `users/index.blade.php`).
- Header in flex met primary-knop rechts (vervangt `flux:card` met inline-form).
- Empty-state callout toegevoegd.
- Tabel zelf onveranderd.

- [ ] **Step 4: Run de tests om te bevestigen dat ze slagen**

```bash
php artisan test --filter="renders a \"Nieuwe rol\" button"
php artisan test --filter="does not render the inline create-form"
php artisan test --filter="shows an empty-state callout"
```

Verwacht: alle drie PASS.

- [ ] **Step 5: Run alle Roles Index-tests om regressie uit te sluiten**

```bash
php artisan test tests/Feature/Roles/RoleManagementTest.php
```

Verwacht: alle tests PASS — *behalve* mogelijk de oude test "creates a per-org role with selected permissions" (regel 77-91), die roept `->set('newRoleName', 'editor')->call('createRole')` aan op `Index`. Die properties bestaan nog tot Task 6, dus deze test slaagt nog steeds. Wel een waarschuwing: verwijder hem in Task 6.

- [ ] **Step 6: Commit**

```bash
git add resources/views/livewire/roles/index.blade.php tests/Feature/Roles/RoleManagementTest.php
git commit -m "$(cat <<'EOF'
feat(roles): index-view gelijkgetrokken met users-pagina

Header met primary 'Nieuwe rol'-knop rechts, inline create-form
verwijderd, empty-state callout toegevoegd. Alleen UI-laag —
component-state volgt in een aparte commit.
EOF
)"
```

---

## Task 6: Cleanup `Roles\Index` — verwijder dode create-state en oude test

`Index.php` bevat nog steeds `$newRoleName`, `$newRolePermissions`, `createRole()` en `allPermissions()`, plus `'permissions' => …` in render-data — dood code sinds Task 5. Ook de oude test `'creates a per-org role with selected permissions'` (regel 77-91) test gedrag dat niet meer bestaat.

**Files:**
- Modify: `app/Livewire/Roles/Index.php`
- Modify: `tests/Feature/Roles/RoleManagementTest.php`

- [ ] **Step 1: Verwijder de oude test in `tests/Feature/Roles/RoleManagementTest.php`**

Verwijder regels 77-91 (de hele `it('creates a per-org role with selected permissions', …)` test). De equivalente dekking zit nu in `RoleEditTest.php` Task 1 Step 5.

- [ ] **Step 2: Run de hele Roles-suite om te bevestigen dat de testverwijdering geen regressie veroorzaakt**

```bash
php artisan test tests/Feature/Roles
```

Verwacht: alle resterende tests PASS.

- [ ] **Step 3: Vereenvoudig `app/Livewire/Roles/Index.php`**

Vervang de volledige inhoud door:

```php
<?php

namespace App\Livewire\Roles;

use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

class Index extends Component
{
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
        $tenantId = tenant()?->id;

        return Role::query()
            ->where(function ($q) use ($tenantId) {
                $q->where('team_id', $tenantId)
                    ->orWhere(function ($q2) use ($tenantId) {
                        $q2->whereNull('team_id')
                            ->whereNotExists(function ($sub) use ($tenantId) {
                                $sub->select(DB::raw(1))
                                    ->from('roles as per_org')
                                    ->whereColumn('per_org.name', 'roles.name')
                                    ->where('per_org.team_id', $tenantId)
                                    ->whereNull('per_org.deleted_at');
                            });
                    });
            })
            ->with('permissions')
            ->withCount('users')
            ->orderBy('name')
            ->get();
    }

    #[Layout('components.layouts.app')]
    #[Title('Rollen en permissies')]
    public function render()
    {
        $this->authorize('viewAny', Role::class);

        return view('livewire.roles.index', [
            'roles' => $this->roles(),
        ]);
    }
}
```

Verwijderd ten opzichte van het origineel:
- `use Livewire\Attributes\Validate;` import.
- `use Spatie\Permission\Models\Permission;` import.
- `#[Validate(...)] public string $newRoleName = '';`
- `#[Validate('array')] public array $newRolePermissions = [];`
- `public function createRole(): void { … }`
- `public function allPermissions() { … }`
- `'permissions' => $this->allPermissions(),` uit de render-array.

- [ ] **Step 4: Run de hele Roles-suite om te bevestigen dat alles nog werkt**

```bash
php artisan test tests/Feature/Roles
```

Verwacht: alle tests PASS.

- [ ] **Step 5: Run de full suite om regressie elders uit te sluiten**

```bash
php artisan test
```

Verwacht: alle tests PASS.

- [ ] **Step 6: Pint check**

```bash
vendor/bin/pint --test
```

Als Pint wijzigingen voorstelt:

```bash
vendor/bin/pint
```

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/Roles/Index.php tests/Feature/Roles/RoleManagementTest.php
git commit -m "$(cat <<'EOF'
refactor(roles): strip create-state uit Roles\Index

newRoleName, newRolePermissions, createRole() en allPermissions()
verwijderd; oude createRole-test op Index verwijderd. Index is nu
puur een lijstcomponent — equivalent gedrag wordt in RoleEditTest
in create-modus gedekt.
EOF
)"
```

---

## Task 7: Manuele browser-verificatie en eindcheck

**Files:** geen — handmatige stappen plus full test run.

- [ ] **Step 1: Run de full Pest-suite**

```bash
php artisan test
```

Verwacht: alle tests PASS.

- [ ] **Step 2: Pint --test op de hele codebase**

```bash
vendor/bin/pint --test
```

Verwacht: "Nothing to fix" of vergelijkbaar.

- [ ] **Step 3: Start dev-server en log in als organisation_admin**

```bash
php artisan serve
```

In een andere terminal — `npm run dev` als nog niet draaiend.

Open `http://demo1.skv1.test:8000/admin/roles` (of jouw lokale subdomain — pas `demo1` aan naar een seed-org).

- [ ] **Step 4: Visuele controle van de roles-lijst**

Bevestig:
- Header toont titel + omschrijving links, **"Nieuwe rol"** primary-knop rechts (zelfde plaatsing als users/organisations).
- Geen inline "Nieuwe rol aanmaken"-card meer.
- Tabel toont alle bestaande rollen.
- Bewerk- en Verwijder-knoppen werken zoals voorheen.

- [ ] **Step 5: Visuele controle van de create-flow**

Klik **"Nieuwe rol"** → moet navigeren naar `/admin/roles/create`.

Bevestig:
- Heading toont **"Nieuwe rol"** (geen rolnaam erachter).
- Form toont leeg `name`-veld en alle permissies als checkboxes.
- Vul `name=test_role`, vink één permissie aan, klik **"Opslaan"**.
- Redirect naar `/admin/roles`, flash-bericht **"Rol aangemaakt."**, nieuwe rij **test_role** zichtbaar in de tabel.

- [ ] **Step 6: Visuele controle van de edit-flow (regressie)**

Klik **"Bewerken"** op `test_role`.

Bevestig:
- Heading toont **"Rol bewerken: test_role"**.
- Form is voorgevuld met naam en aangevinkte permissies.
- Wijzig naam naar `test_redactor`, klik **"Opslaan"** → redirect naar `/admin/roles`, flash **"Rol bijgewerkt."**, rij toont nu `test_redactor`.

- [ ] **Step 7: Visuele controle van de empty-state (optioneel)**

In tinker — verwijder alle rollen voor de huidige tenant en herlaad:

```bash
php artisan tinker
>>> App\Models\Role::query()->forceDelete();
```

(Let op: dit reset ook de seeded data. Daarna `php artisan db:seed --class=Database\Seeders\RolesAndPermissionsSeeder` om alles weer op te bouwen.)

Bevestig:
- Empty-state callout met tekst **"Geen rollen gevonden."** is zichtbaar.

- [ ] **Step 8: Eindcommit (alleen als manuele test issues blootlegt en je iets corrigeert)**

Als alles werkt zonder aanpassingen — geen extra commit nodig.

---

## Self-review notes

**Spec coverage check:**
- Routes (spec §Architectuur): Task 2 voegt `roles.create` toe.
- `Roles\Index` reduceren tot lijstcomponent: Task 6.
- `Roles\Edit` dual-mode + gedeelde validatie: Task 1.
- Index-view header + empty-state + geen create-form: Task 5.
- Edit-view dynamische heading: Task 3.
- Test verhuizen van Index→Edit + create-mode dekking: Task 1 Step 5 + Task 4 + Task 6 Step 1.
- Spec-bonus "create krijgt sjabloon-clash-validatie erbij": Task 4 (test pinning dit gedrag).

**Type-/naam-consistentie:** `?Role $role = null`-property in Edit gebruikt overal hetzelfde naam, `selectedPermissions` blijft consistent, `roles.create`-routenaam wordt in Task 2 geïntroduceerd en in Task 5 in de view + tests gebruikt.

**Geen placeholders:** Elke step bevat exacte commando's of volledige codeblokken.
