# User Edit — Role Picker Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a role multi-select to the `Users/Edit` page so admins can change a user's roles after activation, backed by a reusable `App\Services\UserRoleSyncer` that handles regular roles (team-scoped) and `super_admin` (cross-org binary state) consistently across the invite-flow and the new edit-flow.

**Architecture:** Extract the cross-org propagation logic from `InvitationService::invite()` into a new `UserRoleSyncer` service with a single `sync(User $user, array $roles, int $primaryOrgId)` method. The service is consumed by both `InvitationService` (refactor; behavior-neutral) and the new `Users/Edit` component (new use case). The Edit blade view gains a `<flux:checkbox.group>` bound to `$roles`, populated from the user's current state in `mount()` and saved via the syncer in `save()` inside a `DB::transaction`.

**Tech Stack:** Laravel 13 · Livewire 4 · Flux UI (free) · Spatie Permissions (teams enabled) · PostgreSQL · Pest · Pint

**Spec:** `docs/superpowers/specs/2026-05-09-user-edit-role-picker-design.md`

---

## File Structure

| File | Action | Responsibility |
|---|---|---|
| `app/Services/UserRoleSyncer.php` | Create | Single `sync()` entry-point. Regular roles via `syncRoles` in primary-org scope; `super_admin` cross-org binary via assign/remove loops. try/finally restores team-id. |
| `app/Services/InvitationService.php` | Modify | Replace inline role loop + cross-org propagation block with one `UserRoleSyncer::sync()` call. |
| `app/Livewire/Users/Edit.php` | Modify | New `roles[]` property + `availableRoles()` helper; load existing roles in `mount()`; add `roles.*` validation + syncer call inside `DB::transaction` in `save()`. |
| `resources/views/livewire/users/edit.blade.php` | Modify | Add `<flux:checkbox.group wire:model="roles">` between locale select and submit buttons. |
| `tests/Feature/Users/UserRoleSyncerTest.php` | Create | 5 service tests covering regular sync, cross-org super_admin add/remove, idempotency, exception-safety. |
| `tests/Feature/Users/UserCrudTest.php` | Modify | 6 new tests inside `describe('Users Edit Livewire', ...)` covering visibility scoping, save paths, spoof rejection, self-edit. |

No new permissions, no new migrations, no view-route changes.

---

## Task 1: `UserRoleSyncer` service — RED→GREEN→COMMIT

**Goal:** Implement the new service with TDD discipline. Service is the foundation that Tasks 2 and 3 build on.

**Files:**
- Create: `app/Services/UserRoleSyncer.php`
- Create: `tests/Feature/Users/UserRoleSyncerTest.php`

- [ ] **Step 1: Write the 5 failing tests**

Create `tests/Feature/Users/UserRoleSyncerTest.php`:

```php
<?php

use App\Models\Organisation;
use App\Models\User;
use App\Services\UserRoleSyncer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->orgA = Organisation::factory()->create(['slug' => 'syncer-a']);
    $this->orgB = Organisation::factory()->create(['slug' => 'syncer-b']);
});

describe('UserRoleSyncer::sync', function () {
    it('assigns regular roles in primary org only', function () {
        $user = User::factory()->for($this->orgA)->create();

        app(UserRoleSyncer::class)->sync($user, ['organisation_admin'], $this->orgA->id);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->orgA->id);
        expect($user->fresh()->hasRole('organisation_admin'))->toBeTrue();

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->orgB->id);
        expect($user->fresh()->hasRole('organisation_admin'))->toBeFalse();
    });

    it('propagates super_admin to all orgs when added', function () {
        $user = User::factory()->create(['organisation_id' => null]);

        app(UserRoleSyncer::class)->sync($user, ['super_admin'], $this->orgA->id);

        foreach ([$this->orgA, $this->orgB] as $org) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
            expect($user->fresh()->hasRole('super_admin'))
                ->toBeTrue("expected super_admin in {$org->slug}");
        }
    });

    it('removes super_admin from all orgs when removed', function () {
        $user = User::factory()->create(['organisation_id' => null]);

        // Pre-state: super_admin in both orgs.
        foreach ([$this->orgA, $this->orgB] as $org) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
            $user->assignRole('super_admin');
        }

        app(UserRoleSyncer::class)->sync($user, [], $this->orgA->id);

        foreach ([$this->orgA, $this->orgB] as $org) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
            expect($user->fresh()->hasRole('super_admin'))
                ->toBeFalse("expected NO super_admin in {$org->slug}");
        }
    });

    it('is idempotent when role state already matches selection', function () {
        $user = User::factory()->for($this->orgA)->create();

        app(UserRoleSyncer::class)->sync($user, ['test1'], $this->orgA->id);
        app(UserRoleSyncer::class)->sync($user, ['test1'], $this->orgA->id);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->orgA->id);
        expect($user->fresh()->getRoleNames()->all())->toBe(['test1']);
    });

    it('restores setPermissionsTeamId after the sync completes', function () {
        $user = User::factory()->for($this->orgA)->create();

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->orgB->id);
        app(UserRoleSyncer::class)->sync($user, ['test2'], $this->orgA->id);

        expect(app(PermissionRegistrar::class)->getPermissionsTeamId())
            ->toBe($this->orgB->id);
    });
});
```

