# skv1 — Foundation Implementation Plan (Phase 1: macro-steps 1-9)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Brengt skv1 van leeg-Laravel naar werkende auth + multi-tenancy + RBAC fundament. Acceptance §11 stappen 1-3 + 6-7 worden door dit plan gehaald.

**Architecture:** Single-DB multi-tenant via `ResolveTenant` middleware → subdomein-resolved `Organisation`. Permission-scoping via spatie/laravel-permission teams-mode (team_id = organisation_id). `BelongsToOrganisation` trait levert auto-fill + global scope. Auth = invite-only Fortify + Livewire/Flux UI.

**Tech Stack:** Laravel 13, Livewire 4, Flux UI Pro, Tailwind 4, Pest 4 (incl. browser plugin), PostgreSQL (Herd Pro), spatie/permission, spatie/activitylog.

**Reference docs:**
- `skv1_create.md` (root) — autoritatieve spec
- Super prompt §3 (packages), §4 (architecture), §5 (migrations), §6 (deep modules), §7 (TDD), §10 (implementation order)

**Out of scope for this plan:** macro-stappen 10-21 (Invitation flow, UI CRUD, Impersonation, Activity-log views, Health check, Backups, Error pages, Template publish). Volgt in Phase 2/3 plan.

---

## Phase 1 Macro-steps

| # | Macro-stap | DoD |
|---|---|---|
| 1 | Fresh Laravel 13 project + Herd-link + initial commit | `https://skv1.test` toont welkomst |
| 2 | Composer + npm packages installeren | clean install |
| 3 | Pest 4 init + Pint + CI skeleton | leeg-test groen |
| 4 | `.env` + Postgres DB + migrate | `migrate` schoon |
| 5 | Migrations (organisations, users-extend, invitations) + factories | factories testbaar |
| 6 | Spatie permission seeder (14 perms + 2 roles) | seeder-test groen |
| 7 | `BelongsToOrganisation` trait + `TenantOwned` interface + arch test | §7.2 groen |
| 8 | `ResolveTenant` middleware + `tenant()` helper + `Tenant` facade | §7.1 groen |
| 9 | Auth UI (login/forgot/reset) + 2FA enrolment | §7.6 login + reset browser groen |

---

## Task 1: Fresh Laravel 13 project + Herd-link

**Context:** `/Users/frankcornet/skv1/` bestaat al met git repo (geen commits) en alleen spec-files (`skv1_create.md`, `skv1_uitleg.html`). We installeren Laravel ín deze directory zonder de spec-files te overschrijven.

**Files:**
- Create: hele Laravel app skelet
- Preserve: `skv1_create.md`, `skv1_uitleg.html`, `.git/`, `.claude/`, `docs/`

- [ ] **Step 1.1: Backup spec-files**

```bash
mkdir -p /tmp/skv1-spec-backup
cp /Users/frankcornet/skv1/skv1_create.md /tmp/skv1-spec-backup/
cp /Users/frankcornet/skv1/skv1_uitleg.html /tmp/skv1-spec-backup/
```

- [ ] **Step 1.2: Install Laravel 13 in tmp dir then move**

Composer cannot install into non-empty dir. Strategy: install in `/tmp/skv1-fresh`, then rsync into target.

```bash
cd /tmp && rm -rf skv1-fresh && composer create-project laravel/laravel skv1-fresh "^13.0" --prefer-dist --no-interaction
```
Expected: green install, dir `/tmp/skv1-fresh` populated.

- [ ] **Step 1.3: Move into target preserving .git, .claude, docs, spec files**

```bash
cd /Users/frankcornet/skv1
rsync -a --exclude='.git' --exclude='.claude' --exclude='docs' --exclude='skv1_create.md' --exclude='skv1_uitleg.html' /tmp/skv1-fresh/ ./
```

- [ ] **Step 1.4: Move spec docs into `docs/spec/`**

Spec belongs alongside the kit, not at root. Move:
```bash
mkdir -p docs/spec
git mv skv1_create.md docs/spec/skv1_create.md 2>/dev/null || mv skv1_create.md docs/spec/
git mv skv1_uitleg.html docs/spec/skv1_uitleg.html 2>/dev/null || mv skv1_uitleg.html docs/spec/
```

- [ ] **Step 1.5: Herd link + secure + Postgres DB create**

