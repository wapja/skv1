# Spatie Roles Everywhere — Replace Gate::before with Proper Role Checks

**Datum:** 2026-05-09
**Status:** Goedgekeurd ontwerp, klaar voor implementatieplan

## Aanleiding

skv1 gebruikt momenteel twee parallelle autorisatiemechanismen:

1. **Spatie roles + permissions** voor `organisation_admin` en gerelateerde permissies binnen een tenant.
2. **`users.is_super_admin` boolean kolom + `Gate::before` bypass** voor super-admins (cross-tenant root).

Die parallelle wereld maakt drift makkelijk en de invite-flow onsamenhangend: de role-picker UI kan geen super-admin uitnodigen zonder een aparte checkbox. Beslist op 2026-05-09: alle autorisatie gaat via Spatie. `Gate::before` voor super-admin verdwijnt, `is_super_admin` kolom verdwijnt, super-admin wordt een echte Spatie role.

## Doel

Eén consistente autorisatie-laag waarin elke gebruiker minstens één Spatie role heeft en alle `->can()` / `@can` checks via Spatie's permission-systeem lopen.

## Scope

**In scope:**
- Nieuwe Spatie roles: `super_admin` (alle permissies), `test1` (geen permissies), `test2` (geen permissies).
- Per-tenant role-kopieën via `OrganisationObserver::created`.
- Verwijderen `Gate::before` super-admin bypass in `AppServiceProvider`.
- Drop `users.is_super_admin` kolom (na data-migratie).
- `User::isSuperAdmin()` wordt thin wrapper rond `$this->hasRole('super_admin')`.
- `ResolveTenant` zet voor super-admins op apex een fallback `setPermissionsTeamId`.
- `Send` Livewire component krijgt role multi-select; scope per inviter.
- `InvitationService::invite()` assigne't roles bij activatie/persistentie en propageert `super_admin` cross-org.
- Update factories en tests.

**Buiten scope:**
- Geen wijziging in andere permissions of role-namen behalve de drie genoemde toevoegingen.
- Geen frontend-design refactor — alleen het role-picker veld erbij.
- Geen eigen role-management UI in deze feature (er is al `Livewire/Roles/Index` voor toekomstig beheer).
- Geen wijziging in middleware ordering of session-/cookie-domain config.

## Architectuur

Drie lagen veranderen, in deze volgorde:

1. **Data + seeders** — nieuwe roles, per-tenant copies, super-admin role-assignments in plaats van flag.
2. **Authorization-laag** — `Gate::before` weg, `isSuperAdmin()` op role check, `ResolveTenant` apex-fallback, kolom drop.
3. **UI** — invite-form role multi-select met scope-regels.

## Componenten in detail

### 1. `RolesAndPermissionsSeeder`

Voegt 3 nieuwe template-roles toe (team_id=null) naast `organisation_admin`:

- `super_admin` — alle 14 permissies.
- `test1` — geen permissies.
- `test2` — geen permissies.

```php
$superTemplate = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web', 'team_id' => null]);
$superTemplate->syncPermissions(Permission::all());

Role::firstOrCreate(['name' => 'test1', 'guard_name' => 'web', 'team_id' => null]);
Role::firstOrCreate(['name' => 'test2', 'guard_name' => 'web', 'team_id' => null]);
```

De template-rows fungeren als registry van bekende role-namen. Echte assignments gebruiken per-tenant kopieën.

### 2. `OrganisationObserver::created`

Nieuwe handler bovenop de bestaande `deleted` / `restoring` handlers. Wanneer een organisatie wordt aangemaakt:

```php
public function created(Organisation $organisation): void
{
    $registrar = app(PermissionRegistrar::class);
    $previousTeamId = $registrar->getPermissionsTeamId();

    try {
        $registrar->setPermissionsTeamId($organisation->id);

        // 1. Materialize per-tenant role copies from templates
        foreach (['super_admin', 'organisation_admin', 'test1', 'test2'] as $name) {
            $template = Role::where('name', $name)->whereNull('team_id')->first();
            if (! $template) continue;

            $tenantRole = Role::firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
                'team_id' => $organisation->id,
            ]);

            $tenantRole->syncPermissions($template->permissions);
        }

        // 2. Propagate super-admin assignments to the new tenant
        User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', 'super_admin'))
            ->each(fn (User $u) => $u->assignRole('super_admin'));
    } finally {
        $registrar->setPermissionsTeamId($previousTeamId);
    }
}
```

