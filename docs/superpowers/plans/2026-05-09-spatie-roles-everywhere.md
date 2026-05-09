# Spatie Roles Everywhere — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the `users.is_super_admin` column + `Gate::before` bypass with a per-tenant `super_admin` Spatie role, propagated to all orgs via `OrganisationObserver`. Add `test1` and `test2` permissionless dev-only roles. Add a role multi-select to the invite form. All authorization flows through Spatie.

**Architecture:** Three layers change in dependency order — (1) data + observer (additive: roles + propagation), (2) authorization shift (`isSuperAdmin()` reads from role, drop `Gate::before`, apex-fallback in `ResolveTenant`), (3) UI (role multi-select in invite form + service-level role assignment with cross-org propagation). Final cleanup: drop the legacy `is_super_admin` column.

**Tech Stack:** Laravel 13 · Livewire 4 · Flux UI (incl. flux-pro) · Spatie Permissions (teams enabled) · PostgreSQL · Pest · Pint

**Spec:** `docs/superpowers/specs/2026-05-09-spatie-roles-everywhere-design.md`

---

## File Structure

| File | Action | Responsibility |
|---|---|---|
| `database/seeders/RolesAndPermissionsSeeder.php` | Modify | Seed `super_admin` (all perms), `test1` + `test2` (no perms) templates alongside `organisation_admin` |
| `app/Observers/OrganisationObserver.php` | Modify | Add `created()` hook: materialise per-tenant role copies + propagate super-admin assignments |
| `database/factories/UserFactory.php` | Modify | `superAdmin()` state assigns role via `afterCreating` instead of setting `is_super_admin` flag |
| `database/seeders/DemoUsersSeeder.php` | Modify | Replace `is_super_admin = true` with role-assignment per org for `super@example.local` |
| `app/Http/Middleware/ResolveTenant.php` | Modify | On apex/admin host, set `setPermissionsTeamId` to first-org-id when authed user is super-admin |
| `app/Models/User.php` | Modify | `isSuperAdmin()` becomes thin wrapper around `$this->roles()->where('name', 'super_admin')->exists()`; drop `is_super_admin` from fillable + casts |
| `app/Providers/AppServiceProvider.php` | Modify | Remove `Gate::before` super-admin bypass |
| `app/Services/InvitationService.php` | Modify | Wrap role-assignment in explicit `setPermissionsTeamId($organisationId)` block; propagate `super_admin` cross-org |
| `app/Livewire/Invitations/Send.php` | Modify | Add `availableRoles()` helper with super_admin gating; `roles.*` validation rule |
| `resources/views/livewire/invitations/send.blade.php` | Modify | Add `flux:checkbox.group` for roles |
| `database/migrations/2026_05_09_120000_assign_super_admin_role_to_legacy_flag_holders.php` | Create | Data migration: existing `is_super_admin = true` users get role in every org |
| `database/migrations/2026_05_09_120100_drop_is_super_admin_from_users_table.php` | Create | Schema migration: drop column + index |
| `tests/Feature/Roles/RolesSeederTest.php` | Create | Asserts seeded roles exist with expected permissions |
| `tests/Feature/Tenancy/OrganisationObserverTest.php` | Create | Asserts `created()` propagation behaviour |
| `tests/Feature/Auth/SuperAdminAuthorizationTest.php` | Create | Asserts isSuperAdmin via role + apex fallback + Gate::before removal |
| `tests/Feature/Invitations/InviteUiTest.php` | Modify | Add role-picker tests (visibility scoping + spoof prevention + propagation) |
| `tests/Feature/Invitations/InvitationServiceTest.php` | Modify | Add cross-org super_admin propagation test |

No new permissions added. No changes to existing role assignments outside of super-admin user(s).

---

## Task 1: Add `super_admin`, `test1`, `test2` role templates

**Goal:** Seed three new role templates (`team_id = null`) so per-tenant copies have a single source of truth.

**Files:**
- Modify: `database/seeders/RolesAndPermissionsSeeder.php`
- Create: `tests/Feature/Roles/RolesSeederTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Roles/RolesSeederTest.php`:

```php
<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

describe('RolesAndPermissionsSeeder', function () {
    it('creates the super_admin template role with every permission', function () {
        $role = Role::where('name', 'super_admin')->whereNull('team_id')->first();

        expect($role)->not->toBeNull();
        expect($role->permissions->pluck('name')->sort()->values()->all())
            ->toBe(Permission::pluck('name')->sort()->values()->all());
    });

    it('creates the test1 template role with no permissions', function () {
        $role = Role::where('name', 'test1')->whereNull('team_id')->first();

        expect($role)->not->toBeNull()
            ->and($role->permissions)->toBeEmpty();
    });

    it('creates the test2 template role with no permissions', function () {
        $role = Role::where('name', 'test2')->whereNull('team_id')->first();

        expect($role)->not->toBeNull()
            ->and($role->permissions)->toBeEmpty();
    });

    it('keeps the organisation_admin template intact', function () {
        $role = Role::where('name', 'organisation_admin')->whereNull('team_id')->first();

        expect($role)->not->toBeNull()
            ->and($role->permissions->pluck('name'))->toContain('invitations.send', 'users.view', 'roles.manage');
    });
});
```

- [ ] **Step 2: Run the new tests to verify they fail**

Run: `cd /Users/frankcornet/skv1 && ./vendor/bin/pest tests/Feature/Roles/RolesSeederTest.php`
Expected: FAIL — `super_admin`, `test1`, `test2` roles do not exist yet.

- [ ] **Step 3: Update `RolesAndPermissionsSeeder`**

Replace the body of `database/seeders/RolesAndPermissionsSeeder.php::run()` with:

