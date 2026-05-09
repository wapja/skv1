# User Edit — Role Picker

**Datum:** 2026-05-09
**Status:** Goedgekeurd ontwerp, klaar voor implementatieplan

## Aanleiding

Sinds de spatie-roles-everywhere refactor heeft elke user (in beginsel) Spatie roles. De invite-flow zet rollen *bij uitnodiging*, maar er is geen UI om rollen achteraf te wijzigen. De enige manier om iemand van rol te veranderen is `php artisan tinker` — niet bruikbaar voor een org-admin die niet bij de server kan.

Daarnaast bestaat er nu een dubbele bron van subtiele logica: `InvitationService::invite()` bevat de cross-org `super_admin` propagation. Een tweede consumer (Edit-page) zou diezelfde dans moeten doen. DRY-extractie is nu het juiste moment.

## Doel

1. Op `Users/Edit` rolaanwijzing/-intrekking via een `<flux:checkbox.group>`, scoped op de bewerker's autoriteit (org-admin ziet `organisation_admin`, `test1`, `test2`; super-admin ziet `super_admin` daar bovenop).
2. `super_admin` werkt cross-org binary: aanvinken propageert naar alle orgs, uitvinken verwijdert in alle orgs. Reguliere rollen werken `syncRoles`-style binnen de target user's organisation.
3. Een herbruikbaar `App\Services\UserRoleSyncer` service-laagje dat zowel `Send` (invite) als `Users/Edit` consumeren. Eén bron van waarheid voor "set the user's role state to X".

## Scope

**In scope:**
- `app/Services/UserRoleSyncer.php` (nieuw) — service-laag.
- `app/Services/InvitationService.php` — refactor om `UserRoleSyncer` te gebruiken (gedrag identiek).
- `app/Livewire/Users/Edit.php` — nieuwe `roles[]` property, `availableRoles()` helper, `roles.*` validation, sync-call in `save()`.
- `resources/views/livewire/users/edit.blade.php` — `<flux:checkbox.group>` voor rollen.
- Tests in `tests/Feature/Users/UserRoleSyncerTest.php` (nieuw) en bestaande `tests/Feature/Users/UserCrudTest.php` (uitbreiding van `Users Edit Livewire` describe).