```bash
cd /Users/frankcornet/skv1
herd link skv1
herd secure skv1
createdb -h 127.0.0.1 -U "$USER" skv1 || true   # graceful if exists
```
Expected: `https://skv1.test` reachable; Postgres DB `skv1` exists.

- [ ] **Step 1.6: Verify welcome page renders**

```bash
curl -ksI https://skv1.test/ | head -3
```
Expected: `HTTP/2 200`.

- [ ] **Step 1.7: Initial commit**

```bash
git add -A
git commit -m "chore: bootstrap Laravel 13 via composer create-project"
```

---

## Task 2: Composer + npm packages

**Files:** `composer.json`, `package.json`, `composer.lock`, `package-lock.json`

- [ ] **Step 2.1: Add production composer packages**

```bash
composer require \
  livewire/livewire \
  livewire/flux \
  livewire/flux-pro \
  spatie/laravel-permission \
  spatie/laravel-activitylog \
  spatie/laravel-backup \
  lab404/laravel-impersonate \
  pragmarx/google2fa-laravel \
  bacon/bacon-qr-code
```

> **Flux Pro auth note:** Flux Pro requires `composer config http-basic.composer.fluxui.dev <license-email> <license-key>`. If the auth fails, stop and ask the user for a Flux license. The kit cannot proceed without Flux Pro.

- [ ] **Step 2.2: Add dev composer packages**

```bash
composer require --dev \
  pestphp/pest \
  pestphp/pest-plugin-laravel \
  pestphp/pest-plugin-browser \
  laravel/pint \
  laravel/telescope \
  barryvdh/laravel-debugbar
```

> If `pestphp/pest-plugin-browser` 404s, fall back to whatever browser-plugin package the Pest 4 changelog specifies, and record the substitution in `OUTPUT_SUMMARY.md`.

- [ ] **Step 2.3: Publish vendor configs**

```bash
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
php artisan vendor:publish --provider="Spatie\Activitylog\ActivitylogServiceProvider" --tag="activitylog-migrations"
php artisan vendor:publish --provider="Spatie\Backup\BackupServiceProvider"
php artisan vendor:publish --tag=livewire:config
php artisan flux:publish 2>/dev/null || true
```

- [ ] **Step 2.4: npm install**

```bash
npm install
```
Tailwind v4 + Vite + Flux assets are now wired. `vite.config.js` already ships from Laravel skeleton.

- [ ] **Step 2.5: Verify**

```bash
php artisan --version
npx vite --version
```
Expected: Laravel ≥13, Vite present.

- [ ] **Step 2.6: Commit**

```bash
git add -A
git commit -m "chore: install runtime + dev dependencies (Livewire, Flux Pro, Pest 4, Spatie suite)"
```

---

## Task 3: Pest 4 init + Pint + initial CI skeleton

**Files:**
- Create: `tests/Pest.php` (already from Pest install), `phpunit.xml`, `pint.json`, `.github/workflows/ci.yml`

- [ ] **Step 3.1: Pest install**

```bash
./vendor/bin/pest --init
```
Replaces `phpunit.xml` (or generates `tests/Pest.php`). Pest 4 ships browser support via the plugin.

- [ ] **Step 3.2: Pint config**

Create `pint.json`:
```json
{
    "preset": "laravel"
}
```

- [ ] **Step 3.3: Smoke test runs**

```bash
./vendor/bin/pest --testsuite=Unit --compact
./vendor/bin/pint --test
```
Expected: both pass on the default skeleton (Laravel ships an example test).

- [ ] **Step 3.4: CI workflow stub**

Create `.github/workflows/ci.yml` (full version per super prompt §9.8):
```yaml
name: ci
on: [push, pull_request]
jobs:
  pint:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.3', tools: composer }
      - run: composer install --no-interaction --prefer-dist
      - run: vendor/bin/pint --test

  pest-unit-feature:
    runs-on: ubuntu-latest
    services:
      postgres:
        image: postgres:16
        env:
          POSTGRES_PASSWORD: test
          POSTGRES_USER: test
          POSTGRES_DB: skv1_test
        ports: ['5432:5432']
        options: --health-cmd "pg_isready -U test" --health-interval 5s
    env:
      DB_CONNECTION: pgsql
      DB_HOST: 127.0.0.1
      DB_PORT: 5432
      DB_DATABASE: skv1_test
      DB_USERNAME: test
      DB_PASSWORD: test
      APP_ENV: testing
      APP_KEY: base64:GENERATEDLATERFROMARTISAN
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.3', tools: composer, extensions: pdo_pgsql, coverage: none }
      - run: composer install --no-interaction --prefer-dist
      - run: cp .env.example .env && php artisan key:generate
      - run: php artisan migrate --force
      - run: vendor/bin/pest --testsuite=Unit,Feature --compact
```