```php
public function run(): void
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $permissions = [
        'users.view',
        'users.create',
        'users.update',
        'users.delete',
        'users.impersonate',
        'roles.view',
        'roles.manage',
        'organisations.view',
        'organisations.manage',
        'invitations.send',
        'invitations.cancel',
        'system.health',
        'activity.view',
        'backup.manage',
    ];

    DB::transaction(function () use ($permissions) {
        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        // Template roles. Each tenant gets per-team copies via OrganisationObserver.
        $orgAdmin = Role::firstOrCreate(
            ['name' => 'organisation_admin', 'guard_name' => 'web', 'team_id' => null]
        );
        $orgAdmin->syncPermissions([
            'users.view', 'users.create', 'users.update', 'users.delete',
            'users.impersonate',
            'roles.view', 'roles.manage',
            'organisations.view',
            'invitations.send', 'invitations.cancel',
            'activity.view',
        ]);

        $superAdmin = Role::firstOrCreate(
            ['name' => 'super_admin', 'guard_name' => 'web', 'team_id' => null]
        );
        $superAdmin->syncPermissions(Permission::all());

        Role::firstOrCreate(
            ['name' => 'test1', 'guard_name' => 'web', 'team_id' => null]
        );

        Role::firstOrCreate(
            ['name' => 'test2', 'guard_name' => 'web', 'team_id' => null]
        );
    });
}
```

- [ ] **Step 4: Run the new tests + the full Pest suite**

Run: `cd /Users/frankcornet/skv1 && ./vendor/bin/pest tests/Feature/Roles/RolesSeederTest.php`
Expected: PASS — 4 tests green.

Run: `cd /Users/frankcornet/skv1 && ./vendor/bin/pest`
Expected: PASS — no regressions (149 baseline + 4 new = 153 tests).

- [ ] **Step 5: Pint + commit**

```bash
cd /Users/frankcornet/skv1
./vendor/bin/pint database/seeders/RolesAndPermissionsSeeder.php tests/Feature/Roles/RolesSeederTest.php
git add database/seeders/RolesAndPermissionsSeeder.php tests/Feature/Roles/RolesSeederTest.php
git commit -m "$(cat <<'EOF'
feat(roles): add super_admin, test1, test2 role templates

Seeds three new template roles alongside organisation_admin:
- super_admin: every permission (will be assigned per-tenant by
  OrganisationObserver in the next task)
- test1, test2: empty permission sets, intended for development
  to exercise the role-picker UI

These are templates only (team_id = null); per-tenant copies are
materialised when an Organisation is created.
EOF
)"
```

---

## Task 2: `OrganisationObserver::created` — per-tenant role propagation

**Goal:** When an Organisation is created, materialise per-tenant copies of all four role templates and assign `super_admin` to all existing super-admins for the new tenant.

**Files:**
- Modify: `app/Observers/OrganisationObserver.php`
- Create: `tests/Feature/Tenancy/OrganisationObserverTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Tenancy/OrganisationObserverTest.php`:

```php
<?php

use App\Models\Organisation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

describe('OrganisationObserver::created', function () {
    it('creates per-tenant copies of every role template when an organisation is created', function () {
        $org = Organisation::factory()->create(['slug' => 'fresh-tenant']);

        foreach (['organisation_admin', 'super_admin', 'test1', 'test2'] as $name) {
            $role = Role::where('name', $name)->where('team_id', $org->id)->first();
            expect($role)->not->toBeNull("expected per-tenant copy of {$name} for org {$org->id}");
        }
    });

    it('copies template permissions onto the per-tenant super_admin role', function () {
        $org = Organisation::factory()->create(['slug' => 'perm-check']);

        $tenantSuperAdmin = Role::where('name', 'super_admin')
            ->where('team_id', $org->id)
            ->first();

        expect($tenantSuperAdmin->permissions)->not->toBeEmpty()
            ->and($tenantSuperAdmin->permissions->pluck('name'))->toContain('users.delete', 'roles.manage');
    });

    it('keeps test1 and test2 permissionless on per-tenant copies', function () {
        $org = Organisation::factory()->create(['slug' => 'no-perms']);

        $test1 = Role::where('name', 'test1')->where('team_id', $org->id)->first();
        $test2 = Role::where('name', 'test2')->where('team_id', $org->id)->first();

        expect($test1->permissions)->toBeEmpty()
            ->and($test2->permissions)->toBeEmpty();
    });

    it('propagates super_admin role assignment to existing super-admins when a new org is created', function () {
        $org1 = Organisation::factory()->create(['slug' => 'org-one']);

        $superAdmin = User::factory()->create(['organisation_id' => null]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($org1->id);
        $superAdmin->assignRole('super_admin');

        $org2 = Organisation::factory()->create(['slug' => 'org-two']);

        app(PermissionRegistrar::class)->setPermissionsTeamId($org2->id);
        expect($superAdmin->fresh()->hasRole('super_admin'))->toBeTrue();
    });
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd /Users/frankcornet/skv1 && ./vendor/bin/pest tests/Feature/Tenancy/OrganisationObserverTest.php`
Expected: FAIL — observer's `created()` method does not yet exist.

- [ ] **Step 3: Update `OrganisationObserver`**

Replace `app/Observers/OrganisationObserver.php` with this content:

```php
<?php

namespace App\Observers;

use App\Contracts\TenantOwned;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class OrganisationObserver
{
    /**
     * Models known to implement TenantOwned. Add new TenantOwned models here
     * so the cascade soft-delete reaches them. The observer guards each one
     * with a class_implements() check, so an accidental wrong entry is ignored.
     */
    private const TENANT_OWNED_MODELS = [
        User::class,
    ];

    /**
     * Roles that are materialised per-tenant. Templates live at team_id=null
     * (seeded by RolesAndPermissionsSeeder); per-tenant copies are created
     * here when a new organisation comes into existence.
     */
    private const PROPAGATED_ROLES = [
        'organisation_admin',
        'super_admin',
        'test1',
        'test2',
    ];

    public function created(Organisation $organisation): void
    {
        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();

        DB::transaction(function () use ($organisation, $registrar) {
            $registrar->setPermissionsTeamId($organisation->id);

            foreach (self::PROPAGATED_ROLES as $name) {
                $template = Role::where('name', $name)->whereNull('team_id')->first();
                if (! $template) {
                    continue;
                }

                $tenantRole = Role::firstOrCreate([
                    'name' => $name,
                    'guard_name' => 'web',
                    'team_id' => $organisation->id,
                ]);

                $tenantRole->syncPermissions($template->permissions);
            }

            // Propagate super_admin assignment to existing super-admins.
            // Direct relationship query (no team scope) finds users who have
            // the role in any org — those are the ones who should have it
            // in this new org too.
            User::query()
                ->whereHas('roles', fn ($q) => $q->where('name', 'super_admin'))
                ->each(function (User $user) {
                    if (! $user->hasRole('super_admin')) {
                        $user->assignRole('super_admin');
                    }
                });
        });

        $registrar->setPermissionsTeamId($previousTeamId);
    }

    public function deleted(Organisation $organisation): void
    {
        if ($organisation->isForceDeleting()) {
            return;
        }

        $timestamp = $organisation->deleted_at;

        DB::transaction(function () use ($organisation, $timestamp) {
            foreach (self::TENANT_OWNED_MODELS as $modelClass) {
                if (! in_array(TenantOwned::class, class_implements($modelClass), true)) {
                    continue;
                }

                $modelClass::query()
                    ->withoutGlobalScopes()
                    ->where('organisation_id', $organisation->id)
                    ->whereNull('deleted_at')
                    ->update([
                        'deleted_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]);
            }
        });
    }

    public function restoring(Organisation $organisation): void
    {
        $timestamp = $organisation->deleted_at;

        DB::transaction(function () use ($organisation, $timestamp) {
            foreach (self::TENANT_OWNED_MODELS as $modelClass) {
                if (! in_array(TenantOwned::class, class_implements($modelClass), true)) {
                    continue;
                }

                $modelClass::query()
                    ->withoutGlobalScopes()
                    ->where('organisation_id', $organisation->id)
                    ->where('deleted_at', $timestamp)
                    ->update([
                        'deleted_at' => null,
                    ]);
            }
        });
    }
}
```

- [ ] **Step 4: Run the new tests + full suite**

Run: `cd /Users/frankcornet/skv1 && ./vendor/bin/pest tests/Feature/Tenancy/OrganisationObserverTest.php`
Expected: PASS — 4 tests green.

Run: `cd /Users/frankcornet/skv1 && ./vendor/bin/pest`
Expected: PASS — 153 baseline + 4 new = 157 tests. No regressions.

- [ ] **Step 5: Pint + commit**

```bash
cd /Users/frankcornet/skv1
./vendor/bin/pint app/Observers/OrganisationObserver.php tests/Feature/Tenancy/OrganisationObserverTest.php
git add app/Observers/OrganisationObserver.php tests/Feature/Tenancy/OrganisationObserverTest.php
git commit -m "$(cat <<'EOF'
feat(tenancy): observer materialises per-tenant role copies on org creation

OrganisationObserver::created() now copies each role template
(organisation_admin, super_admin, test1, test2) onto the new tenant
with team_id = organisation.id, and propagates the super_admin
assignment to all existing super-admin users for the new tenant.

Wraps the work in setPermissionsTeamId scope-bracket with try/finally
behaviour to avoid leaking team context if the transaction throws.
EOF
)"
```

---

## Task 3: UserFactory + DemoUsersSeeder — assign super_admin role

**Goal:** `User::factory()->superAdmin()` assigns the role via `afterCreating` instead of setting the legacy column. `DemoUsersSeeder` does the same for the seeded super@example.local. The legacy column is *also* still set (for backward compat until Task 8 drops it).

**Files:**
- Modify: `database/factories/UserFactory.php`
- Modify: `database/seeders/DemoUsersSeeder.php`

- [ ] **Step 1: Update `UserFactory::superAdmin()`**

Replace the `superAdmin()` method in `database/factories/UserFactory.php` with:

```php
public function superAdmin(): static
{
    return $this->state(fn (array $attributes) => [
        'is_super_admin' => true,
    ])->afterCreating(function (\App\Models\User $user) {
        $registrar = app(\Spatie\Permission\PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();

        try {
            foreach (\App\Models\Organisation::all() as $org) {
                $registrar->setPermissionsTeamId($org->id);

                if (! $user->hasRole('super_admin')) {
                    $user->assignRole('super_admin');
                }
            }
        } finally {
            $registrar->setPermissionsTeamId($previousTeamId);
        }
    });
}
```

The `is_super_admin` flag stays for now — Task 8 drops it.

- [ ] **Step 2: Update `DemoUsersSeeder`**

In `database/seeders/DemoUsersSeeder.php`, replace the super-admin block (currently lines ~46-55) with:

```php
$superAdmin = User::factory()->superAdmin()->create([
    'first_name' => 'Super',
    'last_name' => 'Admin',
    'email' => 'super@example.local',
    'organisation_id' => null,
    'password' => Hash::make('Password123!'),
    'status' => 'active',
    'activated_at' => now(),
    'start_date' => now()->toDateString(),
]);
```