De try/finally rond `setPermissionsTeamId` voorkomt team-id-leakage als er een exception optreedt.

### 3. `DemoOrganisationsSeeder` + `DemoUsersSeeder`

`DemoOrganisationsSeeder` blijft `Organisation::factory()->create(...)` aanroepen — dat triggert nu automatisch de observer's `created` hook, die per-tenant role-kopieën aanmaakt.

`DemoUsersSeeder` verandert de super-admin creatie:

```php
// Was: ->is_super_admin = true (column)
// Wordt: assignRole('super_admin') in alle orgs

$superAdmin = User::factory()->create([
    'first_name' => 'Super',
    'last_name' => 'Admin',
    'email' => 'super@example.local',
    'organisation_id' => null,
    ...
]);

foreach (Organisation::all() as $org) {
    $registrar->setPermissionsTeamId($org->id);
    $superAdmin->assignRole('super_admin');
}
```

De super-admin user heeft `organisation_id = null` (blijft zo) — Spatie's role-assignment per team_id beheert het lidmaatschap.

### 4. `User` model

```php
// fillable: drop 'is_super_admin'
protected $fillable = [
    'organisation_id',
    'first_name', 'middle_name', 'last_name',
    'internal_id', 'phone', 'address', 'start_date', 'end_date',
    'email', 'password', 'locale',
    'status', 'activated_at', 'activation_token', 'activation_expires_at',
    'two_factor_secret', 'two_factor_enabled_at',
];

// casts: drop 'is_super_admin' => 'boolean'

public function isSuperAdmin(): bool
{
    // Bypass team scoping by querying the relationship directly.
    // hasRole() with teams enabled scopes to current team_id.
    return $this->roles()->where('name', 'super_admin')->exists();
}
```

`isSuperAdmin()` blijft als getter behouden zodat bestaande code (`BelongsToOrganisation` trait, blade views) zonder rebrand werkt.

### 5. `AppServiceProvider`

```php
// Verwijder:
Gate::before(function (User $user) {
    return $user->isSuperAdmin() ? true : null;
});
```

Permission-checks lopen vanaf nu volledig via Spatie. De super-admin role heeft alle permissies in elke per-tenant kopie, dus elke `$user->can(...)` slaagt voor super-admins zoals voorheen — *mits de juiste `setPermissionsTeamId` actief is*.

### 6. `ResolveTenant` middleware (apex-fallback)

```php
public function handle(Request $request, Closure $next): Response
{
    $host = $request->getHost();
    $apex = config('app.apex_domain');
    $admin = config('app.admin_host');

    if ($host === $apex || $host === $admin) {
        $this->scopeForApexUser();
        return $next($request);
    }

    // ... existing tenant-resolution logic unchanged ...
}

protected function scopeForApexUser(): void
{
    $user = auth()->user();
    if (! $user) return;

    // Direct relationship query bypasses team-scoping; finds super_admin
    // role assignment in any team (super-admins have it everywhere).
    $isSuperAdmin = $user->roles()->where('name', 'super_admin')->exists();
    if (! $isSuperAdmin) return;

    // Pick the lowest org id as a deterministic anchor for permission checks.
    // Super-admin has the role in every org, so any choice grants the same
    // permission set; lowest-id is reproducible across requests.
    $firstOrg = Organisation::orderBy('id')->first();
    if ($firstOrg) {
        app(PermissionRegistrar::class)->setPermissionsTeamId($firstOrg->id);
    }
}
```

Gebruikers die op apex inloggen maar géén super-admin zijn (theoretisch — de Login component filtert daar niet op org_id, dus een org-admin met de juiste credentials kan inloggen) krijgen géén `setPermissionsTeamId`. Hun role-checks falen → ze kunnen niets doen op apex. Dat is gewenst gedrag.

### 7. `Send` Livewire component

Nieuwe property + scope-helper:

```php
#[Validate('array')]
public array $roles = [];

/**
 * @return array<string,string>  internal name => translated label
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

`send()` validation: alleen toegekende roles uit `availableRoles()` zijn toegestaan:

```php
$this->validate([
    'roles' => ['array'],
    'roles.*' => ['required', 'string', 'in:'.implode(',', array_keys($this->availableRoles()))],
]);
```

**Belangrijk:** een org-admin op apex (theoretisch) zou via spoofed payload `super_admin` kunnen kiezen. De `in:`-rule blokkeert dat — `availableRoles()` returnt geen `super_admin` voor non-super-admins.

### 8. `InvitationService::invite()`

Signature blijft hetzelfde; nieuw gedrag binnen de transactie. **Belangrijk:** we zetten expliciet `setPermissionsTeamId` op `$organisationId` voordat we roles assignen, omdat de huidige team-id (op apex bv. de fallback-eerste-org) niet noodzakelijk overeenkomt met de gekozen invite-organisatie.

```php
$registrar = app(PermissionRegistrar::class);
$previousTeamId = $registrar->getPermissionsTeamId();
$registrar->setPermissionsTeamId($organisationId);

