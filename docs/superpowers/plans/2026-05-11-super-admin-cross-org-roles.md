# Super_admin cross-org rol-overzicht en rol-verplaatsing — Implementatieplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Geef super_admin een cross-org rol-overzicht (alle rollen van alle orgs + templates met "Organisatie"-kolom) en de mogelijkheid de aan een per-org rol gekoppelde organisatie te wijzigen, mits er geen gebruikers gekoppeld zijn.

**Architecture:** De wijziging zit in twee Livewire 4 MFC-componenten en het Role-model. De policy hoeft niet aangepast — `RolePolicy::update` retourneert al `true` voor super_admin op iedere rol. Index-component splitst zijn query op `isSuperAdmin()`; edit-component krijgt een `organisationId`-property en server-side guard die alleen voor super_admin actief is.

**Tech Stack:** Laravel 13, Livewire 4 MFC (single-file `.php` + `.blade.php`), Flux UI Pro, Spatie laravel-permission (teams), Pest 4, SQLite (test) / PostgreSQL (prod).

**Spec:** `docs/superpowers/specs/2026-05-11-super-admin-cross-org-roles-design.md`

---

## File Structure

| Pad | Actie | Verantwoordelijkheid |
|---|---|---|
| `app/Models/Role.php` | Modify | `team()` BelongsTo-relatie naar `Organisation` |
| `resources/views/components/roles/⚡index/index.php` | Modify | Query splitsen op super_admin; eager-load `team` |
| `resources/views/components/roles/⚡index/index.blade.php` | Modify | Conditionele "Organisatie"-kolom voor super_admin |
| `resources/views/components/roles/⚡edit/edit.php` | Modify | `organisationId`-property, mount/render/save-logica voor move |
| `resources/views/components/roles/⚡edit/edit.blade.php` | Modify | Conditioneel `<flux:select>` voor super_admin op per-org rol |
| `tests/Feature/Roles/RoleManagementTest.php` | Modify | 4 nieuwe tests (T1-T4) voor cross-org index |
| `tests/Feature/Roles/RoleEditTest.php` | Modify | 6 nieuwe tests (T5-T10) voor edit-move-flow |

---

## Pre-flight

- [ ] **Step 0a: Bevestig baseline.**
  ```bash
  ./vendor/bin/pest tests/Feature/Roles 2>&1 | tail -3
  ```
  Expected: `passed`, 46 tests (de feature van vorige iteratie zit hierin).

- [ ] **Step 0b: Bevestig dat de huidige werkstaat schoon is op de spec-file na.** Verwacht twee changesets uncommitted:
  - De voorgaande super_admin/no_organisation feature (5 files).
  - De nieuwe spec.

- [ ] **Step 0c: Inspectie zonder bewerken** (lees deze om context te krijgen):
  - `app/Models/Role.php` — model dat we uitbreiden.
  - `app/Models/User.php::isSuperAdmin()` — detectie die we gebruiken.
  - `app/Observers/OrganisationObserver.php` — `Role::firstOrCreate`-patroon dat we in tests gebruiken om Spatie's `create`-check te omzeilen.
  - `resources/views/components/roles/⚡index/index.php` en `.blade.php` — huidige tenant-gescopte query en tabel.
  - `resources/views/components/roles/⚡edit/edit.php` en `.blade.php` — huidige edit-flow met `nameUnchanged`/`scopeTeamId`-logica.

---

## Task 1: `Role::team()` relatie

**Files:**
- Modify: `app/Models/Role.php`

- [ ] **Step 1.1: Voeg de `team` BelongsTo toe.** Open `app/Models/Role.php` en breid het model uit:

  ```php
  <?php

  namespace App\Models;

  use App\Contracts\TenantOwned;
  use Illuminate\Database\Eloquent\Relations\BelongsTo;
  use Illuminate\Database\Eloquent\SoftDeletes;
  use Spatie\Permission\Models\Role as SpatieRole;

  class Role extends SpatieRole implements TenantOwned
  {
      use SoftDeletes;

      public function team(): BelongsTo
      {
          return $this->belongsTo(Organisation::class, 'team_id');
      }
  }
  ```

- [ ] **Step 1.2: Snel sanity-check, geen test nodig (pure relatie).**
  ```bash
  ./vendor/bin/pint --test 2>&1 | tail -3
  ```
  Expected: `passed`.

