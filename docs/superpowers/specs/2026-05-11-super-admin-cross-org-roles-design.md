# Super_admin cross-org rol-overzicht en rol-verplaatsing

**Status:** Design — wacht op review
**Datum:** 2026-05-11
**Doelgebied:** `resources/views/components/roles/⚡index`, `resources/views/components/roles/⚡edit`, tests

## 1. Doel

Drie uitbreidingen bovenop het reeds gewijzigde rol-beheer (super_admin mag templates bewerken, badge "Geen organisatie"):

1. **Cross-org overzicht** — een super_admin ziet in het rol-overzicht álle rollen van álle organisaties plus de templates, met een nieuwe **Organisatie**-kolom.
2. **Cross-org bewerken** — super_admin kan een per-org rol bewerken ongeacht het huidige subdomein.
3. **Verplaatsen tussen organisaties** — super_admin kan de aan een rol gekoppelde organisatie wijzigen, mits er geen gebruikers aan die rol gekoppeld zijn.

## 2. Niet-doelen

- **Geen template ↔ per-org switching.** De org-keuze toont enkel bestaande organisaties. Een template (team_id=NULL) blijft een template; een per-org rol blijft per-org. Dit voorkomt vragen rond auto-propagatie (`OrganisationObserver::PROPAGATED_ROLES`) en same-named per-org copies in andere orgs.
- **Geen migratie van user-role assignments.** Verplaatsen mét gekoppelde gebruikers wordt geblokkeerd; er worden géén pivots opgeruimd, gekopieerd of meegenomen.
- **Geen aparte super_admin-route of mod-paneel.** De bestaande `roles.index` en `roles.edit` worden uitgebreid; de view past zich aan op basis van `auth()->user()->isSuperAdmin()`.
- **Geen filter/zoek in de cross-org tabel.** De `roles.index` blijft een platte tabel; sortering/filtering kan later als losse iteratie.
- **Geen wijziging in `RolePolicy`.** De policy retourneert al `true` voor super_admin op iedere rol (zie commit voor "super_admin override").

## 3. Architectuur en bestandsoverzicht

### Aangepast

| Bestand | Wijziging |
| --- | --- |
| `resources/views/components/roles/⚡index/index.php` | `roles()` splitst op super_admin: cross-org query zonder tenant-filter en zonder de "verberg template als per-org copy bestaat"-clause; eager-load van `team` (de gekoppelde organisatie). |
| `resources/views/components/roles/⚡index/index.blade.php` | Nieuwe kolom **Organisatie** zichtbaar voor super_admin; cel-inhoud: org-naam of "Geen organisatie". |
| `resources/views/components/roles/⚡edit/edit.php` | Nieuwe property `organisationId` (init = `$role->team_id`); save-pad valideert/verwerkt org-wijziging. |
| `resources/views/components/roles/⚡edit/edit.blade.php` | Nieuw `<flux:select>` voor de organisatie, alleen zichtbaar voor super_admin én alleen op per-org rollen. |
| `app/Models/Role.php` | `team()` BelongsTo-relatie naar `Organisation` (lokale id-foreignkey, `team_id`), zodat de blade `$role->team->name` kan tonen zonder N+1. |
| `tests/Feature/Roles/RoleManagementTest.php` | Tests voor cross-org index-zichtbaarheid en de Organisatie-kolom. |
| `tests/Feature/Roles/RoleEditTest.php` | Tests voor save met org-wijziging, blokkade bij gekoppelde gebruikers, uniqueness-scope op target-org, en zichtbaarheid van het select-veld. |

### Bewust ongewijzigd

- `app/Policies/RolePolicy.php` — `update()` retourneert al `true` voor super_admin. De policy hoeft de team_id-wijziging niet expliciet te kennen; het is een onderdeel van het update-verzoek.
- `app/Observers/OrganisationObserver.php` en `app/Services/RoleBackfiller.php` — template-propagatie en backfill blijven onaangetast omdat templates niet meer verplaatsbaar zijn.
- `app/Http/Middleware/ResolveTenant.php` — geen wijziging; super_admin's tenant-resolutie op apex blijft hetzelfde.
- `app/Models/User.php::isSuperAdmin()` — bestaande detectie volstaat.