- [ ] **Step 2: Run the new tests to verify they fail**

Run: `cd /Users/frankcornet/skv1 && ./vendor/bin/pest tests/Feature/Users/UserRoleSyncerTest.php`
Expected: FAIL — `App\Services\UserRoleSyncer` does not exist.

- [ ] **Step 3: Create the service**

Create `app/Services/UserRoleSyncer.php`:

```php
<?php

namespace App\Services;

use App\Models\Organisation;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

class UserRoleSyncer
{
    /**
     * Set the user's role state to exactly $selectedRoles, scoped to
     * $primaryOrganisationId. Regular roles (everything except super_admin)
     * are synced within the primary org's team scope. The `super_admin`
     * role is treated as cross-org binary state — when present, it gets
     * assigned in every organisation; when absent, it gets removed
     * everywhere.
     *
     * @param  array<int,string>  $selectedRoles  internal role names
     */
    public function sync(User $user, array $selectedRoles, int $primaryOrganisationId): void
    {
        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();

        try {
            $regular = array_values(array_diff($selectedRoles, ['super_admin']));
            $wantsSuperAdmin = in_array('super_admin', $selectedRoles, true);

            // Regular roles: sync inside the primary-org team scope.
            $registrar->setPermissionsTeamId($primaryOrganisationId);
            $user->syncRoles($regular);

            // super_admin: cross-org binary state.
            // Use direct relationship query with team-scoping disabled to
            // see assignments under any team_id (mirrors User::isSuperAdmin).
            $teamsEnabled = $registrar->teams;
            $registrar->teams = false;
            try {
                $hasSuperAdminAnywhere = $user->roles()
                    ->where('name', 'super_admin')
                    ->exists();
            } finally {
                $registrar->teams = $teamsEnabled;
            }

            if ($wantsSuperAdmin && ! $hasSuperAdminAnywhere) {
                foreach (Organisation::all() as $org) {
                    $registrar->setPermissionsTeamId($org->id);
                    if (! $user->hasRole('super_admin')) {
                        $user->assignRole('super_admin');
                    }
                }
            }

            if (! $wantsSuperAdmin && $hasSuperAdminAnywhere) {
                foreach (Organisation::all() as $org) {
                    $registrar->setPermissionsTeamId($org->id);
                    if ($user->hasRole('super_admin')) {
                        $user->removeRole('super_admin');
                    }
                }
            }
        } finally {
            $registrar->setPermissionsTeamId($previousTeamId);
        }
    }
}
```

- [ ] **Step 4: Run new tests + full suite**

Run: `cd /Users/frankcornet/skv1 && ./vendor/bin/pest tests/Feature/Users/UserRoleSyncerTest.php`
Expected: PASS — 5 tests green.

Run: `cd /Users/frankcornet/skv1 && ./vendor/bin/pest`
Expected: PASS — 167 baseline + 5 new = 172 tests. No regressions.

- [ ] **Step 5: Pint + commit**

```bash
cd /Users/frankcornet/skv1
./vendor/bin/pint app/Services/UserRoleSyncer.php tests/Feature/Users/UserRoleSyncerTest.php
git add app/Services/UserRoleSyncer.php tests/Feature/Users/UserRoleSyncerTest.php
git commit -m "$(cat <<'EOF'
feat(users): UserRoleSyncer service for cross-org role assignment

Single sync(User, array, int) entry-point that:
- syncRoles regular roles inside the primary-org team scope (covers
  add and remove within that scope in one call)
- handles super_admin as cross-org binary state — assigns in every
  org if delta adds it, removes from every org if delta removes it
- restores the previous setPermissionsTeamId in try/finally

The cross-org super_admin existence check temporarily disables
Spatie's team-scoping (mirroring User::isSuperAdmin) so it sees the
role under any team_id.

Five Pest tests cover regular sync isolation, cross-org propagation
add/remove paths, idempotency, and team-id restoration.
EOF
)"
```