The `superAdmin()` factory state's `afterCreating` now handles role assignment across all existing demo orgs (which were created moments earlier in the seeder). No further loop needed in this seeder.

- [ ] **Step 3: Run the full suite**

Run: `cd /Users/frankcornet/skv1 && ./vendor/bin/pest`
Expected: PASS — all tests green. Existing tests that use `User::factory()->superAdmin()` continue working because the flag stays set AND the role gets assigned (where orgs exist in the test setup).

- [ ] **Step 4: Migrate-fresh + manual sanity check**

Run: `cd /Users/frankcornet/skv1 && php artisan migrate:fresh --seed --force`
Expected: seeders complete without error.

Run:
```bash
php artisan tinker --execute="
\$super = App\Models\User::where('email','super@example.local')->first();
foreach (App\Models\Organisation::all() as \$org) {
    app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId(\$org->id);
    echo \$org->slug . ': hasRole(super_admin) = ' . (\$super->hasRole('super_admin') ? 'YES' : 'NO') . PHP_EOL;
}
"
```
Expected:
```
demo1: hasRole(super_admin) = YES
demo2: hasRole(super_admin) = YES
```

- [ ] **Step 5: Pint + commit**

```bash
cd /Users/frankcornet/skv1
./vendor/bin/pint database/factories/UserFactory.php database/seeders/DemoUsersSeeder.php
git add database/factories/UserFactory.php database/seeders/DemoUsersSeeder.php
git commit -m "$(cat <<'EOF'
feat(roles): factory and seeder assign super_admin role per tenant

UserFactory::superAdmin() afterCreating loops through all existing
organisations and assigns the super_admin role under each team_id.
DemoUsersSeeder uses the new factory state, so super@example.local
now has the role in every demo org in addition to the legacy flag.

Legacy is_super_admin flag is still set for backward compatibility
during the rollout; it gets dropped in a later task once the
authorization layer has been switched.
EOF
)"
```

---

## Task 4: `ResolveTenant` apex-fallback for super-admins

**Goal:** When the request hits the apex (or admin) host AND the authenticated user is a super-admin, set `setPermissionsTeamId` to the lowest-id organisation. Without this, super-admins on apex have empty role-context post Task 5.

**Files:**
- Modify: `app/Http/Middleware/ResolveTenant.php`
- Create: `tests/Feature/Auth/SuperAdminAuthorizationTest.php` (will hold further super-admin auth tests in Task 5)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Auth/SuperAdminAuthorizationTest.php`:

```php
<?php

use App\Http\Middleware\ResolveTenant;
use App\Models\Organisation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    config(['app.apex_domain' => 'skv1.test', 'app.url' => 'https://skv1.test']);
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->orgLow = Organisation::factory()->create(['slug' => 'aaa-first']);
    $this->orgHigh = Organisation::factory()->create(['slug' => 'zzz-last']);

    $this->superAdmin = User::factory()->create(['organisation_id' => null]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->orgLow->id);
    $this->superAdmin->assignRole('super_admin');
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->orgHigh->id);
    $this->superAdmin->assignRole('super_admin');
});

describe('ResolveTenant apex super-admin fallback', function () {
    it('sets setPermissionsTeamId to the lowest-id organisation for super-admins on apex', function () {
        // Reset team-id so we observe what the middleware sets.
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        $this->actingAs($this->superAdmin);

        $request = Request::create('https://skv1.test/dashboard', 'GET');

        app(ResolveTenant::class)->handle($request, fn () => null);

        $resolved = app(PermissionRegistrar::class)->getPermissionsTeamId();
        expect($resolved)->toBe($this->orgLow->id);
    });

    it('does not set a team id for non-super-admin users on apex', function () {
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $regular = User::factory()->for($this->orgLow)->create();
        $this->actingAs($regular);

        $request = Request::create('https://skv1.test/dashboard', 'GET');
        app(ResolveTenant::class)->handle($request, fn () => null);

        expect(app(PermissionRegistrar::class)->getPermissionsTeamId())->toBeNull();
    });
});
```

- [ ] **Step 2: Run the new tests to verify they fail**

Run: `cd /Users/frankcornet/skv1 && ./vendor/bin/pest tests/Feature/Auth/SuperAdminAuthorizationTest.php`
Expected: FAIL — middleware does not yet set team-id on apex.

- [ ] **Step 3: Update `ResolveTenant`**

Replace `app/Http/Middleware/ResolveTenant.php` with:

```php
<?php

namespace App\Http\Middleware;

use App\Models\Organisation;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();
        $apex = config('app.apex_domain');
        $admin = config('app.admin_host');

        if ($host === $apex || $host === $admin) {
            $this->scopeForApexUser();

            return $next($request);
        }

        $suffix = '.'.$apex;
        if (! str_ends_with($host, $suffix)) {
            abort(404);
        }

        $slug = substr($host, 0, -strlen($suffix));
        $organisation = Organisation::where('slug', $slug)->first();

        if (! $organisation) {
            abort(404);
        }

        app()->instance('currentOrganisation', $organisation);
        app(PermissionRegistrar::class)->setPermissionsTeamId($organisation->id);

        return $next($request);
    }

    /**
     * On the apex host there is no tenant context. For super-admins we anchor
     * permission checks to the lowest-id organisation so Spatie's team-scoped
     * role lookups can find their `super_admin` assignment. Super-admins have
     * the role in every org, so the choice is reproducible and arbitrary.
     */
    protected function scopeForApexUser(): void
    {
        $user = auth()->user();
        if (! $user) {
            return;
        }

        // Direct relationship query — bypasses team-scoping so we can see the
        // role assignment under any team_id.
        $isSuperAdmin = $user->roles()->where('name', 'super_admin')->exists();
        if (! $isSuperAdmin) {
            return;
        }

        $firstOrg = Organisation::orderBy('id')->first();
        if ($firstOrg) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($firstOrg->id);
        }
    }
}
```

- [ ] **Step 4: Run the tests + full suite**

Run: `cd /Users/frankcornet/skv1 && ./vendor/bin/pest tests/Feature/Auth/SuperAdminAuthorizationTest.php`
Expected: PASS — 2 tests green.

Run: `cd /Users/frankcornet/skv1 && ./vendor/bin/pest`
Expected: PASS — 157 baseline + 2 new = 159 tests. No regressions.

- [ ] **Step 5: Pint + commit**

```bash
cd /Users/frankcornet/skv1
./vendor/bin/pint app/Http/Middleware/ResolveTenant.php tests/Feature/Auth/SuperAdminAuthorizationTest.php
git add app/Http/Middleware/ResolveTenant.php tests/Feature/Auth/SuperAdminAuthorizationTest.php
git commit -m "$(cat <<'EOF'
feat(auth): apex-fallback team_id for super-admins in ResolveTenant