- [ ] **Step 3.5: Commit**

```bash
git add -A && git commit -m "chore: pest 4, pint, CI skeleton"
```

---

## Task 4: .env + Postgres + migrate

**Files:** `.env`, `.env.example`, `app/Providers/AppServiceProvider.php`, `config/app.php`

- [ ] **Step 4.1: Update `.env.example` per super prompt §2.3**

Use the exact env block from the super prompt §2.3, including `APP_APEX_DOMAIN`, `APP_ADMIN_HOST`, `SESSION_SECURE_COOKIE=true`, `DB_CONNECTION=pgsql`, `MAIL_HOST=127.0.0.1` (Mailpit/Herd).

- [ ] **Step 4.2: Add `apex_domain` and `admin_host` to `config/app.php`**

```php
'apex_domain' => env('APP_APEX_DOMAIN', 'skv1.test'),
'admin_host' => env('APP_ADMIN_HOST', 'admin.skv1.test'),
```

- [ ] **Step 4.3: AppServiceProvider — force HTTPS**

Edit `app/Providers/AppServiceProvider.php` `boot()`:
```php
use Illuminate\Support\Facades\URL;

if ($this->app->environment(['local', 'staging', 'production'])) {
    URL::forceScheme('https');
}
```

- [ ] **Step 4.4: Copy & key + migrate**

```bash
cp .env.example .env
php artisan key:generate
php artisan migrate
```
Expected: default migrations + spatie + activitylog all run on Postgres without errors.

- [ ] **Step 4.5: Commit**

```bash
git add -A && git commit -m "chore: env, postgres connection, force-https in app provider"
```

---

## Task 5: Migrations + factories

**Files:**
- Create: `database/migrations/xxxx_create_organisations_table.php`
- Create: `database/migrations/xxxx_extend_users_table.php`
- Create: `database/migrations/xxxx_create_invitations_table.php`
- Create: `app/Models/Organisation.php`, `app/Models/Invitation.php`
- Modify: `app/Models/User.php`
- Create: `database/factories/OrganisationFactory.php`, `InvitationFactory.php`
- Modify: `database/factories/UserFactory.php`
- Test: `tests/Unit/Migrations/SchemaTest.php`

- [ ] **Step 5.1: Failing test — schema shape**

`tests/Unit/Migrations/SchemaTest.php`:
```php
<?php

use Illuminate\Support\Facades\Schema;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('has organisations table with expected columns', function () {
    expect(Schema::hasTable('organisations'))->toBeTrue();
    expect(Schema::hasColumns('organisations', [
        'id', 'name', 'slug', 'description', 'created_at', 'updated_at', 'deleted_at',
    ]))->toBeTrue();
});

it('has users table extended with tenant + activation + 2fa columns', function () {
    expect(Schema::hasColumns('users', [
        'organisation_id', 'status', 'activation_token', 'activation_expires_at',
        'activated_at', 'two_factor_secret', 'two_factor_enabled_at', 'locale', 'deleted_at',
    ]))->toBeTrue();
});

it('has invitations table with expected columns', function () {
    expect(Schema::hasTable('invitations'))->toBeTrue();
    expect(Schema::hasColumns('invitations', [
        'id', 'user_id', 'invited_by', 'token', 'expires_at',
        'reminder_sent_at', 'accepted_at', 'created_at', 'updated_at',
    ]))->toBeTrue();
});
```