## 4. Datamodel

Geen schema-wijziging. De bestaande `roles.team_id` (nullable FK naar `organisations.id`) draagt nu zowel "behoort tot org" als "kan verplaatst worden". Spatie-pivot `model_has_roles` blijft ongewijzigd.

De nieuwe relatie op `App\Models\Role`:

```php
public function team()
{
    return $this->belongsTo(\App\Models\Organisation::class, 'team_id');
}
```

Naamskeuze `team()` sluit aan op Spatie's interne `team_id`-veld; tekst in de UI gebruikt "Organisatie".

## 5. Componentgedrag

### 5.1 `roles.index` query

```
if (actor is super_admin):
    return Role::with('permissions', 'team')
        ->withCount('users')
        ->orderByRaw('team_id IS NOT NULL')   // NULL-first (templates), org-rollen erna
        ->orderBy('team_id')
        ->orderBy('name')
        ->get();
else:
    [bestaande tenant-gefilterde query blijft]
```

De bestaande "verberg template-rol als er een per-org copy met dezelfde naam in deze tenant bestaat"-clause vervalt voor super_admin — die wil juist beide zien.

### 5.2 `roles.index` tabel

- Kolomvolgorde voor super_admin: `Rol | Organisatie | Permissies | Type | Acties`.
- Voor niet-super-admin: huidige kolomvolgorde (`Rol | Permissies | Type | Acties`).
- `Organisatie`-cel:
  - per-org rol → `$role->team->name`
  - template → `Geen organisatie` (zelfde label als de Type-badge, opzettelijk consistent).

### 5.3 `roles.edit` form

Nieuwe wire-property:

```php
public ?int $organisationId = null;
```

Init in `mount()`: `$this->organisationId = $role->team_id` als rol bestaat én actor super_admin is.

Veld-zichtbaarheid (in blade):

```
@if (auth()->user()?->isSuperAdmin() && $role && $role->team_id !== null)
    <flux:select wire:model="organisationId" label="Organisatie">
        @foreach ($organisations as $org)
            <flux:select.option value="{{ $org->id }}">{{ $org->name }}</flux:select.option>
        @endforeach
    </flux:select>
@endif
```

De `render()`-methode laadt `$organisations = Organisation::orderBy('name')->get()` als de actor super_admin is.

### 5.4 `roles.edit` save-logica

Pseudocode binnen `save()`, vóór `validate()`:

```
$isSuperAdmin = auth()->user()?->isSuperAdmin() ?? false;
$wantsMove = $this->role !== null
    && $isSuperAdmin
    && $this->role->team_id !== null
    && $this->organisationId !== null
    && (int) $this->organisationId !== $this->role->team_id;

$targetTeamId = $wantsMove ? (int) $this->organisationId : ($this->role?->team_id ?? tenant()?->id);
```

Validatie-aanpassingen:

- `Rule::unique('roles')->where(team_id = $targetTeamId)->ignore($this->role?->id)` — gebruikt het **target** team_id zodat uniqueness in de nieuwe org wordt gecheckt.
- Extra closure (alleen actief bij `$wantsMove`):
  ```
  if ($wantsMove) {
      $usersCount = DB::table('model_has_roles')
          ->where('role_id', $this->role->id)
          ->count();
      if ($usersCount > 0) {
          $fail(__('Verplaatsen geblokkeerd: er zijn nog gebruikers aan deze rol gekoppeld.'));
      }
  }
  ```
- `organisationId` zelf wordt apart gevalideerd: `'integer', Rule::exists('organisations', 'id')`.

Commit (binnen `DB::transaction`):