try {
    foreach ($roles as $roleName) {
        $user->assignRole($roleName);
    }

    // Cross-tenant propagation for super_admin: assign in every OTHER org too.
    if (in_array('super_admin', $roles, true)) {
        foreach (Organisation::where('id', '!=', $organisationId)->get() as $otherOrg) {
            $registrar->setPermissionsTeamId($otherOrg->id);
            $user->assignRole('super_admin');
        }
    }
} finally {
    $registrar->setPermissionsTeamId($previousTeamId);
}
```

De try/finally garandeert dat de container's team-id terug op de oorspronkelijke waarde staat, ongeacht success of throw — anders zou een failed invite de inviter's eigen permissions verminken.

### 9. View: `send.blade.php` — role multi-select

Tussen de bestaande velden en de actie-knoppen:

```blade
<flux:checkbox.group wire:model="roles" label="{{ __('Rollen') }}">
    @foreach ($this->availableRoles() as $roleName => $roleLabel)
        <flux:checkbox value="{{ $roleName }}" label="{{ $roleLabel }}" />
    @endforeach
</flux:checkbox.group>
```

Flux 4's `checkbox.group` rendert een net keuzeveld. Geen "verplicht" rule; iemand kan worden uitgenodigd zonder rol (default empty array — toekomstige `@can`'s falen, wat betekent dat de gebruiker alleen kan zien-zonder-aanpassen).

### 10. `UserFactory` — `superAdmin()` state

```php
public function superAdmin(): static
{
    return $this->state(fn () => [
        'organisation_id' => null,
    ])->afterCreating(function (User $user) {
        $registrar = app(\Spatie\Permission\PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();

        try {
            foreach (Organisation::all() as $org) {
                $registrar->setPermissionsTeamId($org->id);
                $user->assignRole('super_admin');
            }
        } finally {
            $registrar->setPermissionsTeamId($previousTeamId);
        }
    });
}
```

`afterCreating` zorgt dat alle dan-bestaande organisaties worden gevuld met de super-admin role-assignment. Tests die geen orgs hebben krijgen een super-admin zonder team-assignments — die kunnen dan niets, wat correct is voor zo'n test.

### 11. Migratie: drop `users.is_super_admin`

Twee migraties:

**a. Data migration** (vóór de drop):

```php
// 2026_05_09_120000_migrate_super_admin_flag_to_role.php
public function up(): void
{
    $registrar = app(PermissionRegistrar::class);

    DB::table('users')
        ->where('is_super_admin', true)
        ->orderBy('id')
        ->each(function ($row) use ($registrar) {
            $user = User::find($row->id);
            if (! $user) return;

            foreach (Organisation::all() as $org) {
                $registrar->setPermissionsTeamId($org->id);
                if (! $user->hasRole('super_admin')) {
                    $user->assignRole('super_admin');
                }
            }
        });
}
```

**b. Schema migration** (drop kolom):

```php
// 2026_05_09_120100_drop_is_super_admin_from_users_table.php
Schema::table('users', function (Blueprint $table) {
    $table->dropColumn('is_super_admin');
});
```

Splitsen in twee bestanden zodat een `migrate:rollback` van de schema-migratie het type herstelt zonder de data-migratie ongedaan te maken (data-migratie heeft een leeg `down()`).

## Data flow / autorisatie

### Vóór

```
Request hits route → middleware → @can('users.delete')
  → Gate::before runs → isSuperAdmin() reads is_super_admin column
    → if true: bypass, allow
    → else: fall through to Spatie permission check
```

### Na

```
Request hits route → ResolveTenant resolves tenant OR sets apex fallback
  → setPermissionsTeamId is set (current org OR first-org-fallback for apex super-admin)
  → @can('users.delete')
    → Spatie role check: user has super_admin in current team? → all permissions granted
    → otherwise: per-permission check via role
```

## Migratie

Zie sectie 11 hierboven. Voor de bestaande lokale dev-DB (na `migrate:fresh --seed`) gebeurt automatisch het juiste:

1. Schema migrations runnen (incl. drop-kolom-migratie).
2. Seeders runnen `RolesAndPermissionsSeeder` → templates aangemaakt.
3. `DemoOrganisationsSeeder` triggert observer → per-tenant role-kopieën aangemaakt.
4. `DemoUsersSeeder` assigne't `super_admin` role aan super@example.local in elke org.

Voor productie (als die er ooit komt): de twee genoemde migraties draaien sequentieel. Data-migratie eerst, dan schema-drop.

## Tests

### Bestaande tests aanpassen

Tests die `is_super_admin` direct lezen of schrijven moeten naar de role-API. Lijst (verifieer met grep tijdens implementatie):

- `tests/Feature/Users/UserCrudTest.php` — `User::factory()->superAdmin()` blijft werken via factory-state.
- `tests/Feature/Auth/...` — login-tests met super-admin actor.
- `tests/Feature/Invitations/InviteUiTest.php` — apex super-admin tests.
- `tests/Feature/...` overige.

De grootste impact zit in tests die `User::factory()->superAdmin()->create([...])` aanroepen *zonder* dat er nog organisations bestaan. Voor die tests: óf de test maakt eerst een org en dan de super-admin (huidige patroon in InviteUiTest is correct), óf de assertion verandert in iets dat geen permissies vereist.

### Nieuwe tests

| Test | Onder welke describe |
|---|---|
| `super_admin role exists with all permissions after seed` | `RolesAndPermissionsSeeder` |
| `creating an organisation auto-creates per-tenant role copies` | `OrganisationObserver` |
| `creating an organisation auto-assigns super_admin to existing super-admins` | `OrganisationObserver` |
| `User::isSuperAdmin reflects super_admin role assignment` | User model |
| `Gate::before bypass is removed` (assert via test that without role, permissions fail) | AppServiceProvider |
| `ResolveTenant sets fallback team_id for super-admin on apex` | ResolveTenant middleware |
| `Send component shows test1/test2/organisation_admin to org-admin inviter` | Send Livewire |
| `Send component shows super_admin to super-admin inviter on apex` | Send Livewire |
| `Send component rejects spoofed super_admin role from non-super-admin` | Send Livewire |
| `InvitationService propagates super_admin role across all orgs` | InvitationService |

Doelgetallen na implementatie: minimaal +10 tests, totaal richting ~160.

## Niet-doelen / weloverwogen weglatingen

- **Geen UI om bestaande gebruikers van rol te wisselen.** `Livewire/Roles/Index` bestaat al maar wordt in deze feature niet uitgebreid.
- **Geen multi-org-admin (een gebruiker die in twee specifieke orgs admin is, maar niet super-admin).** De huidige user-model assumeert één primaire `organisation_id`; dat blijft. Gradaties voor de toekomst.
- **Geen automatische "default" role bij user-creatie.** Een gebruiker zonder rol is een geldige state (kan niets, behalve inloggen) — de invite-flow moedigt aan rollen te kiezen, maar dwingt het niet af.
- **Geen wijziging in `Login.php`'s apex-flow** — een org-admin die op apex inlogt krijgt nu géén `setPermissionsTeamId` (afhankelijk van de Login-implementatie). Dat blijft zo en werkt natuurlijk samen met de apex-fallback in ResolveTenant.

## Open punten

- **Bestaande pending invitations zonder rollen**: invitation #1 (Frank) is geactiveerd zonder rol. Hij heeft nu `organisation_id=1` maar geen Spatie role. Hij kan inloggen, niets aanvragen, niets aanpassen. We laten dat zo — hij vraagt zelf wel om een rol-promotie of de admin past het aan via een toekomstige Roles-UI.
- **Test1 en test2 hebben geen permissies.** Iemand met alleen `test1` kan inloggen maar nergens iets doen. Dat is bewust voor development-doeleinden (UI-keuzes valideren).

## Akkoord-punten (gebruiker bevestigde 2026-05-09)

1. ✅ Drop `users.is_super_admin` kolom.
2. ✅ Apex-fallback: super-admin krijgt eerste org als team_id (op id-volgorde).
3. ✅ Org-admin mag geen `super_admin` toekennen via picker.
4. ✅ Auto-propagation via `OrganisationObserver::created`.
5. ✅ Optie 1 (per-tenant role copies + auto-sync).
6. ✅ `test1` en `test2` zonder permissies.
7. ✅ Memory bijgewerkt vóór deze spec werd geschreven.