- [ ] **Step 1.3: Commit.**
  ```bash
  git add app/Models/Role.php
  git commit -m "$(cat <<'EOF'
  feat(role): add team() relation to Organisation

  Lets blade views render $role->team->name without an extra query, used by
  the upcoming super_admin cross-org roles overview.

  Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
  EOF
  )"
  ```

---

## Task 2: Cross-org index voor super_admin (T1, T4)

**Files:**
- Modify: `resources/views/components/roles/⚡index/index.php`
- Modify: `tests/Feature/Roles/RoleManagementTest.php`

- [ ] **Step 2.1: Schrijf de twee falende tests** in `tests/Feature/Roles/RoleManagementTest.php`. Voeg ze toe in het `describe('Roles Index Livewire', function () { ... })`-blok, vóór de bestaande `'renders the "Geen organisatie" badge...'`-test:

  ```php
  it('shows per-org roles from another organisation to a super_admin', function () {
      $otherOrg = Organisation::factory()->create(['slug' => 'demo2']);
      // firstOrCreate bypasses Spatie's static create() team-scoped existence check.
      Role::firstOrCreate(['name' => 'foreign_role', 'guard_name' => 'web', 'team_id' => $otherOrg->id]);

      $superAdmin = User::factory()->for($this->org)->create();
      $superAdmin->assignRole('super_admin');

      $this->actingAs($superAdmin);

      Livewire::test('roles.index')
          ->assertSee('foreign_role');
  });

  it('does NOT show roles from another organisation to a regular org_admin (regression guard)', function () {
      $otherOrg = Organisation::factory()->create(['slug' => 'demo2']);
      Role::firstOrCreate(['name' => 'foreign_role', 'guard_name' => 'web', 'team_id' => $otherOrg->id]);

      $this->actingAs($this->actor);

      Livewire::test('roles.index')
          ->assertDontSee('foreign_role');
  });
  ```

- [ ] **Step 2.2: Run de tests, bevestig RED.**
  ```bash
  ./vendor/bin/pest tests/Feature/Roles/RoleManagementTest.php --filter="super_admin|regular org_admin" 2>&1 | tail -10
  ```
  Expected: de "super_admin" test faalt (vreemde rol niet zichtbaar). De "regular org_admin" test passt al per ongeluk omdat de bestaande query hem ook niet toont — dat is OK, hij dient als regressiebescherming na implementatie.

- [ ] **Step 2.3: Pas de query aan** in `resources/views/components/roles/⚡index/index.php`. Vervang de huidige `roles()`-methode door:

  ```php
  public function roles()
  {
      if (auth()->user()?->isSuperAdmin()) {
          return Role::query()
              ->with(['permissions', 'team'])
              ->withCount('users')
              ->orderByRaw('team_id IS NOT NULL')
              ->orderBy('team_id')
              ->orderBy('name')
              ->get();
      }

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
  ```

- [ ] **Step 2.4: Run beide tests, bevestig GREEN.**
  ```bash
  ./vendor/bin/pest tests/Feature/Roles/RoleManagementTest.php --filter="super_admin|regular org_admin" 2>&1 | tail -5
  ```
  Expected: 2 passed.

- [ ] **Step 2.5: Run de volledige `RoleManagementTest`-suite.**
  ```bash
  ./vendor/bin/pest tests/Feature/Roles/RoleManagementTest.php 2>&1 | tail -3
  ```
  Expected: passed (19 tests, was 17 + 2 nieuwe).

- [ ] **Step 2.6: Commit.**
  ```bash
  git add resources/views/components/roles/⚡index/index.php tests/Feature/Roles/RoleManagementTest.php
  git commit -m "$(cat <<'EOF'
  feat(roles): super_admin sees cross-org roles in index

  The roles index now branches on isSuperAdmin(): super_admin gets every
  role (templates + per-org of all orgs) eager-loading the team relation;
  org_admin keeps the existing tenant-scoped query verbatim.

  Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
  EOF
  )"
  ```

---

## Task 3: Organisatie-kolom in de index-tabel (T2, T3)

**Files:**
- Modify: `resources/views/components/roles/⚡index/index.blade.php`
- Modify: `tests/Feature/Roles/RoleManagementTest.php`

