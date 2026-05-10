# skv1 — Technische beschrijving

Versie: 2026-05-10 · Doelgroep: ontwikkelaars die de codebase moeten begrijpen, uitbreiden of debuggen.

> Dit document beschrijft **hoe het systeem werkt en waar dingen staan**.
> Voor *installatie en projectsetup* zie [`handleiding.html`](handleiding.html).
> Voor *gebruikersuitleg in lekentaal* zie [`../skv1_uitleg.html`](../skv1_uitleg.html).
> Voor *ontwerpkeuzes en de oorspronkelijke opdracht* zie [`../OUTPUT_SUMMARY.md`](../OUTPUT_SUMMARY.md).

---

## 1. Wat is skv1?

skv1 is een opinionated Laravel-startersjabloon dat als basis dient voor alle nieuwe projecten van Frank Cornet. Per nieuw project levert het direct werkende multi-tenancy, RBAC, invitation-only authenticatie, audit-log, impersonation en backups — zodat per project geen tijd verloren gaat aan herhaaldelijk dezelfde basisinfrastructuur opzetten.

Het wordt geconsumeerd via:
```
composer create-project wapja/skv1 <projectnaam>
```

### Tech stack

| Laag | Keuze |
|---|---|
| Framework | Laravel 13 |
| UI-componenten | Livewire 4 + Flux UI Pro |
| Database | PostgreSQL 16+ (Herd Pro levert lokaal v18) |
| PHP | 8.3+ (Herd levert 8.4) |
| Tests | Pest 4 + pest-plugin-browser |
| Code style | Laravel Pint (PSR-12 variant) |
| Build | Vite 8 + Tailwind v4 |
| Queue / cache / session | `database`-driver (geen Redis, geen Horizon) |
| RBAC | `spatie/laravel-permission` (teams-mode) |
| Audit | `spatie/laravel-activitylog` |
| Impersonation | `lab404/laravel-impersonate` |
| 2FA (opt-in) | `pragmarx/google2fa-laravel` + `bacon/bacon-qr-code` |
| Backups | `spatie/laravel-backup` |
| Profiler (alleen lokaal) | Laravel Telescope + Debugbar |

### Bewust **niet** in scope

Billing, publieke marketing-frontend, REST/JSON API, Sanctum, Vue/React/Inertia, MySQL/SQLite-fallback, Horizon, SSO/social login, feature-flag systeem, custom CLI-installer, publieke registratie.

---

## 2. Mappenstructuur op hoog niveau

```
skv1/
├── app/
│   ├── Contracts/             ← marker-interfaces
│   ├── Exceptions/            ← per-feature exception classes
│   │   ├── Impersonation/
│   │   └── Invitation/
│   ├── Http/
│   │   ├── Controllers/       ← uitsluitend HealthCheck (rest is Livewire MFC)
│   │   ├── Middleware/        ← ResolveTenant, HealthCheckAuth
│   │   └── Requests/          ← FormRequests
│   ├── Mail/                  ← Mailable classes
│   ├── Models/
│   │   └── Concerns/          ← reusable model traits
│   ├── Observers/             ← model lifecycle hooks
│   ├── Policies/              ← Gate-policies
│   ├── Providers/             ← AppServiceProvider, TelescopeServiceProvider
│   ├── Services/              ← business logic (zie §6)
│   └── helpers.php            ← globale `tenant()` helper
├── bootstrap/                  ← Laravel 11+ bootstrap (geen Kernel.php)
├── config/                     ← config-bestanden
├── database/
│   ├── factories/
│   ├── migrations/
│   └── seeders/
├── docs/
│   ├── handleiding.html       ← installatie & projectsetup
│   ├── technische-beschrijving.md/.html  ← dit document
│   └── superpowers/           ← plans & specs uit eerdere sessies
├── lang/                       ← nl.json + vendor/
├── public/                     ← Vite-build doelmap
├── resources/views/            ← Blade-templates, layouts en Livewire MFC's onder components/<feature>/⚡<naam>/
├── routes/
│   ├── console.php            ← scheduler-jobs
│   └── web.php                ← alle web-routes
├── storage/
└── tests/
    ├── Arch/
    ├── Browser/
    ├── Feature/
    └── Unit/
```

---

## 3. Request-levenscyclus

Laravel 13 gebruikt het bootstrap-pattern uit Laravel 11+. **Er is geen `app/Http/Kernel.php`.** De middleware-registratie en routing wordt opgegeven in `bootstrap/app.php`:

```php
// bootstrap/app.php
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web:      __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health:   '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            ResolveTenant::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
```

Een binnenkomende request doorloopt:

1. **Standaard `web`-middleware-stack** (sessions, CSRF, cookies, encryption).
2. **`ResolveTenant`** — leest het hostname, bindt zo nodig de `Organisation` aan de container en zet de Spatie team-scope. Zie §4.
3. **Route-middleware** (`auth`, `guest`, `signed`, `HealthCheckAuth`).
4. **Livewire-component of view** rendert het antwoord.

> **Healthcheck**: `/up` is de Laravel-default healthcheck. Daarnaast bestaat een custom `/health-check` (zie `routes/web.php`) met API-key/permission gate via `HealthCheckAuth`.

---

## 4. Multi-tenancy

skv1 is **single-database multi-tenant**: één gedeelde PostgreSQL-database met een `organisation_id` op elke tenant-owned tabel. Subdomeinen identificeren de tenant.

### 4.1 Tenant-resolutie via subdomein

```
school1.skv1.test  →  Organisation::where('slug', 'school1')
school2.skv1.test  →  Organisation::where('slug', 'school2')
skv1.test          →  apex (geen tenant)
admin.skv1.test    →  apex/admin (geen tenant; voor super-admins)
```

Logic in `app/Http/Middleware/ResolveTenant.php`:

| Hostname | Gedrag |
|---|---|
| `apex_domain` of `admin_host` | Geen tenant binden. Voor super-admins wordt Spatie's `team_id` op de laagst-genummerde organisatie gezet, zodat role-lookups werken (zie §5.3). |
| `<slug>.<apex>` (bekende slug) | `Organisation` wordt opgehaald, gebonden aan `app('currentOrganisation')`, en `PermissionRegistrar::setPermissionsTeamId($org->id)` aangeroepen. |
| Onbekend subdomein | `abort(404)`. |

`apex_domain` en `admin_host` worden gelezen uit `config/app.php` (gevoed door `APP_APEX_DOMAIN` en `APP_ADMIN_HOST`).

### 4.2 De `BelongsToOrganisation`-trait

Locatie: `app/Models/Concerns/BelongsToOrganisation.php`

Modellen die per-tenant data bevatten gebruiken deze trait. Het levert drie dingen:

1. **Auto-vullen van `organisation_id`** bij het aanmaken van een nieuw record:
   ```php
   static::creating(function ($model) {
       if (! $model->organisation_id && $tenant = static::resolveCurrentTenant()) {
           $model->organisation_id = $tenant->id;
       }
   });
   ```
2. **Globale scope** die queries automatisch filtert op de huidige tenant. Super-admins worden bewust overgeslagen, zodat zij cross-org kunnen werken.
3. **`organisation()` BelongsTo**-relatie en **`scopeWithoutTenantScope()`** voor expliciete uitstapjes.

> **Niet-evidente valstrik**: de scope gebruikt `auth()->hasUser()` (niet `auth()->user()`). De inline comment legt uit waarom: `auth()->user()` triggert SessionGuard's eigen `User::find($id)`-query, die opnieuw deze scope binnentreedt — recursie tot PHP-FPM crasht. Wijzig dit niet zonder te begrijpen waarom.

### 4.3 Het `TenantOwned`-contract

Locatie: `app/Contracts/TenantOwned.php`

Volledig leeg:
```php
interface TenantOwned {}
```

Het is een **marker-interface**: geen contract-methodes, alleen labelen. Wie hem implementeert tekent het volgende:

- "Mijn rijen worden door `OrganisationObserver` mee soft-deleted als de organisatie zelf soft-deleted wordt."
- "Ik moet expliciet door de architectuur-test (`tests/Arch/TenantOwnedArchTest.php`) gevalideerd worden."