On the apex/admin host, ResolveTenant now sets setPermissionsTeamId to
the lowest-id organisation for authenticated super-admins. Without
this, Spatie's team-scoped role-lookups would return false on apex
even though the super-admin has the role in every organisation, since
no team is bound there by default.

Non-super-admin users on apex remain unscoped (their role checks
fail, which is the desired behaviour — they should not have
permissions on the apex domain).
EOF
)"
```

---

## Task 5: Switch `User::isSuperAdmin()` to role-based + remove `Gate::before`

**Goal:** `isSuperAdmin()` now reads from the role rather than the legacy column. `Gate::before` super-admin bypass is removed; super-admins gain their permissions through the role's permission set in the per-tenant context that ResolveTenant now sets up.

**Files:**
- Modify: `app/Models/User.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `tests/Feature/Auth/SuperAdminAuthorizationTest.php` (extend with role-based isSuperAdmin tests)

- [ ] **Step 1: Add new failing tests for role-based isSuperAdmin behaviour**

Append to `tests/Feature/Auth/SuperAdminAuthorizationTest.php`, immediately before the final closing `});`:

```php
describe('User::isSuperAdmin role-based check', function () {
    it('returns true when the user has the super_admin role in any org', function () {
        $org = Organisation::factory()->create(['slug' => 'role-check']);
        $user = User::factory()->for($org)->create();

        app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
        $user->assignRole('super_admin');

        expect($user->fresh()->isSuperAdmin())->toBeTrue();
    });

    it('returns false when the user has no super_admin role assignment', function () {
        $org = Organisation::factory()->create(['slug' => 'no-role']);
        $user = User::factory()->for($org)->create();

        expect($user->isSuperAdmin())->toBeFalse();
    });

    it('grants super-admin access via Spatie permissions only — no Gate::before bypass', function () {
        $org = Organisation::factory()->create(['slug' => 'gate-check']);
        // Create a user who has the LEGACY flag but NO role.
        $user = User::factory()->for($org)->create(['is_super_admin' => true]);

        // Permission check must fail because Gate::before no longer bypasses.
        // The user must hold the actual super_admin role to gain permissions.
        app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
        expect($user->can('users.delete'))->toBeFalse();
    });
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd /Users/frankcornet/skv1 && ./vendor/bin/pest tests/Feature/Auth/SuperAdminAuthorizationTest.php --filter="isSuperAdmin role-based"`
Expected: FAIL — tests 2 and 3 fail (test 1 may pass coincidentally since the legacy flag check returns true; the real assertion is whether role-based check is in effect).

- [ ] **Step 3: Update `User::isSuperAdmin()`**

In `app/Models/User.php`, replace the `isSuperAdmin()` method (currently around line 84):

```php
public function isSuperAdmin(): bool
{
    // Direct relationship query bypasses team-scoping; returns true if the
    // user has super_admin role in any organisation. Super-admins are
    // assigned the role in every org via OrganisationObserver, so this is
    // a single-query "are you a super-admin anywhere" check.
    return $this->roles()->where('name', 'super_admin')->exists();
}
```

Leave the `is_super_admin` field in `$fillable` and `$casts` for now (Task 8 drops them).

- [ ] **Step 4: Remove `Gate::before` super-admin bypass**

In `app/Providers/AppServiceProvider.php`, find the `boot()` method and remove the `Gate::before` block:

```php
// REMOVE these lines (and the Gate import if it's not used elsewhere in the file):
Gate::before(function (User $user) {
    return $user->isSuperAdmin() ? true : null;
});
```

If `Gate` and `User` imports become unused after removal, also remove their `use` statements.

- [ ] **Step 5: Run the new tests**

Run: `cd /Users/frankcornet/skv1 && ./vendor/bin/pest tests/Feature/Auth/SuperAdminAuthorizationTest.php`
Expected: PASS — 5 tests total in the file (2 from Task 4 + 3 new).

- [ ] **Step 6: Run the full suite — there will be regressions to investigate**

Run: `cd /Users/frankcornet/skv1 && ./vendor/bin/pest`
Expected: most tests pass. Some tests that relied on `Gate::before` for super-admin permissions in apex-only contexts (or weird test setups without orgs) may fail. **Read each failure carefully** — the most likely cause is a test creating a super-admin BEFORE creating any organisations, so `afterCreating` had no orgs to assign the role in.

For each failure: either (a) reorder the test to create orgs first, or (b) assign the role manually after factory creation. Do NOT bypass by re-adding Gate::before. If the failure is unrelated to super-admin-on-apex behaviour, escalate as a concern.

- [ ] **Step 7: Pint + commit**