- [ ] **Step 5.2: Run — expect FAIL (tables don't exist yet)**

```bash
./vendor/bin/pest tests/Unit/Migrations/SchemaTest.php
```

- [ ] **Step 5.3: Create migrations** per super prompt §5.1, §5.2, §5.3 (verbatim).

```bash
php artisan make:migration create_organisations_table
php artisan make:migration extend_users_table
php artisan make:migration create_invitations_table
```
Then paste schemas from super prompt §5.

- [ ] **Step 5.4: Models**

`app/Models/Organisation.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organisation extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['name', 'slug', 'description'];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function invitations()
    {
        return $this->hasManyThrough(Invitation::class, User::class);
    }
}
```

`app/Models/Invitation.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Invitation extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'invited_by', 'token', 'expires_at',
        'reminder_sent_at', 'accepted_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function inviter()
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
```

Modify `app/Models/User.php`: add `SoftDeletes`, `HasRoles` (spatie), `Impersonate` (lab404), and casts:
```php
'two_factor_secret' => 'encrypted',
'activation_expires_at' => 'datetime',
'activated_at' => 'datetime',
'two_factor_enabled_at' => 'datetime',
```
Plus relations `organisation()`, `invitations()`.

- [ ] **Step 5.5: Factories**

`database/factories/OrganisationFactory.php`:
```php
<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class OrganisationFactory extends Factory
{
    public function definition(): array
    {
        $name = $this->faker->unique()->company();
        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => $this->faker->sentence(),
        ];
    }
}
```

`database/factories/InvitationFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class InvitationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'invited_by' => User::factory(),
            'token' => Str::random(64),
            'expires_at' => now()->addDays(7),
        ];
    }
}
```

`UserFactory` adjustments: default `status => 'active'`, `locale => 'nl'`, `organisation_id => null` (let trait fill).

- [ ] **Step 5.6: Migrate fresh + run schema tests**

```bash
php artisan migrate:fresh
./vendor/bin/pest tests/Unit/Migrations/SchemaTest.php
```
Expected: PASS.

- [ ] **Step 5.7: Commit**

```bash
git add -A && git commit -m "feat: organisations + extended users + invitations migrations & models"
```

---

## Task 6: Spatie permission seeder + 2 roles + 14 permissions

**Files:**
- Create: `database/seeders/RolesAndPermissionsSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Modify: `config/permission.php` — `'teams' => true`, team_resolver
- Test: `tests/Feature/Seeding/RolesAndPermissionsSeederTest.php`

- [ ] **Step 6.1: Failing test**

`tests/Feature/Seeding/RolesAndPermissionsSeederTest.php`:
```php
<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('seeds 14 permissions and 2 roles', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(Permission::count())->toBeGreaterThanOrEqual(14);
    expect(Role::where('name', 'super_admin')->whereNull('team_id')->exists())->toBeTrue();
    expect(Role::where('name', 'organisation_admin')->whereNull('team_id')->exists())->toBeTrue();
});
```

- [ ] **Step 6.2: Run — expect FAIL**

- [ ] **Step 6.3: `config/permission.php` — enable teams**

```php
'teams' => true,
'team_foreign_key' => 'team_id',
'team_resolver' => function () {
    return optional(app('currentOrganisation', null))->id ?? null;
},
```

> Spatie's permission package needs the migrations re-run with `team_id`. Run `php artisan migrate:fresh` after publishing.

- [ ] **Step 6.4: Implement seeder per super prompt §5.5**

14 permissions: `users.view`, `users.create`, `users.update`, `users.delete`, `users.impersonate`, `roles.view`, `roles.manage`, `organisations.view`, `organisations.manage`, `invitations.send`, `invitations.cancel`, `system.health`, `activity.view`, `backup.manage` — all guard `web`.

- [ ] **Step 6.5: Hook into DatabaseSeeder**

```php
public function run(): void
{
    $this->call([
        RolesAndPermissionsSeeder::class,
        DemoOrganisationsSeeder::class,    // Task later
        DemoUsersSeeder::class,            // Task later
    ]);
}
```

- [ ] **Step 6.6: Run test — expect PASS**

```bash
php artisan migrate:fresh
./vendor/bin/pest tests/Feature/Seeding/RolesAndPermissionsSeederTest.php
```

- [ ] **Step 6.7: Commit**

```bash
git add -A && git commit -m "feat: spatie permission teams-mode + 14 permissions + 2 roles seeder"
```

---

## Task 7: BelongsToOrganisation trait + TenantOwned interface + arch test

**Files:**
- Create: `app/Contracts/TenantOwned.php`
- Create: `app/Models/Concerns/BelongsToOrganisation.php`
- Modify: `app/Models/User.php` — `implements TenantOwned`, `use BelongsToOrganisation`
- Test: `tests/Unit/Tenancy/BelongsToOrganisationTest.php`
- Test: `tests/Arch/TenantOwnedArchTest.php`

- [ ] **Step 7.1: Write failing tests** per super prompt §7.2 (3 tests: auto-fill, scope-hides, super_admin-sees-all).

- [ ] **Step 7.2: Run — expect FAIL** (trait doesn't exist).

- [ ] **Step 7.3: Implement `TenantOwned` interface** (empty marker — see super prompt §6.5).

- [ ] **Step 7.4: Implement `BelongsToOrganisation` trait** verbatim from super prompt §6.1.

- [ ] **Step 7.5: Apply to `User`**

```php
class User extends Authenticatable implements TenantOwned
{
    use BelongsToOrganisation, HasFactory, HasRoles, Impersonate, Notifiable, SoftDeletes;
    // ...
}
```

- [ ] **Step 7.6: Arch test**

```php
arch('all tenant-owned models implement TenantOwned')
    ->expect('App\Models')
    ->toImplement('App\Contracts\TenantOwned')
    ->ignoring(['App\Models\Organisation', 'App\Models\Invitation']);