- [ ] **Step 3.1: Schrijf de twee falende tests** in `tests/Feature/Roles/RoleManagementTest.php`. Plaats ze net na de tests uit Task 2:

  ```php
  it('renders the "Organisatie" column with org name for super_admin', function () {
      $otherOrg = Organisation::factory()->create(['slug' => 'demo2', 'name' => 'Acme BV']);
      Role::firstOrCreate(['name' => 'foreign_role', 'guard_name' => 'web', 'team_id' => $otherOrg->id]);

      $superAdmin = User::factory()->for($this->org)->create();
      $superAdmin->assignRole('super_admin');

      $this->actingAs($superAdmin);

      $this->get(route('roles.index'))
          ->assertOk()
          ->assertSee('Organisatie')
          ->assertSee('Acme BV');
  });

  it('renders "Geen organisatie" in the org column for template roles (super_admin view)', function () {
      $superAdmin = User::factory()->for($this->org)->create();
      $superAdmin->assignRole('super_admin');

      $this->actingAs($superAdmin);

      // organisation_admin template is seeded with team_id=NULL by RolesAndPermissionsSeeder.
      $this->get(route('roles.index'))
          ->assertOk()
          ->assertSee('Geen organisatie');
  });
  ```

  Opmerking: De tweede test slaagt strikt genomen al door de bestaande "Geen organisatie" badge in de Type-kolom. Hij blijft toch nuttig — als we later de badge zouden hernoemen breekt deze test ook, wat we willen.

- [ ] **Step 3.2: Run de tests, bevestig RED voor de Organisatie-kolom-test.**
  ```bash
  ./vendor/bin/pest tests/Feature/Roles/RoleManagementTest.php --filter="Organisatie column|Geen organisatie in the org column" 2>&1 | tail -10
  ```
  Expected: de "Organisatie column"-test faalt op de assertion `assertSee('Acme BV')` (de orgnaam wordt nog niet gerenderd).

- [ ] **Step 3.3: Pas de blade-tabel aan.** Open `resources/views/components/roles/⚡index/index.blade.php` en pas alleen het `<flux:table>`-blok aan. Voeg een conditioneel `<flux:table.column>` toe na `Rol` en een conditionele `<flux:table.cell>` na de naam-cel:

  ```html
  <flux:table>
      <flux:table.columns>
          <flux:table.column>{{ __('Rol') }}</flux:table.column>
          @if (auth()->user()?->isSuperAdmin())
              <flux:table.column>{{ __('Organisatie') }}</flux:table.column>
          @endif
          <flux:table.column>{{ __('Permissies') }}</flux:table.column>
          <flux:table.column>{{ __('Type') }}</flux:table.column>
          <flux:table.column>{{ __('Acties') }}</flux:table.column>
      </flux:table.columns>
      <flux:table.rows>
          @foreach ($roles as $role)
              <flux:table.row :key="$role->id">
                  <flux:table.cell>{{ $role->name }}</flux:table.cell>
                  @if (auth()->user()?->isSuperAdmin())
                      <flux:table.cell>
                          {{ $role->team?->name ?? __('Geen organisatie') }}
                      </flux:table.cell>
                  @endif
                  <flux:table.cell>{{ $role->permissions->pluck('name')->join(', ') ?: '—' }}</flux:table.cell>
                  {{-- rest unchanged --}}
  ```

  De rest van de table-row (Type, Acties) blijft ongewijzigd.

- [ ] **Step 3.4: Run de tests, bevestig GREEN.**
  ```bash
  ./vendor/bin/pest tests/Feature/Roles/RoleManagementTest.php --filter="Organisatie column|Geen organisatie in the org column" 2>&1 | tail -5
  ```
  Expected: 2 passed.

- [ ] **Step 3.5: Run de hele `RoleManagementTest`-suite.**
  ```bash
  ./vendor/bin/pest tests/Feature/Roles/RoleManagementTest.php 2>&1 | tail -3
  ```
  Expected: 21 passed.

- [ ] **Step 3.6: Commit.**
  ```bash
  git add resources/views/components/roles/⚡index/index.blade.php tests/Feature/Roles/RoleManagementTest.php
  git commit -m "$(cat <<'EOF'
  feat(roles): show Organisation column to super_admin in roles index

  Adds an "Organisatie" table column visible only to super_admin, populated
  from the new Role::team relation. Template rows render "Geen organisatie"
  for consistency with the Type badge.

  Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
  EOF
  )"
  ```

---

## Task 4: `organisationId`-property + zichtbaarheid van het select-veld (T8, T9)