**Buiten scope:**
- Geen wijziging in `UserPolicy::update`'s "kan-niet-super-admin-bewerken" guard. Wie een ander super-admin wil demoten doet dat via `tinker` of via self-revocation door die persoon zelf.
- Geen UI-wijziging in `Roles/Index` (rollen+permissies pagina).
- Geen wijziging in `Send` invite-form (de role-picker daar blijft zoals 'ie is — zelfde `availableRoles()` helper, zelfde validation).
- Geen "lock-out preventie": een org-admin die zichzelf demote't, accepteert het lock-out-risico. Document maar handhaaf niet in code.
- Geen audit-log-uitbreiding voor role-wijzigingen (kan later).

## Architectuur

Drie nieuwe lagen:

1. **Service** (`UserRoleSyncer`) — pure imperative API. Roept `setPermissionsTeamId`, `syncRoles`, `assignRole`/`removeRole` op met de juiste team-context. Geen Livewire, geen request-state, geen authorization — die hoort bij de caller.
2. **Component** (`Users/Edit`) — eigenaar van form-state, autorisatie en validation. Roept de service aan met de juiste args.
3. **View** — extra `<flux:checkbox.group>`, hergebruikt `availableRoles()` uit de component.

`InvitationService::invite()` consumeert de service in plaats van zijn eigen inline loop — pure refactor, geen gedragswijziging.

## Componenten in detail

### 1. `App\Services\UserRoleSyncer` (nieuw)

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
            $hasSuperAdminAnywhere = $user->roles()
                ->where('name', 'super_admin')
                ->exists();

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

**Belangrijk over de `$hasSuperAdminAnywhere` check:** `roles()` is team-scoped, maar zonder team-id binding (we hebben net `setPermissionsTeamId($primaryOrganisationId)` gezet, *waarna* we eerst `syncRoles($regular)` deden). Het probleem: na `syncRoles($regular)` is de Spatie role-cache op de User instance verouderd. We moeten `$user->unsetRelation('roles')` of een fresh-state-aanpak nemen. Cleaner: directe DB-query (zoals `User::isSuperAdmin()` ook doet):

```php
// Vervang $hasSuperAdminAnywhere check met:
$hasSuperAdminAnywhere = (function () use ($user, $registrar): bool {
    $teamsEnabled = $registrar->teams;
    $registrar->teams = false;
    try {
        return $user->roles()->where('name', 'super_admin')->exists();
    } finally {
        $registrar->teams = $teamsEnabled;
    }
})();
```

Hierin disablen we Spatie's team-scoping kortstondig (mirror van `User::isSuperAdmin()`). Dat omzeilt zowel cache-staleness als team-scope-misalignment.

### 2. `App\Services\InvitationService::invite()` (refactor)

Vervang het huidige role-assignment-blok (de hele `try/finally` rond `setPermissionsTeamId` met de cross-org loop) door:

```php
app(UserRoleSyncer::class)->sync($user, $roles, $organisationId);
```

Geen gedragswijziging — alleen extractie. Bestaande InvitationService-tests blijven ongewijzigd groen.

### 3. `App\Livewire\Users\Edit.php`

Nieuwe property + helper:

```php
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

In `mount()` — laad de huidige rollen van de target user. Voor `super_admin` gebruiken we de "anywhere" check (mirror van `isSuperAdmin()`); voor reguliere rollen de huidige team-scope:

```php
public function mount(User $user): void
{
    $this->authorize('update', $user);
    $this->user = $user;
    // ... bestaande veld-prefill ...

    $registrar = app(PermissionRegistrar::class);
    $previousTeamId = $registrar->getPermissionsTeamId();

    try {
        $primaryOrgId = $user->organisation_id ?: Organisation::orderBy('id')->value('id');
        $registrar->setPermissionsTeamId($primaryOrgId);

        $current = $user->getRoleNames()->all();

        if ($user->isSuperAdmin()) {
            $current[] = 'super_admin';
        }

        $this->roles = array_values(array_unique($current));
    } finally {
        $registrar->setPermissionsTeamId($previousTeamId);
    }
}
```

In `save()`, na de bestaande validate + persist:

```php
$this->validate([
    'roles' => ['array'],
    'roles.*' => ['required', 'string', 'in:'.implode(',', array_keys($this->availableRoles()))],
]);

$primaryOrgId = $this->user->organisation_id ?: Organisation::orderBy('id')->value('id');
app(UserRoleSyncer::class)->sync($this->user, $this->roles, $primaryOrgId);
```

### 4. `resources/views/livewire/users/edit.blade.php`

Vlak boven de submit-knop:

```blade
<flux:checkbox.group wire:model="roles" label="{{ __('Rollen') }}">
    @foreach ($this->availableRoles() as $roleName => $roleLabel)
        <flux:checkbox value="{{ $roleName }}" label="{{ $roleLabel }}" />
    @endforeach
</flux:checkbox.group>
```

## Data flow

### Org-admin werkt user binnen eigen org bij

```
1. Edit-page op demo1.skv1.test → ResolveTenant binds demo1 → currentOrganisation = demo1.
2. UserPolicy::update — actor in demo1, target in demo1, has users.update → ok.
3. mount() laadt target user's huidige rollen (in demo1's team scope) → $this->roles.
4. View rendert checkboxes; super_admin niet zichtbaar.
5. Admin vinkt aan/uit → wire:model.
6. Submit → validate (in: rule) → save() roept UserRoleSyncer aan.
7. Syncer: setPermissionsTeamId(demo1.id) → syncRoles($regular) → check super_admin (skip, niet selectable) → finally restore.
```

### Super-admin werkt apex-target user bij

```
1. Edit-page op skv1.test → ResolveTenant apex-fallback zet team_id = lowest org.
2. UserPolicy::update — actor.isSuperAdmin → bypass cross-org → ok.
3. mount() — primaryOrgId = target.organisation_id (of fallback voor super-admins).
4. View rendert checkboxes incl. super_admin.
5. Toggle super_admin → submit.
6. Syncer: regular sync in primary-org → cross-org loop voor super_admin als delta dat vraagt.
```

## Error handling

- Validation fouten: Flux toont per veld onder de checkbox-group.
- 403 (UserPolicy::update returnt false): bestaande error-page.
- Service throws (DB error in cross-org loop): try/finally herstelt team-id; geen partial-write doordat de Edit's `save()` de service aanroept *na* User::update, dus user-naam-wijzigingen zijn al gepersisteerd. Voor strikte atomiciteit kunnen we de service-call én User::update in een DB::transaction wrappen — overweging voor implementatie.
- Spoof: `roles.*` `in:` rule scoped op `availableRoles()` — server-side zelfs als front-end gebypassed wordt.

## Tests

### Service: `tests/Feature/Users/UserRoleSyncerTest.php` (nieuw)

| Test | Verifieert |
|---|---|
| `assigns regular roles in primary org only` | syncRoles is team-scoped op primary org |
| `propagates super_admin to all orgs when added` | cross-org assignRole bij delta toevoegen |
| `removes super_admin from all orgs when removed` | cross-org removeRole bij delta verwijderen |
| `noop when role state matches selection` | Idempotency |
| `restores setPermissionsTeamId after exception` | try/finally guard |

### UI: `tests/Feature/Users/UserCrudTest.php` (uitbreiding)

Inside `describe('Users Edit Livewire', ...)`:

| Test | Verifieert |
|---|---|
| `shows role checkboxes scoped to org-admin authority` | availableRoles helper rendert org_admin/test1/test2 zonder super_admin |
| `shows super_admin to super-admin editor` | availableRoles heeft super_admin als super-admin in actie |
| `org-admin saves regular role assignments` | Happy path: vink test1 aan, save, check DB |
| `super-admin grants super_admin via UI propagates cross-org` | End-to-end cross-org propagation |
| `org-admin cannot spoof super_admin via roles[] payload` | `roles.*` `in:` validation rejects |
| `self-edit allows demoting own organisation_admin` | Self-update werkt; geen lock-out-guard |

Plus mechanische update van bestaande Edit-test als deze de form-init aanroept zonder `roles` te initialiseren — `mount()` zorgt dat dat soepel default'd naar de bestaande rollen.

### `InvitationServiceTest.php` (no change)

De refactor in `InvitationService` is gedragsneutraal — bestaande tests pinnen het gedrag, en moeten groen blijven na de extractie. Geen test-aanpassing nodig.

## Niet-doelen / weloverwogen weglatingen

- **Geen Livewire-Computed property voor `availableRoles()`.** Voor 3-4 rollen is dat overhead zonder winst.
- **Geen role-history of audit-trail.** Het bestaande `activitylog`-pakket logt al user-changes; role-wijzigingen erin opnemen kan een latere uitbreiding zijn.
- **Geen "demote yourself"-confirmation modal.** Self-update gewoon toegestaan — gebruiker is verantwoordelijk.
- **Geen UI om gedeeltelijke super-admin-rechten te geven** (e.g., super-admin in alleen demo1+demo2). De cross-org binary is bewust simpel; gradaties kunnen later via custom roles.
- **Geen wijziging aan `UserPolicy::update`'s super-admin-edit-blocked guard.** Cross-super-admin demotion blijft tinker-only of self-revocation.

## Open punten

- **Atomiciteit van `User::update` + `UserRoleSyncer::sync`**: zou idealiter in dezelfde transactie. Implementatieplan zal dit oppakken (waarschijnlijk een `DB::transaction(...)` rond de hele `save()`-body).

## Akkoord-punten (gebruiker bevestigde 2026-05-09)

1. ✅ Self-update toegestaan, geen lock-out-guard.
2. ✅ Role-picker op `Users/Edit` (zelfde page, niet apart).
3. ✅ super_admin als cross-org binary state.
4. ✅ syncRoles voor reguliere rollen in target-user's primary org.
5. ✅ Apex-fallback (eerste org) voor super-admin targets met organisation_id=null.
6. ✅ `UserRoleSyncer` service extraheren, gebruikt door zowel `Send` als `Users/Edit`.
7. ✅ `UserPolicy::update`'s super-admin-blocked guard ongewijzigd.