```
if ($wantsMove) {
    $this->role->update(['name' => $this->name, 'team_id' => $targetTeamId]);
} else {
    [bestaande update- of create-tak]
}
```

### 5.5 Onveranderd geval voor super_admin

Als super_admin een per-org rol opent en het org-veld niet wijzigt, gedraagt save zich identiek aan het bestaande pad: geen user-count check, geen team_id-update.

## 6. Foutmeldingen (Nederlands)

| Situatie | Foutmelding |
| --- | --- |
| Verplaatsen met users gekoppeld | "Verplaatsen geblokkeerd: er zijn nog gebruikers aan deze rol gekoppeld." |
| Naam clasht in target-org | (bestaande Laravel-melding "De naam is al in gebruik.") |
| Ongeldig org-id | (bestaande Laravel-melding `exists`) |

## 7. Edge cases

- **Super_admin op tenant-subdomein:** `tenant()` blijft de subdomein-org. De index toont nog steeds álle orgs (de query negeert tenant-scope voor super_admin). `setPermissionsTeamId` blijft onveranderd want we creëren/verplaatsen rollen direct via Eloquent zonder Spatie's static `Role::create`.
- **Super_admin bewerkt een template:** veld is verborgen; save-pad detecteert `team_id === null` → geen move, gewone update.
- **Super_admin selecteert dezelfde org als huidige:** `wantsMove === false` (gelijke ids), normale flow.
- **Reguliere org_admin:** geen kolom, geen veld; achterliggende logica activeert nooit (niet super_admin).
- **Role-naam unchanged én org-wijziging tegelijk:** uniqueness-rule gebruikt target team_id; `ignore($this->role->id)` voorkomt zelfclash; de bestaande `nameUnchanged`-shortcut blijft werken want naam veranderde niet.
- **Naam-wijziging én org-wijziging tegelijk:** beide checks lopen op het target team_id; `notIn` reserved-names en template-clash closure blijven actief omdat `nameUnchanged === false`.

## 8. Beveiliging

Server-side checks (niet steunen op view-zichtbaarheid):

- `$wantsMove` activeert alleen als `auth()->user()?->isSuperAdmin()` true is — anders genegeerd, ook al stuurt iemand `organisationId` mee in de payload.
- `RolePolicy::update` autoriseert al iedere rol voor super_admin; geen extra gate nodig.

## 9. Testdekking (TDD-volgorde)

| # | Test | Beoogd gedrag |
| --- | --- | --- |
| T1 | `roles.index` voor super_admin toont rollen uit een andere org | Cross-org query laadt rij van vreemde org-rol |
| T2 | `roles.index` voor super_admin toont kolom "Organisatie" met org-naam | UI-kolom aanwezig + ingevuld |
| T3 | `roles.index` voor super_admin toont "Geen organisatie" voor templates | Template-zichtbaarheid + label |
| T4 | `roles.index` voor org_admin toont GEEN rollen uit andere orgs | Regressiebescherming |
| T5 | Super_admin verplaatst een per-org rol zonder users → save lukt, team_id wijzigt | Happy path move |
| T6 | Super_admin probeert te verplaatsen met user gekoppeld → validatiefout + team_id ongewijzigd | Block-gedrag |
| T7 | Uniqueness gebruikt het target-org bij verplaatsing (clash met gelijknamige rol in doel-org) | Validatie-scope correct |
| T8 | Org-select is afwezig in de edit-blade voor een org_admin | UI-conditional |
| T9 | Org-select is afwezig voor super_admin op een template | UI-conditional (geen template-move) |
| T10 | `organisationId` van een non-super-admin wordt server-side genegeerd (geen privilege-escalation) | Beveiliging |

## 10. Open vragen

Geen openstaande open vragen na de brainstorm:

- Move-gedrag bij users: blokkeren — vastgelegd.
- Template ↔ per-org switching: niet toegestaan — vastgelegd.
- Cross-org scope: altijd voor super_admin — vastgelegd.