**Files:**
- Modify: `resources/views/components/roles/⚡edit/edit.php`
- Modify: `resources/views/components/roles/⚡edit/edit.blade.php`
- Modify: `tests/Feature/Roles/RoleEditTest.php`

- [ ] **Step 4.1: Schrijf twee falende UI-conditional tests** in `tests/Feature/Roles/RoleEditTest.php`. Plaats ze binnen het bestaande `describe('Super admin template editing', ...)`-blok aan het eind:

  ```php
  it('does NOT render the organisation select for a regular org_admin editing a per-org role', function () {
      $role = Role::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);
      $this->actingAs($this->actor);

      $this->get(route('roles.edit', $role))
          ->assertOk()
          ->assertDontSee('wire:model="organisationId"', false);
  });

  it('does NOT render the organisation select for a super_admin editing a template role', function () {
      $superAdmin = User::factory()->for($this->org)->create();
      $superAdmin->assignRole('super_admin');

      $template = Role::where('name', 'organisation_admin')->whereNull('team_id')->firstOrFail();

      $this->actingAs($superAdmin);

      $this->get(route('roles.edit', $template))
          ->assertOk()
          ->assertDontSee('wire:model="organisationId"', false);
  });
  ```

  Het tweede argument `false` op `assertDontSee` zet HTML-escape uit zodat het wire-model attribuut letterlijk gematcht wordt.

- [ ] **Step 4.2: Run de tests, bevestig RED.**
  ```bash
  ./vendor/bin/pest tests/Feature/Roles/RoleEditTest.php --filter="does NOT render the organisation select" 2>&1 | tail -10
  ```
  Expected: Beide tests passen meteen — er IS nog geen select-veld. Dit zijn negatieve tests: ze beschermen tegen toekomstige onbedoelde zichtbaarheid. Dat is OK; ze bewijzen niet de implementatie maar dekken een regressie-risico zodra het veld bestaat. We zetten ze GREEN voor implementatie en verwachten dat ze GREEN blijven.

  **Subtask:** Voeg ALSNOG één direct-RED test toe waarin het select WEL zichtbaar moet zijn voor super_admin op een per-org rol. Voeg toe binnen hetzelfde describe-blok:

  ```php
  it('renders the organisation select for a super_admin editing a per-org role', function () {
      $superAdmin = User::factory()->for($this->org)->create();
      $superAdmin->assignRole('super_admin');

      $role = Role::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);

      $this->actingAs($superAdmin);

      $this->get(route('roles.edit', $role))
          ->assertOk()
          ->assertSee('wire:model="organisationId"', false);
  });
  ```

  Run:
  ```bash
  ./vendor/bin/pest tests/Feature/Roles/RoleEditTest.php --filter="renders the organisation select" 2>&1 | tail -10
  ```
  Expected: RED — "wire:model=\"organisationId\"" wordt niet gevonden.

- [ ] **Step 4.3: Implementeer property + select in de component.** Open `resources/views/components/roles/⚡edit/edit.php`. Voeg property toe boven `mount()`:

  ```php
  public ?int $organisationId = null;
  ```

  Voeg toe aan `use`-imports:

  ```php
  use App\Models\Organisation;
  ```

  Pas `mount()` aan zodat na de bestaande mount-logica de property wordt geïnit:

  ```php
  public function mount(?Role $role = null): void
  {
      if ($role && $role->exists) {
          $this->authorize('update', $role);

          $this->role = $role;
          $this->name = $role->name;
          $this->selectedPermissions = $role->permissions->pluck('id')->all();
          $this->organisationId = $role->team_id;
      } else {
          $this->authorize('create', Role::class);
      }
  }
  ```

  Pas `render()` aan zodat de organisaties-lijst meegegeven wordt aan de view (alleen wanneer relevant — altijd doorgeven kan ook, kost weinig):

  ```php
  #[Layout('components.layouts.app')]
  #[Title('Rol bewerken')]
  public function render()
  {
      return $this->view([
          'permissions' => Permission::orderBy('name')->get(),
          'organisations' => Organisation::orderBy('name')->get(),
      ]);
  }
  ```