```bash
cd /Users/frankcornet/skv1
./vendor/bin/pint app/Models/User.php app/Providers/AppServiceProvider.php tests/Feature/Auth/SuperAdminAuthorizationTest.php
git add app/Models/User.php app/Providers/AppServiceProvider.php tests/Feature/Auth/SuperAdminAuthorizationTest.php
git commit -m "$(cat <<'EOF'
refactor(auth): isSuperAdmin reads from role, drop Gate::before bypass

User::isSuperAdmin() now queries the spatie role relationship directly
(bypassing team-scope so it sees the role in any org). The Gate::before
super-admin bypass is removed; permissions are granted via the role's
permission set in the per-tenant team context.

Apex super-admin permission checks rely on the apex-fallback team_id
that ResolveTenant set up in the previous task. Per-tenant requests
work because OrganisationObserver materialises super_admin role copies
for each org and the role holds every permission.
EOF
)"
```

---

## Task 6: `InvitationService` — accept roles + propagate `super_admin` cross-org

**Goal:** The service explicitly sets `setPermissionsTeamId($organisationId)` before assigning roles (apex-fallback may have set it elsewhere), assigns the requested roles, and propagates `super_admin` to every other org.

**Files:**
- Modify: `app/Services/InvitationService.php`
- Modify: `tests/Feature/Invitations/InvitationServiceTest.php`

- [ ] **Step 1: Append a failing test**

Append at the bottom of `tests/Feature/Invitations/InvitationServiceTest.php`:

```php
it('propagates super_admin role across all organisations on invite', function () {
    Mail::fake();

    $otherOrg = Organisation::factory()->create(['slug' => 'demo-other']);
    $thirdOrg = Organisation::factory()->create(['slug' => 'demo-third']);

    $invitation = app(InvitationService::class)->invite(
        firstName: 'Cross',
        middleName: null,
        lastName: 'Admin',
        email: 'cross-admin@demo1.local',
        locale: 'nl',
        roles: ['super_admin'],
        invitedBy: $this->actor,
        organisationId: $this->org->id,
    );

    $invitee = $invitation->user;

    foreach ([$this->org, $otherOrg, $thirdOrg] as $org) {
        app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
        expect($invitee->fresh()->hasRole('super_admin'))
            ->toBeTrue("expected super_admin role in org {$org->slug}");
    }
});

it('only assigns non-super_admin roles in the invitee organisation', function () {
    Mail::fake();

    $otherOrg = Organisation::factory()->create(['slug' => 'demo-elsewhere']);

    $invitation = app(InvitationService::class)->invite(
        firstName: 'Org',
        middleName: null,
        lastName: 'Member',
        email: 'org-member@demo1.local',
        locale: 'nl',
        roles: ['organisation_admin'],
        invitedBy: $this->actor,
        organisationId: $this->org->id,
    );

    $invitee = $invitation->user;

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
    expect($invitee->fresh()->hasRole('organisation_admin'))->toBeTrue();

    app(PermissionRegistrar::class)->setPermissionsTeamId($otherOrg->id);
    expect($invitee->fresh()->hasRole('organisation_admin'))->toBeFalse();
});
```

- [ ] **Step 2: Run new tests to verify they fail**

Run: `cd /Users/frankcornet/skv1 && ./vendor/bin/pest tests/Feature/Invitations/InvitationServiceTest.php --filter="super_admin role across all"`
Expected: FAIL — current InvitationService loops `$user->assignRole($roleName)` against the *current* setPermissionsTeamId, which (depending on test setup) may not be the invitee's org. Cross-org propagation also doesn't exist.

- [ ] **Step 3: Update `InvitationService::invite()`**

In `app/Services/InvitationService.php`, replace the `foreach ($roles as $roleName)` block (currently lines ~30-32 inside the transaction) with:

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

This replaces the existing simple assign-loop. Leave the surrounding code (Invitation::create, Mail::queue, activity log, return) unchanged.

- [ ] **Step 4: Run tests + full suite**

Run: `cd /Users/frankcornet/skv1 && ./vendor/bin/pest tests/Feature/Invitations/InvitationServiceTest.php`
Expected: PASS — all service tests including the 2 new ones.

Run: `cd /Users/frankcornet/skv1 && ./vendor/bin/pest`
Expected: PASS — no regressions.

- [ ] **Step 5: Pint + commit**

```bash
cd /Users/frankcornet/skv1
./vendor/bin/pint app/Services/InvitationService.php tests/Feature/Invitations/InvitationServiceTest.php
git add app/Services/InvitationService.php tests/Feature/Invitations/InvitationServiceTest.php
git commit -m "$(cat <<'EOF'
feat(invitations): assign roles in invitee org and propagate super_admin

InvitationService::invite() now explicitly sets setPermissionsTeamId
to the invitee's organisation_id before role assignment, and
propagates super_admin to every other organisation when present in
the requested role list. Wraps the work in try/finally to restore
the inviter's previous team context after the transaction.

Two new tests cover the cross-org super_admin propagation and the
opposite — that organisation_admin stays scoped to the invitee org.
EOF
)"
```

---

## Task 7: Send component + view — role multi-select picker

**Goal:** Inviter sees a checkbox group of available roles, scoped by their authority. Org-admins see `organisation_admin`, `test1`, `test2`. Super-admins additionally see `super_admin`. Server-side validation prevents spoofing.

**Files:**
- Modify: `app/Livewire/Invitations/Send.php`
- Modify: `resources/views/livewire/invitations/send.blade.php`
- Modify: `tests/Feature/Invitations/InviteUiTest.php`

- [ ] **Step 1: Write 3 failing tests in `InviteUiTest.php`**