---

## Task 2: Refactor `InvitationService` to use `UserRoleSyncer`

**Goal:** Replace the inline role-assignment block in `InvitationService::invite()` with a single call to the new syncer. Behavior-neutral — existing InvitationServiceTest tests must stay green without modification.

**Files:**
- Modify: `app/Services/InvitationService.php`

- [ ] **Step 1: Read current `invite()` to identify the block to replace**

The current method has this inside the `DB::transaction(...)` closure (after `$user = User::create([...])` and before `Invitation::create([...])`):

```php
$registrar = app(\Spatie\Permission\PermissionRegistrar::class);
$previousTeamId = $registrar->getPermissionsTeamId();
$registrar->setPermissionsTeamId($organisationId);

try {
    foreach ($roles as $roleName) {
        $user->assignRole($roleName);
    }

    if (in_array('super_admin', $roles, true)) {
        foreach (\App\Models\Organisation::where('id', '!=', $organisationId)->get() as $otherOrg) {
            $registrar->setPermissionsTeamId($otherOrg->id);
            $user->assignRole('super_admin');
        }
    }
} finally {
    $registrar->setPermissionsTeamId($previousTeamId);
}
```

(The exact text may be slightly different — read the file first to find the actual block.)

- [ ] **Step 2: Replace the block with one syncer call**

Replace the block above with:

```php
app(\App\Services\UserRoleSyncer::class)->sync($user, $roles, $organisationId);
```

This is the entire role-assignment logic now. Leave everything else (User::create, Invitation::create, Mail::queue, activity log, fresh()->load, return) unchanged.

- [ ] **Step 3: Run InvitationServiceTest + full suite**

Run: `cd /Users/frankcornet/skv1 && ./vendor/bin/pest tests/Feature/Invitations/InvitationServiceTest.php`
Expected: PASS — all 13 service tests, including the cross-org propagation test (`propagates super_admin role across all organisations on invite`) which now exercises the syncer's cross-org loop.

Run: `cd /Users/frankcornet/skv1 && ./vendor/bin/pest`
Expected: PASS — full suite green at 172.

- [ ] **Step 4: Pint + commit**

```bash
cd /Users/frankcornet/skv1
./vendor/bin/pint app/Services/InvitationService.php
git add app/Services/InvitationService.php
git commit -m "$(cat <<'EOF'
refactor(invitations): delegate role assignment to UserRoleSyncer

InvitationService::invite() now hands the role-assignment work to
UserRoleSyncer::sync. Behaviour is identical — pinned by the existing
'propagates super_admin role across all organisations on invite' test
in InvitationServiceTest, which continues to pass without modification.
EOF
)"
```

---

## Task 3: `Users/Edit` component — load roles in mount + sync in save

**Goal:** Wire the role state into the Edit form. `mount()` pre-fills `$roles`; `save()` validates the selection and calls the syncer inside a `DB::transaction` together with the existing `$this->user->update(...)`.

**Files:**
- Modify: `app/Livewire/Users/Edit.php`

This task does NOT touch the blade view yet (Task 4 does). UI tests come in Task 5. After this task the view is misaligned but PHP-level callsites work.

- [ ] **Step 1: Replace `app/Livewire/Users/Edit.php` integrally**

Use this exact content:

```php
<?php

namespace App\Livewire\Users;

use App\Http\Requests\UpdateUserRequest;
use App\Models\Organisation;
use App\Models\User;
use App\Services\UserRoleSyncer;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Spatie\Permission\PermissionRegistrar;

class Edit extends Component
{
    public User $user;

    public string $first_name = '';

    public string $middle_name = '';

    public string $last_name = '';

    public string $internal_id = '';

    public string $phone = '';

    public string $address = '';

    public string $start_date = '';

    public string $end_date = '';

    public string $email = '';

    public string $status = '';

    public string $locale = '';

    public array $roles = [];

    public function mount(User $user): void
    {
        $this->authorize('update', $user);

        $this->user = $user;
        $this->first_name = $user->first_name ?? '';
        $this->middle_name = $user->middle_name ?? '';
        $this->last_name = $user->last_name ?? '';
        $this->internal_id = $user->internal_id ?? '';
        $this->phone = $user->phone ?? '';
        $this->address = $user->address ?? '';
        $this->start_date = $user->start_date?->toDateString() ?? '';
        $this->end_date = $user->end_date?->toDateString() ?? '';
        $this->email = $user->email;
        $this->status = $user->status;
        $this->locale = $user->locale;

        $this->roles = $this->loadCurrentRoles($user);
    }

    /**
     * @return array<string,string>  internal role-name => translated label
     */
    public function availableRoles(): array
    {
        $base = [
            'organisation_admin' => __('Organisatie-admin'),
            'test1' => __('Test rol 1'),
            'test2' => __('Test rol 2'),
        ];

        if (auth()->user()?->isSuperAdmin()) {
            return ['super_admin' => __('Super-admin')] + $base;
        }

        return $base;
    }

    protected function rules(): array
    {
        return (new UpdateUserRequest)->rules();
    }

    public function save(): mixed
    {
        $this->authorize('update', $this->user);

        $validated = $this->validate();

        $this->validate([
            'roles' => ['array'],
            'roles.*' => ['required', 'string', 'in:'.implode(',', array_keys($this->availableRoles()))],
        ]);

        DB::transaction(function () use ($validated) {
            $this->user->update($validated);

            $primaryOrgId = $this->user->organisation_id
                ?: Organisation::orderBy('id')->value('id');

            app(UserRoleSyncer::class)->sync($this->user, $this->roles, (int) $primaryOrgId);
        });

        session()->flash('status', __('Gebruiker opgeslagen.'));

        return redirect()->route('users.index');
    }

    /**
     * @return array<int,string>
     */
    protected function loadCurrentRoles(User $user): array
    {
        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();

        try {
            $primaryOrgId = $user->organisation_id
                ?: Organisation::orderBy('id')->value('id');

            $current = [];

            if ($primaryOrgId !== null) {
                $registrar->setPermissionsTeamId((int) $primaryOrgId);
                $current = $user->getRoleNames()->all();
            }

            if ($user->isSuperAdmin()) {
                $current[] = 'super_admin';
            }

            return array_values(array_unique($current));
        } finally {
            $registrar->setPermissionsTeamId($previousTeamId);
        }
    }

    #[Layout('components.layouts.app')]
    #[Title('Gebruiker bewerken')]
    public function render()
    {
        return view('livewire.users.edit');
    }
}
```

- [ ] **Step 2: Pint on the component**

Run: `cd /Users/frankcornet/skv1 && ./vendor/bin/pint app/Livewire/Users/Edit.php`
Expected: `passed`.