- [ ] **Step 4.4: Voeg het select-veld toe** in `resources/views/components/roles/⚡edit/edit.blade.php`, **direct na** `<flux:input wire:model="name" .../>` en **vóór** de `<fieldset>`:

  ```html
  @if (auth()->user()?->isSuperAdmin() && $role && $role->team_id !== null)
      <flux:select wire:model="organisationId" label="{{ __('Organisatie') }}">
          @foreach ($organisations as $organisation)
              <flux:select.option value="{{ $organisation->id }}">{{ $organisation->name }}</flux:select.option>
          @endforeach
      </flux:select>
  @endif
  ```

- [ ] **Step 4.5: Run de drie zichtbaarheids-tests, bevestig GREEN.**
  ```bash
  ./vendor/bin/pest tests/Feature/Roles/RoleEditTest.php --filter="organisation select" 2>&1 | tail -5
  ```
  Expected: 3 passed.

- [ ] **Step 4.6: Run de hele `RoleEditTest`-suite.**
  ```bash
  ./vendor/bin/pest tests/Feature/Roles/RoleEditTest.php 2>&1 | tail -3
  ```
  Expected: 28 passed (25 oud + 3 nieuw).

- [ ] **Step 4.7: Commit.**
  ```bash
  git add resources/views/components/roles/⚡edit/edit.php resources/views/components/roles/⚡edit/edit.blade.php tests/Feature/Roles/RoleEditTest.php
  git commit -m "$(cat <<'EOF'
  feat(roles): add organisation select to edit form for super_admin

  Super_admin editing a per-org role now sees an Organisatie select bound to
  $organisationId, listing every Organisation. Templates and non-super-admins
  do not see the field. The select does not save anything yet; the move-logic
  is added in the next commit.

  Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
  EOF
  )"
  ```

---

## Task 5: Save met org-wijziging (T5)

**Files:**
- Modify: `resources/views/components/roles/⚡edit/edit.php`
- Modify: `tests/Feature/Roles/RoleEditTest.php`

- [ ] **Step 5.1: Schrijf de happy-path move-test** in het `describe('Super admin template editing', ...)`-blok:

  ```php
  it('lets super_admin move a per-org role to a different organisation when no users are attached', function () {
      $otherOrg = Organisation::factory()->create(['slug' => 'demo2']);
      $role = Role::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);

      $superAdmin = User::factory()->for($this->org)->create();
      $superAdmin->assignRole('super_admin');

      $this->actingAs($superAdmin);

      Livewire::test('roles.edit', ['role' => $role])
          ->set('organisationId', $otherOrg->id)
          ->call('save')
          ->assertHasNoErrors()
          ->assertRedirect(route('roles.index'));

      expect($role->fresh()->team_id)->toBe($otherOrg->id);
  });
  ```

- [ ] **Step 5.2: Run de test, bevestig RED.**
  ```bash
  ./vendor/bin/pest tests/Feature/Roles/RoleEditTest.php --filter="move a per-org role to a different" 2>&1 | tail -10
  ```
  Expected: RED — `$role->team_id` blijft `$this->org->id`.

- [ ] **Step 5.3: Implementeer move-logica.** In `resources/views/components/roles/⚡edit/edit.php` pas `save()` aan. Direct na de bestaande regel `$nameUnchanged = ...;`, voeg toe:

  ```php
  $isSuperAdmin = auth()->user()?->isSuperAdmin() ?? false;
  $wantsMove = $this->role !== null
      && $isSuperAdmin
      && $this->role->team_id !== null
      && $this->organisationId !== null
      && (int) $this->organisationId !== (int) $this->role->team_id;

  $targetTeamId = $wantsMove
      ? (int) $this->organisationId
      : ($this->role !== null ? $this->role->team_id : tenant()?->id);
  ```

  Vervang de bestaande regel `$scopeTeamId = $this->role !== null ? $this->role->team_id : tenant()?->id;` — die wordt nu vervangen door `$targetTeamId` (verzeker dat de `Rule::unique`-rule `where('team_id', $targetTeamId)` gebruikt in plaats van `$scopeTeamId`).

  In de `DB::transaction`-callback, pas de update-tak aan:

  ```php
  if ($this->role) {
      $update = ['name' => $this->name];
      if ($wantsMove) {
          $update['team_id'] = $targetTeamId;
      }
      $this->role->update($update);
  } else {
      // create-tak ongewijzigd
  }
  ```

  Voeg ook regel voor validatie van `organisationId` toe aan de validate()-array:

  ```php
  'organisationId' => ['nullable', 'integer', Rule::exists('organisations', 'id')],
  ```