Inside the existing `describe('Send invitation Livewire component', ...)` block, after the last existing test (`hides organisation dropdown from non-super-admin on apex`), append:

```php
it('shows organisation_admin / test1 / test2 to org-admin inviters', function () {
    $this->actingAs($this->actor);

    $component = Livewire::test(Send::class);

    expect($component->instance()->availableRoles())
        ->toBe([
            'organisation_admin' => __('Organisatie-admin'),
            'test1' => __('Test rol 1'),
            'test2' => __('Test rol 2'),
        ]);
});

it('shows super_admin additionally to super-admin inviters on apex', function () {
    app()->forgetInstance('currentOrganisation');

    $superAdmin = User::factory()->superAdmin()->create([
        'email' => 'super-picker@example.local',
        'organisation_id' => null,
    ]);

    $this->actingAs($superAdmin);

    $component = Livewire::test(Send::class);

    expect(array_keys($component->instance()->availableRoles()))
        ->toBe(['super_admin', 'organisation_admin', 'test1', 'test2']);
});

it('rejects a spoofed super_admin role from a non-super-admin inviter', function () {
    $this->actingAs($this->actor);

    Livewire::test(Send::class)
        ->set('firstName', 'Spoof')
        ->set('lastName', 'Role')
        ->set('email', 'spoof-role@demo1.local')
        ->set('roles', ['super_admin'])
        ->call('send')
        ->assertHasErrors(['roles.0']);
});
```

- [ ] **Step 2: Run the new tests to verify they fail**

Run: `cd /Users/frankcornet/skv1 && ./vendor/bin/pest tests/Feature/Invitations/InviteUiTest.php --filter="role"`
Expected: FAIL — `availableRoles()` does not yet exist; `roles.*` validation does not yet reject super_admin from org-admins.

- [ ] **Step 3: Update `Send` component**

In `app/Livewire/Invitations/Send.php`:

(a) Remove the existing `#[Validate('array')] public array $roles = [];` line and its attribute. Replace it with the same property but without a class-level Validate attribute (validation is now applied inline because rules depend on the inviter):

```php
public array $roles = [];
```

(b) Add a new helper method `availableRoles()` immediately after the existing `availableOrganisations()` helper:

```php
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
```

(c) Inside `send()`, after `$this->validate();` (the first validate call) and BEFORE the org-id resolution `if`-block, add:

```php
$this->validate([
    'roles' => ['array'],
    'roles.*' => ['required', 'string', 'in:'.implode(',', array_keys($this->availableRoles()))],
]);
```

This replaces what the class-level `#[Validate('array')]` did and adds the per-element constraint scoped to the inviter's allowed role set.

- [ ] **Step 4: Update `send.blade.php`**

In `resources/views/livewire/invitations/send.blade.php`, insert this block immediately after the `<flux:select wire:model="locale" ...>` block and before the `@if (count($this->availableOrganisations()) > 0)` block:

```blade
<flux:checkbox.group wire:model="roles" label="{{ __('Rollen') }}">
    @foreach ($this->availableRoles() as $roleName => $roleLabel)
        <flux:checkbox value="{{ $roleName }}" label="{{ $roleLabel }}" />
    @endforeach
</flux:checkbox.group>
```

- [ ] **Step 5: Run all UI tests + full suite**

Run: `cd /Users/frankcornet/skv1 && ./vendor/bin/pest tests/Feature/Invitations/InviteUiTest.php`
Expected: PASS — all UI tests including 3 new ones.

Run: `cd /Users/frankcornet/skv1 && ./vendor/bin/pest`
Expected: PASS — no regressions.

- [ ] **Step 6: Pint + commit**

```bash
cd /Users/frankcornet/skv1
./vendor/bin/pint app/Livewire/Invitations/Send.php tests/Feature/Invitations/InviteUiTest.php
git add app/Livewire/Invitations/Send.php resources/views/livewire/invitations/send.blade.php tests/Feature/Invitations/InviteUiTest.php
git commit -m "$(cat <<'EOF'
feat(invitations): role multi-select with inviter-scoped role list

Send Livewire component now exposes availableRoles() that returns the
roles the current inviter is allowed to assign: organisation_admin,
test1, and test2 for any inviter; super_admin additionally for
super-admins. The view renders a flux:checkbox.group bound to roles[].

Server-side validation pins each selected role to that scoped list,
so a spoofed super_admin payload from a non-super-admin is rejected
even if the front-end is bypassed.
EOF
)"
```

---

## Task 8: Drop `is_super_admin` column

**Goal:** Final cleanup. Data migration backfills the role for any user that still has the legacy flag (defensive — should be a no-op after Tasks 1-3, but covers manual DB edits). Schema migration drops the column + index. Factory and seeder remove flag references.

**Files:**
- Create: `database/migrations/2026_05_09_120000_assign_super_admin_role_to_legacy_flag_holders.php`
- Create: `database/migrations/2026_05_09_120100_drop_is_super_admin_from_users_table.php`
- Modify: `database/factories/UserFactory.php`
- Modify: `app/Models/User.php`

- [ ] **Step 1: Create the data migration**

Create `database/migrations/2026_05_09_120000_assign_super_admin_role_to_legacy_flag_holders.php`:

```php
<?php

use App\Models\Organisation;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();

        try {
            $userIds = DB::table('users')
                ->where('is_super_admin', true)
                ->pluck('id');

            foreach ($userIds as $userId) {
                $user = User::find($userId);
                if (! $user) {
                    continue;
                }

                foreach (Organisation::all() as $org) {
                    $registrar->setPermissionsTeamId($org->id);
                    if (! $user->hasRole('super_admin')) {
                        $user->assignRole('super_admin');
                    }
                }
            }
        } finally {
            $registrar->setPermissionsTeamId($previousTeamId);
        }
    }

    public function down(): void
    {
        // Intentionally a no-op. Reverting the assignment is risky because
        // we cannot tell which super_admin grants pre-dated this migration
        // versus which were added later.
    }
};
```