```

- [ ] **Step 7.7: Run all tests — expect PASS**

- [ ] **Step 7.8: Commit**

---

## Task 8: ResolveTenant middleware + helper + facade

**Files:**
- Create: `app/Http/Middleware/ResolveTenant.php`
- Create: `app/Support/Tenant.php` (facade target)
- Create: `app/Facades/Tenant.php`
- Create: `app/helpers.php` (or autoloaded function)
- Modify: `bootstrap/app.php` — register middleware
- Modify: `composer.json` — autoload `app/helpers.php`
- Test: `tests/Feature/Tenancy/ResolveTenantTest.php`

- [ ] **Step 8.1: Failing tests** per super prompt §7.1.

- [ ] **Step 8.2: Run — expect FAIL.**

- [ ] **Step 8.3: Helper**

`app/helpers.php`:
```php
<?php

if (! function_exists('tenant')) {
    function tenant(): ?\App\Models\Organisation
    {
        return app()->bound('currentOrganisation') ? app('currentOrganisation') : null;
    }
}
```

`composer.json` autoload:
```json
"autoload": {
    "files": ["app/helpers.php"],
    ...
}
```
Then `composer dump-autoload`.

- [ ] **Step 8.4: Middleware**

```php
<?php

namespace App\Http\Middleware;

use App\Models\Organisation;
use Closure;

class ResolveTenant
{
    public function handle($request, Closure $next)
    {
        $host = $request->getHost();
        $apex = config('app.apex_domain');
        $admin = config('app.admin_host');

        if ($host === $apex || $host === $admin) {
            return $next($request);
        }

        if (! str_ends_with($host, '.'.$apex)) {
            abort(404);
        }

        $slug = substr($host, 0, -strlen('.'.$apex));
        $org = Organisation::where('slug', $slug)->first();
        if (! $org) {
            abort(404);
        }

        app()->instance('currentOrganisation', $org);
        return $next($request);
    }
}
```

- [ ] **Step 8.5: Register middleware** in `bootstrap/app.php` (Laravel 11+ style):

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->web(append: [
        \App\Http\Middleware\ResolveTenant::class,
    ]);
})
```

- [ ] **Step 8.6: Facade**

`app/Facades/Tenant.php` resolving to `currentOrganisation`.

- [ ] **Step 8.7: Run tests — expect PASS.**

- [ ] **Step 8.8: Commit.**

---

## Task 9: Auth UI (login + forgot + reset) + 2FA enrolment

**Files:**
- Create: Livewire components in `app/Livewire/Auth/{Login,ForgotPassword,ResetPassword,Activate,TwoFactorEnroll}.php`
- Create: views in `resources/views/livewire/auth/*.blade.php`
- Create: layouts `resources/views/components/layouts/{guest,app}.blade.php`
- Modify: `routes/web.php` — register routes
- Modify: `app/Providers/AppServiceProvider.php` — disable Fortify register if loaded; we don't use Fortify
- Test: `tests/Browser/Auth/LoginFlowTest.php`, `ResetPasswordFlowTest.php`

> **Decision:** No Fortify, no Breeze. We hand-roll Livewire components for full control + Flux-native UI. This is faster than ripping out Breeze's Tailwind UI scaffolding.