- [ ] **Step 5.4: Run de test, bevestig GREEN.**
  ```bash
  ./vendor/bin/pest tests/Feature/Roles/RoleEditTest.php --filter="move a per-org role to a different" 2>&1 | tail -5
  ```
  Expected: 1 passed.

- [ ] **Step 5.5: Regressie-check op de hele `RoleEditTest`-suite.**
  ```bash
  ./vendor/bin/pest tests/Feature/Roles/RoleEditTest.php 2>&1 | tail -3
  ```
  Expected: 29 passed.

- [ ] **Step 5.6: Commit.**
  ```bash
  git add resources/views/components/roles/⚡edit/edit.php tests/Feature/Roles/RoleEditTest.php
  git commit -m "$(cat <<'EOF'
  feat(roles): super_admin can move a per-org role to another organisation

  When super_admin changes organisationId on a per-org role's edit form, the
  role's team_id is updated. Uniqueness scope now follows the target team_id,
  so a name clash in the destination organisation surfaces as a validation
  error instead of a hidden conflict.

  Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
  EOF
  )"
  ```

---

## Task 6: Verplaatsing blokkeren bij gekoppelde gebruikers (T6)

**Files:**
- Modify: `resources/views/components/roles/⚡edit/edit.php`
- Modify: `tests/Feature/Roles/RoleEditTest.php`

- [ ] **Step 6.1: Schrijf de blokkade-test.**

  ```php
  it('blocks super_admin from moving a role that still has users attached', function () {
      $otherOrg = Organisation::factory()->create(['slug' => 'demo2']);
      $role = Role::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);
      $member = User::factory()->for($this->org)->create();
      $member->assignRole($role);

      $superAdmin = User::factory()->for($this->org)->create();
      $superAdmin->assignRole('super_admin');

      $this->actingAs($superAdmin);

      Livewire::test('roles.edit', ['role' => $role])
          ->set('organisationId', $otherOrg->id)
          ->call('save')
          ->assertHasErrors(['organisationId']);

      expect($role->fresh()->team_id)->toBe($this->org->id);
  });
  ```

- [ ] **Step 6.2: Run de test, bevestig RED.**
  ```bash
  ./vendor/bin/pest tests/Feature/Roles/RoleEditTest.php --filter="blocks super_admin from moving" 2>&1 | tail -10
  ```
  Expected: RED — er wordt geen error op `organisationId` opgegooid, `team_id` is gewijzigd.

- [ ] **Step 6.3: Voeg closure-validatie toe** in de `validate()`-array onder de `'organisationId'`-key:

  ```php
  'organisationId' => [
      'nullable', 'integer', Rule::exists('organisations', 'id'),
      function ($attribute, $value, $fail) use ($wantsMove): void {
          if (! $wantsMove) {
              return;
          }
          $usersCount = DB::table('model_has_roles')
              ->where('role_id', $this->role->id)
              ->count();
          if ($usersCount > 0) {
              $fail(__('Verplaatsen geblokkeerd: er zijn nog gebruikers aan deze rol gekoppeld.'));
          }
      },
  ],
  ```

  Let op: `DB` is al geïmporteerd in dit bestand.

- [ ] **Step 6.4: Run de test, bevestig GREEN.**
  ```bash
  ./vendor/bin/pest tests/Feature/Roles/RoleEditTest.php --filter="blocks super_admin from moving" 2>&1 | tail -5
  ```
  Expected: 1 passed.

- [ ] **Step 6.5: Run de hele `RoleEditTest`-suite.**
  ```bash
  ./vendor/bin/pest tests/Feature/Roles/RoleEditTest.php 2>&1 | tail -3
  ```
  Expected: 30 passed.

- [ ] **Step 6.6: Commit.**
  ```bash
  git add resources/views/components/roles/⚡edit/edit.php tests/Feature/Roles/RoleEditTest.php
  git commit -m "$(cat <<'EOF'
  feat(roles): block role-move when users still attached

  Adds a closure validator on organisationId that counts assignments in the
  model_has_roles pivot. Any non-zero count blocks the save with a Dutch
  message, mirroring the existing delete-with-users guard.

  Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
  EOF
  )"
  ```

---

## Task 7: Uniqueness op target-org (T7)

**Files:**
- Modify: `tests/Feature/Roles/RoleEditTest.php`