Huidige implementaties: `User`, `Role` (de skv1-extensie van Spatie's Role).

### 4.4 De `tenant()`-helper

`app/helpers.php` definieert globaal:

```php
function tenant(): ?Organisation
{
    return app()->bound('currentOrganisation') ? app('currentOrganisation') : null;
}
```

Bruikbaar in views en services om de huidige organisatie op te halen zonder dependency injection.

---

## 5. Autorisatie

### 5.1 Permissions

Permissions zijn **globaal**, niet per-tenant. Gedefinieerd in `database/seeders/RolesAndPermissionsSeeder.php`. Ze volgen het patroon `<resource>.<actie>`, bijvoorbeeld:

```
users.view, users.create, users.update, users.delete, users.impersonate
roles.view, roles.manage
organisations.view, organisations.manage
invitations.send, invitations.cancel
activity.view
backup.manage
system.health
```

### 5.2 Roles

Spatie draait in **teams-mode**: het `team_id`-kolom is gelijk aan `organisation_id`.

Er bestaan twee niveaus van rollen:

| Niveau | `team_id` | Doel |
|---|---|---|
| **Template** | `NULL` | Apex-definitie. Wordt nooit direct toegekend aan gebruikers. |
| **Per-tenant kopie** | `<organisation_id>` | Echte rol die toegekend wordt aan users binnen die organisatie. |

Wanneer een nieuwe `Organisation` wordt aangemaakt, materialiseert `OrganisationObserver::created()` een per-tenant kopie van elke template-rol (inclusief permissions). Code in `app/Observers/OrganisationObserver.php` en hulpklasse `app/Services/RoleBackfiller.php` voor bestaande organisaties (gebruikt door migratie `2026_05_09_184629`).

Standaard template-rollen:
- `super_admin` — krijgt **alle** permissions
- `organisation_admin` — krijgt org-niveau admin permissions
- `test1`, `test2` — leeg, voor testdoeleinden

`App\Models\Role` extendt `Spatie\Permission\Models\Role`, voegt `SoftDeletes` toe en implementeert `TenantOwned`.

### 5.3 Super-admin pattern

> ⚠️ **Belangrijke breuk met oudere skv1-iteraties**: super-admin is géén `Gate::before`-callback meer en géén `users.is_super_admin` boolean. Beide zijn verwijderd in migraties `2026_05_09_120000` en `2026_05_09_120100`. Super-admin is nu **een echte Spatie-rol** die in iedere organisatie aan de gebruiker is toegekend.

De controle gebeurt via `User::isSuperAdmin()`:

```php
public function isSuperAdmin(): bool
{
    $registrar = app(PermissionRegistrar::class);
    $teamsEnabled = $registrar->teams;
    $registrar->teams = false;          // tijdelijk team-scoping uit
    try {
        return $this->roles()->where('name', 'super_admin')->exists();
    } finally {
        $registrar->teams = $teamsEnabled;
    }
}
```

Het tijdelijk uitschakelen van team-scoping is identiek aan het patroon dat Spatie zelf in `bootHasRoles()` gebruikt. We willen weten "heeft deze user `super_admin` ergens?" — niet "in de huidig actieve team-context".

Cross-org propagatie van super-admin wordt gedaan door **`UserRoleSyncer::sync()`** (zie §6.2): wanneer iemand de super_admin-rol krijgt, wordt die in *elke* organisatie toegekend; wanneer hij wordt ingetrokken, in *elke* organisatie verwijderd.

### 5.4 Policies

Locatie: `app/Policies/`

| Policy | Beschermt | Belangrijkste regels |
|---|---|---|
| `UserPolicy` | `User`-model | `update()`/`delete()`: nooit jezelf, nooit een super-admin (tenzij je er zelf één bent), org-grens checken. |
| `OrganisationPolicy` | `Organisation`-model | Alleen super-admins mogen aanmaken/wijzigen/verwijderen. |
| `RolePolicy` | `Role`-model | `roles.manage`-permission vereist. Verwijderen faalt als users de rol nog dragen. |

Geregistreerd in `AppServiceProvider::boot()` via `Gate::policy(Role::class, RolePolicy::class)` etc.

---

## 6. Services

Locatie: `app/Services/`

skv1 plaatst **business logic in services**, niet in controllers of Livewire-componenten. Livewire delegeert naar services zodra het iets meer is dan UI-state.

### 6.1 `InvitationService`

Het hart van de invitation-workflow. Publieke methoden:

| Methode | Wat het doet |
|---|---|
| `invite($firstName, $middleName, $lastName, $email, $locale, $roles, $invitedBy, $organisationId)` | Maakt `User` (status `pending_activation`), kent rollen toe via `UserRoleSyncer`, maakt `Invitation` (token, 7 dagen vervaltijd), zet `InvitationMail` in de queue, logt naar `activity('invitations')`. Alles in één DB-transactie. |
| `accept($token, $password, $totpSecret = null)` | Valideert token, vervaltijd en cancellation. Zet `password`, `activated_at`, `status = 'active'`, optioneel 2FA-secret. Werpt `InvitationExpired` / `InvitationAlreadyAccepted` / `InvitationCancelled`. |
| `resendReminder($invitation, $actor)` | Zet de mail opnieuw in de queue, schuift `expires_at` met nóg 7 dagen door, zet `reminder_sent_at`. |
| `cancel($invitation, $actor)` | Soft-delete de uitgenodigde user — daarmee wordt de invitation impliciet "cancelled" (computed status). |
| `purgeExpired()` | Aangeroepen door scheduler; verwijdert echt-vervallen invitation-records. |

### 6.2 `UserRoleSyncer`

Centrale rol-toewijzing. Splitst de gevraagde rollen in twee groepen:

- **Reguliere rollen**: alleen binnen de primaire organisatie van de user (`syncRoles()` met team-scope = primary org).
- **`super_admin`**: cross-org. Bij toekenning → in elke organisatie toevoegen. Bij intrekking → uit elke organisatie verwijderen. Gebruikt `PermissionRegistrar` om team-context te switchen.

Wordt aangeroepen door de `users.edit` Livewire-component en door `InvitationService`.

### 6.3 `ImpersonationGuard`

Validatie en lifecycle voor impersonation. Voorkomt:

- Jezelf imiteren
- Een super-admin imiteren (immer verboden)
- Cross-org imiteren als je geen super-admin bent
- Geneste impersonation
- Lege of te lange reden (1–500 tekens)

Werpt exceptions uit `app/Exceptions/Impersonation/`. Schrijft elke `start()` naar `activity('impersonation')`.

`stop()` wordt aangeroepen door de POST `/impersonate/stop`-route.

### 6.4 `RoleBackfiller`

Eénmalige migratie-utility. Itereert alle bestaande organisaties en triggert dezelfde logica als `OrganisationObserver::created()` plus repointing van pivots. Wordt aangeroepen door migratie `2026_05_09_184629_backfill_per_org_role_copies_for_existing_orgs.php`.

---

## 7. Models

Locatie: `app/Models/`

| Model | Implements / Uses | Belangrijkste velden |
|---|---|---|
| `Organisation` | `SoftDeletes`, `HasFactory` | `name` (unique), `slug` (unique), `description` |
| `User` | `TenantOwned`, `BelongsToOrganisation`, `HasRoles`, `Impersonate`, `SoftDeletes`, `Notifiable` | profile (`first_name`, `middle_name`, `last_name`, `internal_id`, `phone`, `address`, `start_date`, `end_date`), auth (`email`, `password`), tenancy (`organisation_id`), status (`pending_activation`/`active`), 2FA (`two_factor_secret`, `two_factor_enabled_at`), locale |
| `Invitation` | `HasFactory` | `user_id`, `invited_by`, `token` (unique 64), `expires_at`, `reminder_sent_at`, `accepted_at` |
| `Role` (extends Spatie) | `TenantOwned`, `SoftDeletes` | + `team_id` van Spatie |

### 7.1 Laravel 13 attribuut-fillable

`User` gebruikt de nieuwe attribuut-syntax in plaats van `protected $fillable`:

```php
#[Fillable([
    'first_name', 'middle_name', 'last_name', /* … */
])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'activation_token'])]
class User extends Authenticatable implements TenantOwned
```

### 7.2 Computed attributes

`User::name` is afgeleid van `first_name + middle_name + last_name`:

```php
protected function name(): Attribute
{
    return Attribute::get(fn () => trim(implode(' ', array_filter([
        $this->first_name, $this->middle_name, $this->last_name,
    ]))));
}
```

`Invitation::status` is een computed attribuut dat één van `pending` / `accepted` / `expired` / `cancelled` teruggeeft op basis van timestamps en `user->trashed()`.

### 7.3 PostgreSQL-specifieke scopes

Search-scopes op `User` gebruiken `ILIKE` (case-insensitive Postgres-operator):

```php
public function scopeWhereNameLike(Builder $query, string $value): Builder
{
    $like = '%'.$value.'%';
    return $query->where(fn ($q) => $q
        ->where('first_name', 'ILIKE', $like)
        ->orWhere('middle_name', 'ILIKE', $like)
        ->orWhere('last_name',   'ILIKE', $like)
    );
}
```

> Wanneer je MySQL ooit zou ondersteunen, breken deze scopes. Dit is bewust — skv1 ondersteunt **uitsluitend** PostgreSQL.

---

## 8. Observers

Locatie: `app/Observers/`

### 8.1 `OrganisationObserver`

Geregistreerd in `AppServiceProvider::boot()`.

| Hook | Wat het doet |
|---|---|
| `created()` | Materialiseert per-tenant kopieën van alle template-rollen. Propageert bestaande super-admins naar de nieuwe org (zodat zij ook hier `super_admin` hebben). |
| `deleted()` (= soft-delete) | Cascade-soft-delete van alle `TenantOwned`-modellen in deze org, met **identieke timestamp** zodat `restoring()` ze precies weer kan terugbrengen. |
| `restoring()` | Maakt de cascade ongedaan. |

Wijzigingen aan dit observer raken de hele tenancy-laag — pas op.

---

## 9. Authenticatie

### 9.1 Geen publieke registratie

Er is **geen `/register`-route**. Nieuwe users komen uitsluitend binnen via een invitation (signed URL, 7 dagen geldig).

### 9.2 Sessions per subdomein

Configuratie in `config/session.php` (gevoed door `.env`):

```
SESSION_DRIVER=database
SESSION_DOMAIN=null         # ← null = current host only, geen apex-share
SESSION_SECURE_COOKIE=true  # ← vereist HTTPS (Herd: `herd secure`)
SESSION_PATH=/
SESSION_LIFETIME=120
```

Het belang van `SESSION_DOMAIN=null`: een sessie op `demo1.skv1.test` mag nooit gedeeld worden met `demo2.skv1.test` of de apex. Cookies blijven strak per subdomein.

### 9.3 2FA (opt-in)

Tijdens invitation-acceptatie kan de user optioneel TOTP instellen via `pragmarx/google2fa-laravel`. Het secret wordt versleuteld opgeslagen (`'two_factor_secret' => 'encrypted'` cast) en `two_factor_enabled_at` wordt gezet op het moment van activatie.

---

## 10. Frontend-architectuur

### 10.1 Geen losse controllers — Livewire 4 multi-file components

Op `HealthCheckController` na bevat `app/Http/Controllers/` geen UI-controllers. Pagina's zijn **Livewire 4 multi-file componenten** (MFC) die rechtstreeks aan een route worden gekoppeld via de `Route::livewire()` macro:

```php
Route::livewire('/admin/users', 'users.index')->name('users.index');
```

De Livewire-class bepaalt zélf zijn layout via een `#[Layout('components.layouts.app')]` attribuut. Anonymous classes (`new class extends Component { … }`) bevatten de componentlogica; de `render()`-methode gebruikt `return $this->view([...])` zonder expliciete view-naam — Livewire mapt automatisch op de naast gelegen Blade.

### 10.2 Componenten per feature

Locatie: `resources/views/components/<feature>/⚡<naam>/` met daarin `<naam>.php` (anonymous class) en `<naam>.blade.php` (view) naast elkaar. Eventuele scoped partials wonen in dezelfde directory.

| Feature | Componenten | String-naam(en) |
|---|---|---|
| `activity/` | `Index` | `activity.index` |
| `auth/` | `Login`, `Activate`, `ForgotPassword`, `ResetPassword` | `auth.login`, `auth.activate`, `auth.forgot-password`, `auth.reset-password` |
| `invitations/` | `Index` (+ `column-filter` partial), `Send` | `invitations.index`, `invitations.send` |
| `organisations/` | `Index`, `Edit` | `organisations.index`, `organisations.edit` |
| `roles/` | `Index`, `Edit` | `roles.index`, `roles.edit` |
| `users/` | `Index` (+ `column-filter` partial), `Edit`, `Impersonate` | `users.index`, `users.edit`, `users.impersonate` |

Tests refereren componenten via die string-naam: `Livewire::test('users.index')`. Routes idem via `Route::livewire('/path', 'users.index')`. De ⚡-prefix in de directory-naam is Livewire's conventie om MFC's visueel te onderscheiden van gewone Blade anonymous components in dezelfde `components/` boom; bij component-resolutie wordt de prefix gestript.

### 10.3 Layouts

Locatie: `resources/views/components/layouts/`

| Layout | Gebruik |
|---|---|
| `app.blade.php` | Authenticated layout met Flux-sidebar, navbar, dark-mode toggle. |
| `guest.blade.php` | Auth-formulieren (login, password reset, invitation-accept). Centreert form in een max-width `md` container. |

Dark-mode wordt client-side bewaard in `localStorage` onder de sleutel `skv1-theme` (Alpine).

### 10.4 Flux UI Pro

Pro-componenten zijn beschikbaar dankzij een `auth.json` met de license-credentials. Build- en assets-pijplijn: Vite 8 + Tailwind v4 (`@tailwindcss/vite`).

### 10.5 Translaties

- `lang/nl.json` — Nederlandse strings (default).
- `lang/vendor/backup/...` — Spatie Backup-vertalingen (auto-vendored).
- Standaard locale: `nl`. Fallback: `en`.

Per-user locale wordt opgeslagen in `users.locale` en geactiveerd door middleware (impliciet via Laravel's session-locale of expliciet in een toekomstige `SetLocale`-middleware — bij uitbreiding hier rekening mee houden).

---

## 11. Routes

### 11.1 `routes/web.php`

Livewire-routes gebruiken de `Route::livewire('/path', 'component.naam')` macro; de derde kolom hieronder toont de Livewire string-componentnaam.

| Pad | Methode | Middleware | Component / handler |
|---|---|---|---|
| `/` | GET | – | Inline redirect naar `dashboard` of `login` |
| `/login` | GET | `guest` | `auth.login` (Livewire MFC) |
| `/forgot-password` | GET | `guest` | `auth.forgot-password` |
| `/reset-password/{token}` | GET | `guest` | `auth.reset-password` |
| `/invitations/{token}/accept` | GET | `signed`, `guest` | `auth.activate` |
| `/health-check` | GET | `HealthCheckAuth` | `HealthCheckController` |
| `/dashboard` | GET | `auth` | view `dashboard` |
| `/admin/users` | GET | `auth` | `users.index` |
| `/admin/users/{user}/edit` | GET | `auth` | `users.edit` |
| `/admin/invitations` | GET | `auth` | `invitations.index` |
| `/admin/roles` | GET | `auth` | `roles.index` |
| `/admin/roles/create` | GET | `auth` | `roles.edit` |
| `/admin/roles/{role}/edit` | GET | `auth` | `roles.edit` |
| `/admin/organisations` | GET | `auth` | `organisations.index` |
| `/admin/organisations/create` | GET | `auth` | `organisations.edit` |
| `/admin/organisations/{organisation}/edit` | GET | `auth` | `organisations.edit` |
| `/admin/activity` | GET | `auth` | `activity.index` |
| `/impersonate/stop` | POST | `auth` | inline (`ImpersonationGuard::stop`) |
| `/logout` | POST | `auth` | inline (Auth::logout + session invalidate) |

`ResolveTenant` is **op alle web-routes actief** omdat hij in `bootstrap/app.php` aan de globale `web`-stack is toegevoegd.

### 11.2 `routes/console.php`

```php
Schedule::command('backup:clean')->dailyAt('01:00');
Schedule::command('backup:run --only-db')->dailyAt('01:30');
Schedule::call(fn () => app(InvitationService::class)->purgeExpired())
    ->daily()
    ->name('invitations:purge-expired');
```

Geen API-routes. Geen aparte `routes/auth.php`.

---

## 12. Database

### 12.1 Connectie

PostgreSQL — geen MySQL/SQLite-fallback, **niet inwisselbaar**.

```
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=skv1
DB_USERNAME=postgres
DB_PASSWORD=
```

### 12.2 Migrations (chronologisch)

| Bestand | Doel |
|---|---|
| `0001_01_01_000000_create_users_table.php` | Laravel-default users-tabel (later flink uitgebreid). |
| `0001_01_01_000001_create_cache_table.php` | `cache` + `cache_locks`. |
| `0001_01_01_000002_create_jobs_table.php` | `jobs`, `job_batches`, `failed_jobs` (database-queue). |
| `2026_05_08_083924_create_activity_log_table.php` | Spatie ActivityLog. |
| `2026_05_08_083924_create_permission_tables.php` | Spatie Permission tabellen, met `team_id`. |
| `2026_05_08_083936_create_telescope_entries_table.php` | Telescope. |
| `2026_05_08_100001_create_organisations_table.php` | `organisations` (id, name, slug, description, soft deletes). |
| `2026_05_08_100002_extend_users_table.php` | Voegt tenancy- en auth-velden aan `users` toe (organisation_id, status, activation_*, 2FA, locale, soft deletes, plus een `is_super_admin`-boolean die later gedropt wordt). |
| `2026_05_08_100003_create_invitations_table.php` | `invitations` (token, expires_at, reminder_sent_at, accepted_at). |
| `2026_05_08_180000_add_profile_fields_to_users_table.php` | Migreert van enkele `name`-kolom naar disaggregated profielvelden + backfill. |
| `2026_05_09_120000_assign_super_admin_role_to_legacy_flag_holders.php` | Datamigratie: zet de Spatie `super_admin`-rol in elke organisatie voor users met de oude boolean. |
| `2026_05_09_120100_drop_is_super_admin_from_users_table.php` | Schemamigratie: verwijdert `is_super_admin` definitief. |
| `2026_05_09_155104_add_soft_deletes_to_roles_table.php` | Soft-deletes op `roles` (per-tenant role-isolatie). |
| `2026_05_09_184629_backfill_per_org_role_copies_for_existing_orgs.php` | Roept `RoleBackfiller::backfillExistingOrganisations()` aan. |

> **Foreign-key strategie**: `users.organisation_id` is `restrictOnDelete` (eerst de users opruimen of soft-deleten via observer). `invitations.user_id` is `cascadeOnDelete`. `invitations.invited_by` is `restrictOnDelete`.

### 12.3 Seeders

Locatie: `database/seeders/`

| Seeder | Doel |
|---|---|
| `DatabaseSeeder` | Master, roept de andere aan. |
| `RolesAndPermissionsSeeder` | Permissions + template-rollen (`super_admin`, `organisation_admin`, `test1`, `test2`). |
| `DemoOrganisationsSeeder` | `demo1`, `demo2` voor lokaal testen. |
| `DemoUsersSeeder` | Demo-users in beide demo-organisaties met diverse rollen. |

### 12.4 Factories

Locatie: `database/factories/`

`UserFactory`, `OrganisationFactory`, `InvitationFactory`. `UserFactory` heeft states `superAdmin()`, `pending()` en `deactivated()`.

---

## 13. Tests

Test-runner: **Pest 4**. Configuratie in `tests/Pest.php` en `phpunit.xml`. Suites:

| Suite | Map | Wat erin staat |
|---|---|---|
| Unit | `tests/Unit/` | Geïsoleerde tests, momenteel `Migrations/SchemaTest.php`. |
| Feature | `tests/Feature/` | HTTP- en Livewire-tests, gegroepeerd per feature (`Activity/`, `Auth/`, `Impersonation/`, `Invitations/`, `Models/`, `Navigation/`, `Organisations/`, `Roles/`, `Seeding/`, `Services/`, `Tenancy/`, `Users/` + losse files voor `ErrorPage`, `Factories`, `HealthCheck`, `Scheduler`). |
| Arch | `tests/Arch/` | Architectuur-asserties: `TelescopeOnlyLocalArchTest` (Telescope mag niet in production-stack), `TenantOwnedArchTest` (alle tenant-modellen moeten het marker-contract implementeren). |
| Browser | `tests/Browser/Auth/` | `pest-plugin-browser` end-to-end tests. |

`phpunit.xml` zet voor tests:

```
APP_ENV=testing
DB_CONNECTION=pgsql        # tests draaien tegen een echte Postgres-test-DB
SESSION_DRIVER=array
CACHE_STORE=array
QUEUE_CONNECTION=sync
MAIL_MAILER=array
BCRYPT_ROUNDS=4            # snelle hashes in tests
```

### 13.1 Tests draaien

```bash
composer test            # alle Pest-suites
php artisan test --filter=Tenancy    # alleen tenancy-tests
./vendor/bin/pest --parallel         # parallel, lokaal sneller
```

---

## 14. Configuratie & environment

### 14.1 Belangrijke `.env`-variabelen

| Variabele | Doel |
|---|---|
| `APP_URL` | Volledige basis-URL incl. scheme, bv. `https://skv1.test`. |
| `APP_APEX_DOMAIN` | Apex hostname voor tenant-resolutie, bv. `skv1.test`. |
| `APP_ADMIN_HOST` | Apex-admin hostname, bv. `admin.skv1.test`. |
| `APP_LOCALE` / `APP_FALLBACK_LOCALE` | `nl` / `en`. |
| `DB_*` | PostgreSQL-credentials. |
| `SESSION_*` | Per-subdomein cookie-scope (zie §9.2). |
| `QUEUE_CONNECTION` | `database`. |
| `CACHE_STORE` | `database`. |
| `MAIL_*` | Standaard SMTP naar Herd Pro mail-service (`127.0.0.1:2525`). |
| `HEALTH_CHECK_KEY` | Header-token dat `/health-check` accepteert. |
| `TELESCOPE_ENABLED` | Schakelt Telescope aan; alleen lokaal aan zetten. |

### 14.2 Aangepaste config-files

Standaard Laravel-configs werden alleen aangepast waar nodig:

| Config | Wat is aangepast |
|---|---|
| `config/app.php` | `apex_domain`, `admin_host` toegevoegd; locale `nl`, fallback `en`, faker `nl_NL`. |
| `config/permission.php` | `role` model → `App\Models\Role`. Teams enabled. |
| `config/session.php` | Driver `database`, secure cookies, domain `null`. |
| `config/database.php` | Default `pgsql`. |
| `config/queue.php`, `config/cache.php` | `database`. |
| `config/backup.php` | Spatie Backup-config (DB-only). |
| `config/telescope.php` | Conditioneel geladen via `TelescopeServiceProvider`. |

---

## 15. Scheduler & queue

| Soort | Detail |
|---|---|
| Queue-driver | `database` (jobs in `jobs`-tabel). |
| Worker | `php artisan queue:listen` lokaal (gestart via `composer dev`). In productie: `php artisan queue:work` als systemd/supervisord. |
| Scheduler | `php artisan schedule:run` elk minuut (cron). |
| Geplande taken | Backup clean (01:00), backup run (01:30), invitations purge (daily). |

---

## 16. CI

GitHub Actions workflow in `.github/workflows/`. Per push:

1. PHP setup, Composer install (cached).
2. `vendor/bin/pint --test` — code style.
3. `php artisan migrate --env=testing` tegen Postgres-service-container.
4. `vendor/bin/pest` — Unit / Feature / Arch.
5. `vendor/bin/pest --browser` — browser-suite.

---

## 17. Waar vind ik...?

Snelle file-lookup voor veelvoorkomende vragen:

| Vraag | Antwoord |
|---|---|
| Hoe wordt een nieuwe user aangemaakt? | `app/Services/InvitationService.php::invite()` |
| Hoe verandert een rol? | `app/Services/UserRoleSyncer.php::sync()` |
| Wie checkt of iemand super-admin is? | `app/Models/User.php::isSuperAdmin()` |
| Wie zet de tenant-context? | `app/Http/Middleware/ResolveTenant.php` |
| Waar wordt `organisation_id` automatisch gevuld? | `app/Models/Concerns/BelongsToOrganisation.php` |
| Wat gebeurt er bij `Organisation::delete()`? | `app/Observers/OrganisationObserver.php::deleted()` |
| Welke routes bestaan? | `routes/web.php` |
| Waar zit de scheduler? | `routes/console.php` |
| Welke permissions bestaan? | `database/seeders/RolesAndPermissionsSeeder.php` |
| Hoe heet de invite-mail? | `app/Mail/InvitationMail.php` + `resources/views/mail/invitation.blade.php` |
| Welke layouts zijn er? | `resources/views/components/layouts/{app,guest}.blade.php` |
| Hoe weet ik of impersonation mag? | `app/Services/ImpersonationGuard.php::assertActorMayImpersonate()` |
| Welke statussen kan een Invitation hebben? | computed attribuut in `app/Models/Invitation.php` |
| Waar staan de tests voor tenancy? | `tests/Feature/Tenancy/` |
| Hoe roep ik in een view de huidige org aan? | `tenant()` helper (zie `app/helpers.php`) |

---

## 18. Veelvoorkomende uitbreidingen

### 18.1 Een nieuw tenant-owned model toevoegen

1. Migratie: voeg `organisation_id`-kolom toe met FK naar `organisations` (`restrictOnDelete()`).
2. Model: voeg `use BelongsToOrganisation;` toe en `implements TenantOwned`.
3. Eventueel: voeg toe aan `OrganisationObserver`-cascade (gebeurt al automatisch via de `TenantOwned`-check).
4. Schrijf een arch-test: `tests/Arch/TenantOwnedArchTest.php` zou hem al moeten oppikken; check.
5. Schrijf een tenancy-test in `tests/Feature/Tenancy/` die verifieert dat queries gefilterd worden.

### 18.2 Een nieuwe permission toevoegen

1. Voeg de string toe aan `RolesAndPermissionsSeeder` (lijst + toewijzing aan template-rollen).
2. Voeg een datamigratie toe die de permission in bestaande databases creëert en aan bestaande rollen toekent.
3. Update relevante policies en views (`@can(...)`).

### 18.3 Een nieuw subdomein-only feature toevoegen

Geen extra middleware nodig — `ResolveTenant` draait al globaal. Bewaak alleen in je policy/Livewire-component dat `tenant() !== null` voor pure tenant-features, of gebruik de `BelongsToOrganisation`-trait op het achterliggende model.

---

## 19. Conventies & afspraken

- **Code style**: Laravel Pint — `composer pint` voor formatting; CI faalt op stijlafwijkingen.
- **Naam-conventies**: Nederlandstalige UI-tekst, Engelstalige identifiers/code/comments.
- **Model traits**: `BelongsToOrganisation` is de enige skv1-eigen trait; staat in `app/Models/Concerns/`.
- **Services**: business logic woont in services, niet in controllers of Livewire-componenten.
- **Logging**: gebruik Spatie ActivityLog met expliciete `log_name` per feature (`'invitations'`, `'impersonation'`, …).
- **Exceptions**: per feature een eigen subnamespace onder `app/Exceptions/`.
- **Tests**: Pest, niet PHPUnit-stijl. Feature-tests gegroepeerd per feature-map.
- **Geen factory rollouts** in test-setup; gebruik factories per test.

---

## 20. Versie-historiek

| Datum | Wijziging |
|---|---|
| 2026-05-10 | Eerste versie van dit document. |