- [ ] **Step 9.1: Failing browser tests** (login + reset only — invitation flow comes Task 11).

```php
test('user can log in and log out', function () {
    $org = \App\Models\Organisation::factory()->create(['slug' => 'demo1']);
    $user = \App\Models\User::factory()->create([
        'organisation_id' => $org->id,
        'email' => 'admin@demo1.local',
        'password' => bcrypt('Password123!'),
        'status' => 'active',
    ]);

    $page = visit("https://demo1.skv1.test/login");
    $page->fill('email', 'admin@demo1.local')
         ->fill('password', 'Password123!')
         ->press('Inloggen')
         ->assertPathIs('/dashboard');
});
```

- [ ] **Step 9.2: Run — expect FAIL** (no login route).

- [ ] **Step 9.3: Layouts** — Flux-based `guest.blade.php` shell with `<flux:header>`, dark-mode toggle, locale switch placeholder.

- [ ] **Step 9.4: Login Livewire component**

`app/Livewire/Auth/Login.php`:
```php
<?php

namespace App\Livewire\Auth;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Login extends Component
{
    #[Validate('required|email')] public string $email = '';
    #[Validate('required|string')] public string $password = '';
    #[Validate('boolean')] public bool $remember = false;

    public function submit()
    {
        $this->validate();
        $org = tenant();
        $credentials = [
            'email' => $this->email,
            'password' => $this->password,
            'organisation_id' => $org?->id,
            'status' => 'active',
        ];
        if (! Auth::attempt($credentials, $this->remember)) {
            $this->addError('email', __('Ongeldige inloggegevens.'));
            return;
        }
        session()->regenerate();
        return redirect()->intended('/dashboard');
    }

    public function render()
    {
        return view('livewire.auth.login')->layout('components.layouts.guest');
    }
}
```

- [ ] **Step 9.5: Login view** — Flux components only (`<flux:input>`, `<flux:button>`).

- [ ] **Step 9.6: Forgot + Reset Livewire components** using Laravel's built-in `Password::sendResetLink` + `Password::reset` facades.

- [ ] **Step 9.7: 2FA enrolment** — Livewire component using `pragmarx/google2fa-laravel` + `bacon/qr-code`. User enables from `/settings/two-factor`. Out of scope here: enforcing 2FA on login (that comes when we add a second login step).

- [ ] **Step 9.8: Routes + dashboard placeholder**

```php
Route::middleware('guest')->group(function () {
    Route::get('/login', \App\Livewire\Auth\Login::class)->name('login');
    Route::get('/forgot-password', \App\Livewire\Auth\ForgotPassword::class)->name('password.request');
    Route::get('/reset-password/{token}', \App\Livewire\Auth\ResetPassword::class)->name('password.reset');
});

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', fn () => view('dashboard'))->name('dashboard');
    Route::post('/logout', function () {
        auth()->logout();
        request()->session()->invalidate();
        return redirect('/login');
    })->name('logout');
});
```

- [ ] **Step 9.9: Run all tests + Pint test**

```bash
./vendor/bin/pest --testsuite=Unit,Feature --compact
./vendor/bin/pest --browser
./vendor/bin/pint --test
```

- [ ] **Step 9.10: Commit + tag phase 1 complete**

```bash
git add -A && git commit -m "feat: auth UI (login, forgot, reset) + 2FA enrolment via Livewire+Flux"
git tag phase-1-foundation
```

---

## Phase 1 Acceptance

After Task 9 completes:

- ✅ `https://skv1.test/` reaches Laravel
- ✅ `https://demo1.skv1.test/` redirects unauthenticated users to `/login`
- ✅ Login as seeded admin works → `/dashboard`
- ✅ Logout works
- ✅ `composer test` green
- ✅ `vendor/bin/pint --test` green
- ✅ All §7.1, §7.2, §7.5, §7.6 (login + reset) tests green

§11.5 (invitation flow), §11.3-11.4 (impersonation), §11.8 (health-check) are **Phase 2 / 3**.

---

## Next plan trigger

After phase 1 tag, write a Phase 2 plan covering:
- macro-step 10 (InvitationService + mail)
- macro-step 11 (invite UI)
- macro-step 12 (users CRUD)
- macro-step 13 (roles UI)
- macro-step 14 (org CRUD + observer)
- macro-step 15 (impersonation)

Then Phase 3 plan for steps 16-21.