Niets te implementeren — Task 5 zet uniqueness al op `$targetTeamId`. We voegen enkel een expliciete regressietest toe.

- [ ] **Step 7.1: Schrijf de uniqueness-test.**

  ```php
  it('rejects a move when the target org already has a role with the same name', function () {
      $otherOrg = Organisation::factory()->create(['slug' => 'demo2']);
      Role::firstOrCreate(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $otherOrg->id]);

      $role = Role::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);

      $superAdmin = User::factory()->for($this->org)->create();
      $superAdmin->assignRole('super_admin');

      $this->actingAs($superAdmin);

      Livewire::test('roles.edit', ['role' => $role])
          ->set('organisationId', $otherOrg->id)
          ->call('save')
          ->assertHasErrors(['name']);

      expect($role->fresh()->team_id)->toBe($this->org->id);
  });
  ```

- [ ] **Step 7.2: Run, verwacht GREEN.**
  ```bash
  ./vendor/bin/pest tests/Feature/Roles/RoleEditTest.php --filter="rejects a move when the target org" 2>&1 | tail -5
  ```
  Expected: 1 passed.

- [ ] **Step 7.3: Commit.**
  ```bash
  git add tests/Feature/Roles/RoleEditTest.php
  git commit -m "$(cat <<'EOF'
  test(roles): regression guard for uniqueness scope on role-move

  Locks in that moving a role to an org with a same-named role fails on
  unique validation in the target scope, not silently.

  Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
  EOF
  )"
  ```

---

## Task 8: Privilege-escalation guard (T10)

**Files:**
- Modify: `tests/Feature/Roles/RoleEditTest.php`

Geen implementatie nodig — `$wantsMove` vereist al `$isSuperAdmin`. Test legt het vast.

- [ ] **Step 8.1: Schrijf de security-test.**

  ```php
  it('ignores organisationId from a non-super-admin payload (no privilege escalation)', function () {
      $otherOrg = Organisation::factory()->create(['slug' => 'demo2']);
      $role = Role::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);

      $this->actingAs($this->actor);

      // Even though the org_admin payload sets organisationId, the server-side
      // $wantsMove gate requires isSuperAdmin() so team_id must not change.
      Livewire::test('roles.edit', ['role' => $role])
          ->set('organisationId', $otherOrg->id)
          ->call('save');

      expect($role->fresh()->team_id)->toBe($this->org->id);
  });
  ```

- [ ] **Step 8.2: Run, verwacht GREEN.**
  ```bash
  ./vendor/bin/pest tests/Feature/Roles/RoleEditTest.php --filter="ignores organisationId from a non-super-admin" 2>&1 | tail -5
  ```
  Expected: 1 passed.

- [ ] **Step 8.3: Commit.**
  ```bash
  git add tests/Feature/Roles/RoleEditTest.php
  git commit -m "$(cat <<'EOF'
  test(roles): regression guard against organisationId privilege escalation

  Asserts that an org_admin payload containing organisationId cannot move
  the role; the server-side super_admin gate is the only authoriser.

  Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
  EOF
  )"
  ```

---

## Verification

- [ ] **Step V.1: Volledige roles-test-suite.**
  ```bash
  ./vendor/bin/pest tests/Feature/Roles 2>&1 | tail -3
  ```
  Expected: 57 passed (46 baseline + 11 nieuw).

- [ ] **Step V.2: Volledige pest-suite (regressie).**
  ```bash
  ./vendor/bin/pest 2>&1 | tail -3
  ```
  Expected: alle tests passed; verwacht aantal ≈ 273 (262 baseline + 11 nieuw).

- [ ] **Step V.3: Pint.**
  ```bash
  ./vendor/bin/pint --test 2>&1 | tail -3
  ```
  Expected: passed.

- [ ] **Step V.4: Hand-check (optioneel maar aangeraden).** Start `php artisan serve`, log in als een super_admin op een tenant-subdomein, ga naar `/admin/roles`, controleer:
  - Tabel toont rijen van andere orgs met juiste org-naam.
  - Templates tonen "Geen organisatie".
  - Edit-page van een per-org rol toont de Organisatie-dropdown.
  - Edit-page van een template toont geen dropdown.
  - Verplaatsen van rol zonder users → lukt, terug naar index, rol staat onder nieuwe org.
  - Verplaatsen van rol met user-koppeling → blokkade-melding.