- [ ] **Step 3: Do NOT commit yet** — the view still has no role-picker, so submitting the form right now would always send `roles: []` (the property's default). That would silently un-assign roles. Task 4 lands the view; Task 5 lands the tests; commit happens in Task 5.

---

## Task 4: `Users/Edit` blade view — role checkbox group

**Goal:** Render the role multi-select inside the existing form, between the locale select and the action buttons.

**Files:**
- Modify: `resources/views/livewire/users/edit.blade.php`

- [ ] **Step 1: Insert the role checkbox group**

Find this block in the current view:

```blade
        <flux:select wire:model="locale" label="{{ __('Taal') }}">
            <option value="nl">{{ __('Nederlands') }}</option>
            <option value="en">{{ __('Engels') }}</option>
        </flux:select>

        <div class="flex justify-end gap-2">
```

Insert a `<flux:checkbox.group>` between them, so the section becomes:

```blade
        <flux:select wire:model="locale" label="{{ __('Taal') }}">
            <option value="nl">{{ __('Nederlands') }}</option>
            <option value="en">{{ __('Engels') }}</option>
        </flux:select>

        <flux:checkbox.group wire:model="roles" label="{{ __('Rollen') }}">
            @foreach ($this->availableRoles() as $roleName => $roleLabel)
                <flux:checkbox value="{{ $roleName }}" label="{{ $roleLabel }}" />
            @endforeach
        </flux:checkbox.group>

        <div class="flex justify-end gap-2">
```

- [ ] **Step 2: Do NOT commit yet** — Task 5 commits Tasks 3+4+5 together so the diff lands as one logically complete unit (component + view + tests).

---

## Task 5: New UI tests + commit Tasks 3+4+5 together

**Goal:** Add 6 new tests inside `describe('Users Edit Livewire', ...)` covering visibility, save paths, spoof rejection, and self-edit. Commit Tasks 3+4+5 in one logical commit.

**Files:**
- Modify: `tests/Feature/Users/UserCrudTest.php`

- [ ] **Step 1: Add the 6 new tests inside `describe('Users Edit Livewire', ...)`**

Read `tests/Feature/Users/UserCrudTest.php` and locate the `describe('Users Edit Livewire', ...)` block (currently starts around line 112). Insert these 6 tests at the end of that describe block, before its closing `});`:

```php
    it('shows organisation_admin / test1 / test2 to org-admin editor', function () {
        $target = User::factory()->for($this->org)->create();

        $this->actingAs($this->actor);

        $component = Livewire::test(Edit::class, ['user' => $target]);

        expect($component->instance()->availableRoles())
            ->toBe([
                'organisation_admin' => __('Organisatie-admin'),
                'test1' => __('Test rol 1'),
                'test2' => __('Test rol 2'),
            ]);
    });

    it('shows super_admin additionally to super-admin editor', function () {
        $target = User::factory()->for($this->org)->create();

        $editor = User::factory()->superAdmin()->create([
            'email' => 'edit-super@example.local',
            'organisation_id' => null,
        ]);

        $this->actingAs($editor);

        $component = Livewire::test(Edit::class, ['user' => $target]);

        expect(array_keys($component->instance()->availableRoles()))
            ->toBe(['super_admin', 'organisation_admin', 'test1', 'test2']);
    });

    it('saves regular role assignments selected by an org-admin', function () {
        $target = User::factory()->for($this->org)->create();

        $this->actingAs($this->actor);

        Livewire::test(Edit::class, ['user' => $target])
            ->set('first_name', $target->first_name)
            ->set('last_name', $target->last_name)
            ->set('email', $target->email)
            ->set('start_date', $target->start_date->toDateString())
            ->set('roles', ['test1', 'test2'])
            ->call('save')
            ->assertHasNoErrors();

        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
        expect($target->fresh()->getRoleNames()->sort()->values()->all())
            ->toBe(['test1', 'test2']);
    });

    it('grants super_admin via UI and propagates cross-org', function () {
        $otherOrg = Organisation::factory()->create(['slug' => 'edit-other']);
        $target = User::factory()->for($this->org)->create();

        $editor = User::factory()->superAdmin()->create([
            'email' => 'edit-promoter@example.local',
            'organisation_id' => null,
        ]);

        $this->actingAs($editor);

        Livewire::test(Edit::class, ['user' => $target])
            ->set('first_name', $target->first_name)
            ->set('last_name', $target->last_name)
            ->set('email', $target->email)
            ->set('start_date', $target->start_date->toDateString())
            ->set('roles', ['super_admin'])
            ->call('save')
            ->assertHasNoErrors();

        foreach ([$this->org, $otherOrg] as $org) {
            app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($org->id);
            expect($target->fresh()->hasRole('super_admin'))
                ->toBeTrue("expected super_admin in {$org->slug}");
        }
    });

    it('rejects a spoofed super_admin role from a non-super-admin editor', function () {
        $target = User::factory()->for($this->org)->create();

        $this->actingAs($this->actor);

        Livewire::test(Edit::class, ['user' => $target])
            ->set('first_name', $target->first_name)
            ->set('last_name', $target->last_name)
            ->set('email', $target->email)
            ->set('start_date', $target->start_date->toDateString())
            ->set('roles', ['super_admin'])
            ->call('save')
            ->assertHasErrors(['roles.0']);
    });

    it('allows an org-admin to demote themselves via self-edit', function () {
        $this->actingAs($this->actor);

        Livewire::test(Edit::class, ['user' => $this->actor])
            ->set('first_name', $this->actor->first_name)
            ->set('last_name', $this->actor->last_name)
            ->set('email', $this->actor->email)
            ->set('start_date', $this->actor->start_date->toDateString())
            ->set('roles', [])
            ->call('save')
            ->assertHasNoErrors();

        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
        expect($this->actor->fresh()->getRoleNames()->all())->toBe([]);
    });
```

- [ ] **Step 2: Run the new UI tests + full suite**

Run: `cd /Users/frankcornet/skv1 && ./vendor/bin/pest tests/Feature/Users/UserCrudTest.php`
Expected: PASS — all UserCrud tests including 6 new ones (existing + 6).

Run: `cd /Users/frankcornet/skv1 && ./vendor/bin/pest`
Expected: PASS — 172 baseline + 6 new = 178 tests. No regressions.

- [ ] **Step 3: Pint on touched files**

Run: `cd /Users/frankcornet/skv1 && ./vendor/bin/pint app/Livewire/Users/Edit.php tests/Feature/Users/UserCrudTest.php`
Expected: `passed`.

- [ ] **Step 4: Commit Tasks 3+4+5 as one logical unit**

```bash
cd /Users/frankcornet/skv1
git add app/Livewire/Users/Edit.php resources/views/livewire/users/edit.blade.php tests/Feature/Users/UserCrudTest.php
git commit -m "$(cat <<'EOF'
feat(users): role multi-select on Edit page with inviter-scoped list

Edit-page now exposes a flux:checkbox.group bound to a new roles[]
property. Loads the user's current role state in mount() — including
super_admin via the team-scope-disabled relationship query. Validation
in save() pins each selected role to the editor's allowed list
(availableRoles helper, identical pattern to Send component).

Save is wrapped in DB::transaction so the user-attribute update and
the role-sync are atomic — partial state never persists if the syncer
throws.

Six Pest tests cover org-admin / super-admin visibility, regular role
save, cross-org super_admin propagation via the UI, server-side spoof
rejection, and self-edit demotion (no lock-out guard, per spec).
EOF
)"
```

---

## Task 6: Full-suite verification

**Goal:** Confirm the whole project still passes. Spot any incidental references to `assignRole`/`removeRole` that should now go through the syncer (they shouldn't be any — UserPolicy and direct factory calls are out of scope).

- [ ] **Step 1: Run the full Pest suite**

Run: `cd /Users/frankcornet/skv1 && ./vendor/bin/pest`
Expected: PASS — 178 tests green.

- [ ] **Step 2: Spot-check that there are no regressions in invitation tests**

Run: `cd /Users/frankcornet/skv1 && ./vendor/bin/pest tests/Feature/Invitations/`
Expected: PASS — all 33 invitation tests green (the InvitationService refactor in Task 2 is behavior-neutral; the existing cross-org propagation test pins it).

- [ ] **Step 3: No commit needed if Steps 1-2 are clean.**

If a Pint pass on the whole project is wanted, run `./vendor/bin/pint` and commit any incidental formatting fixes as a separate `style:` commit.

---

## Self-Review (post-write checklist)

**Spec coverage:**
- [x] `UserRoleSyncer` service with `sync(User, array, int)` → Task 1
- [x] Regular roles via `syncRoles` in primary-org scope → Task 1 (test: `assigns regular roles in primary org only`)
- [x] super_admin cross-org binary state → Task 1 (tests: propagation add/remove)
- [x] try/finally setPermissionsTeamId restoration → Task 1 (test: `restores setPermissionsTeamId after the sync completes`)
- [x] InvitationService refactor to use syncer (behavior-neutral) → Task 2
- [x] Edit component: roles property, availableRoles helper, validate, syncer call inside DB::transaction → Task 3
- [x] Edit blade: flux:checkbox.group → Task 4
- [x] UI tests: visibility scoping (org-admin/super-admin), save paths, spoof rejection, self-edit → Task 5
- [x] UserPolicy::update super-admin-edit guard untouched → not modified anywhere
- [x] Self-edit allowed (no lock-out guard) → Task 5 (test: `allows an org-admin to demote themselves via self-edit`)
- [x] DB::transaction for atomicity → Task 3
- [x] Apex-fallback (lowest org id) for users with organisation_id=null → Task 3 (`mount` and `save` both use `$user->organisation_id ?: Organisation::orderBy('id')->value('id')`)

No spec gaps detected.

**Placeholder scan:** No TBD/TODO/"add validation"/"similar to" placeholders. Each step has either runnable code or explicit commands.

**Type consistency:** `availableRoles()`, `loadCurrentRoles()`, `UserRoleSyncer::sync($user, $selectedRoles, $primaryOrganisationId)`, `super_admin`/`organisation_admin`/`test1`/`test2`, `setPermissionsTeamId`/`getPermissionsTeamId` consistent across tasks.

Plan ready for execution.