- [ ] **Step 2: Create the schema migration**

Create `database/migrations/2026_05_09_120100_drop_is_super_admin_from_users_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_super_admin']);
            $table->dropColumn('is_super_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_super_admin')->default(false)->after('organisation_id');
            $table->index('is_super_admin');
        });
    }
};
```

- [ ] **Step 3: Update `UserFactory::superAdmin()`**

In `database/factories/UserFactory.php`, remove the legacy state from the `superAdmin()` method. Replace the method body's `state(...)` argument with an empty-state callback (or drop the `state()` chain entirely):

```php
public function superAdmin(): static
{
    return $this->afterCreating(function (\App\Models\User $user) {
        $registrar = app(\Spatie\Permission\PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();

        try {
            foreach (\App\Models\Organisation::all() as $org) {
                $registrar->setPermissionsTeamId($org->id);

                if (! $user->hasRole('super_admin')) {
                    $user->assignRole('super_admin');
                }
            }
        } finally {
            $registrar->setPermissionsTeamId($previousTeamId);
        }
    });
}
```

- [ ] **Step 4: Drop `is_super_admin` from `User::$fillable` and `$casts`**

In `app/Models/User.php`:

(a) Remove `'is_super_admin'` from the `$fillable` array (currently line 24).
(b) Remove the `'is_super_admin' => 'boolean'` line from the `casts()` method (currently around line 40).

- [ ] **Step 5: Run migrations + full suite**

Run: `cd /Users/frankcornet/skv1 && php artisan migrate`
Expected: both new migrations apply successfully.

Run: `cd /Users/frankcornet/skv1 && ./vendor/bin/pest`
Expected: PASS — no regressions. Tests that use `User::factory()->superAdmin()` continue to pass because the role assignment still happens via `afterCreating`.

If any test fails because it sets `'is_super_admin' => true` directly via `create([...])`, fix that test by either removing the `is_super_admin` key or replacing it with a post-create role assignment. Do not re-add the column.

- [ ] **Step 6: Pint + commit**

```bash
cd /Users/frankcornet/skv1
./vendor/bin/pint database/factories/UserFactory.php app/Models/User.php
git add database/migrations/2026_05_09_120000_assign_super_admin_role_to_legacy_flag_holders.php database/migrations/2026_05_09_120100_drop_is_super_admin_from_users_table.php database/factories/UserFactory.php app/Models/User.php
git commit -m "$(cat <<'EOF'
refactor(auth): drop is_super_admin column, finish role-based migration

Two migrations: a data migration that assigns super_admin role to any
legacy flag-holders (idempotent — no-op if the role is already in place),
and a schema migration that drops the column + index.

UserFactory::superAdmin() and User model no longer reference the flag.
Authorization is now exclusively spatie-role-based.
EOF
)"
```

---

## Task 9: Full-suite verification + final cleanup

**Goal:** Confirm the whole project still passes. Identify any production-code references to `is_super_admin` (the column) that linger after Task 8.

- [ ] **Step 1: Run the full Pest suite**

Run: `cd /Users/frankcornet/skv1 && ./vendor/bin/pest`
Expected: PASS — all tests green. Baseline 149 + new tests across Tasks 1, 2, 4, 5, 6, 7 = approximately 14 new tests, totalling around 163.

- [ ] **Step 2: Grep for stale `is_super_admin` references**

Run: `cd /Users/frankcornet/skv1 && grep -rn "is_super_admin" app/ database/ tests/ --include="*.php"`
Expected: only matches inside the two migration files (which are intentional historical references). Production code (`app/`) should have ZERO matches. If any remain, fix them in this task before continuing.

If matches show up in `tests/` that are NOT in test code that explicitly refers to migration history, they likely indicate stale flag-checking; fix those by switching to role checks.

- [ ] **Step 3: Pint + final commit (if anything was fixed in Step 2)**

```bash
cd /Users/frankcornet/skv1
./vendor/bin/pint
git add -p   # carefully stage only what you fixed in step 2
git commit -m "chore(roles): clean up stray is_super_admin references after refactor"
```

If Step 2 was clean, no commit is needed.

---

## Self-Review (post-write checklist)

**Spec coverage:**
- [x] Section 1 (RolesAndPermissionsSeeder) → Task 1
- [x] Section 2 (OrganisationObserver::created) → Task 2
- [x] Section 3 (DemoUsersSeeder + DemoOrganisationsSeeder) → Task 3
- [x] Section 4 (User model `isSuperAdmin`) → Task 5 (and column drop in Task 8)
- [x] Section 5 (AppServiceProvider Gate::before removal) → Task 5
- [x] Section 6 (ResolveTenant apex-fallback) → Task 4
- [x] Section 7 (Send component role-picker) → Task 7
- [x] Section 8 (InvitationService team-scope + super_admin propagation) → Task 6
- [x] Section 9 (View role multi-select) → Task 7
- [x] Section 10 (UserFactory superAdmin state) → Tasks 3 + 8
- [x] Section 11 (Migrations: data + schema) → Task 8

**Placeholder scan:** No TBD/TODO/"add validation"/"similar to" placeholders. Each step is concrete with code or runnable commands.

**Type consistency:** `availableRoles()`, `availableOrganisations()`, `super_admin`/`organisation_admin`/`test1`/`test2`, `setPermissionsTeamId`, `getPermissionsTeamId` used consistently across tasks.

Plan ready for execution.
