# Invite-flow met namen en expliciete organisatie

**Datum:** 2026-05-09
**Status:** Goedgekeurd ontwerp, klaar voor implementatieplan

## Aanleiding

Het huidige invite-formulier accepteert alleen e-mailadres, taal en rollen. Naam-velden worden gefakete (`first_name = email-prefix`, `last_name = '(uit te nodigen)'`) en het `organisation_id` van de nieuwe user wordt impliciet door de `BelongsToOrganisation`-trait uit `tenant()` gehaald. Dat werkt voor regular admins op een tenant-subdomein, maar:

- De geadresseerde verschijnt in de gebruikerslijst met onzin-namen tot zij activeert (en zelfs daarna, omdat de Activate-flow alleen het wachtwoord zet).
- Een super-admin die op het apex-domein (`skv1.test`) werkt heeft géén `tenant()`-context; de huidige code zou dan een user met `organisation_id = null` aanmaken — een organisatieloze gebruiker, wat in deze multi-tenant app een bug is.

## Doel

1. De inviter moet bij elke uitnodiging `first_name` en `last_name` opgeven; `middle_name` is optioneel (Nederlandse tussenvoegsel-realiteit, en kolom is nullable in DB).
2. Op een tenant-subdomein wordt `organisation_id` automatisch ingevuld vanuit `tenant()` — onveranderbaar, zelfs als de client een andere id meestuurt.
3. Op het apex-domein (super-admin context) is de inviter verplicht om expliciet een organisatie te kiezen via een dropdown; de validatie eist dat de gekozen id bestaat.

## Scope

**In scope:**
- `app/Services/InvitationService.php` — uitgebreide signature van `invite()`
- `app/Livewire/Invitations/Send.php` — nieuwe form-properties, conditionele validatie, autorisatielogica
- `resources/views/livewire/invitations/send.blade.php` — extra inputs + conditionele org-dropdown
- Tests in `tests/Feature/Invitations/InviteUiTest.php` en `tests/Feature/Invitations/InvitationServiceTest.php`

**Buiten scope (expliciet):**
- De Activate Livewire-flow blijft ongewijzigd: de invitee zet alleen het wachtwoord; de namen die de inviter opgaf staan al goed in de DB.
- De `PendingList` en bijbehorende cancel/resend-acties veranderen niet (de service is intern gewijzigd, maar de PendingList-callsites blijven werken).
- Geen nieuwe permission `invitations.send.cross_org` — `is_super_admin` + `Gate::before` is al de universele bypass.
- Geen wijziging in invitations-mailtemplate, expiry, of activity-log.

## Architectuur

Drie componenten, in laag-volgorde:

1. **Service-laag (`InvitationService::invite()`)** — uitgebreide signature, ontvangt naam-velden en `organisation_id` expliciet. Geen tenant-detectie meer in de service.
2. **Component-laag (`Livewire/Invitations/Send`)** — bezit alle form-state, doet contextuele validatie en is verantwoordelijk voor de org-id-resolutie (tenant() ↔ input).
3. **View-laag (`send.blade.php`)** — drie naam-velden + conditionele organisatie-dropdown.

## Componenten in detail

### Service: `App\Services\InvitationService::invite()`

Nieuwe signature (named arguments aanbevolen voor leesbaarheid):

```php
public function invite(
    string $firstName,
    ?string $middleName,
    string $lastName,
    string $email,
    string $locale,
    array $roles,
    User $invitedBy,
    int $organisationId,
): Invitation
```

`User::create()`-payload binnen de transactie:

```php
$user = User::create([
    'organisation_id' => $organisationId,
    'first_name'      => $firstName,
    'middle_name'     => $middleName,
    'last_name'       => $lastName,
    'email'           => $email,
    'start_date'      => now()->toDateString(),
    'locale'          => $locale,
    'status'          => 'pending_activation',
]);
```

De `BelongsToOrganisation`-trait heeft `if (! $model->organisation_id && $tenant = ...)` als guard; doordat wij `organisation_id` expliciet vóór de save zetten, slaat de trait zijn eigen fill over.

### Component: `App\Livewire\Invitations\Send`

Nieuwe properties:

```php
#[Validate('required|string|max:255')] public string $firstName = '';
#[Validate('nullable|string|max:255')] public string $middleName = '';
#[Validate('required|string|max:255')] public string $lastName = '';
public ?int $organisationId = null;
```

`organisationId` heeft géén class-level `#[Validate]` — de regels variëren per context en worden inline gevalideerd in `send()`.

`send()`-flow:

```php
public function send(InvitationService $service): void
{
    $user = auth()->user();
    abort_unless($user?->can('invitations.send'), 403);

    // Apex-flow alleen voor super-admins. Voorkomt dat een regular admin
    // die op apex weet in te loggen een cross-tenant invite kan sturen.
    if (! tenant() && ! $user->isSuperAdmin()) {
        abort(403);
    }

    $this->validate();

    if ($tenant = tenant()) {
        $organisationId = $tenant->id;
    } else {
        $this->validate([
            'organisationId' => ['required', 'integer', 'exists:organisations,id'],
        ]);
        $organisationId = (int) $this->organisationId;
    }

    $service->invite(
        firstName:      $this->firstName,
        middleName:     $this->middleName !== '' ? $this->middleName : null,
        lastName:       $this->lastName,
        email:          $this->email,
        locale:         $this->locale,
        roles:          $this->roles,
        invitedBy:      auth()->user(),
        organisationId: $organisationId,
    );

    $this->reset(['firstName', 'middleName', 'lastName', 'email', 'roles', 'organisationId', 'open']);
    $this->dispatch('invitation-sent');
    session()->flash('status', __('Uitnodiging verzonden.'));
}
```

Helper voor de view:

```php
public function availableOrganisations(): array
{
    if (tenant() !== null) {
        return [];
    }

    if (! auth()->user()?->isSuperAdmin()) {
        return [];
    }

    return Organisation::orderBy('name')->pluck('name', 'id')->all();
}
```

`openModal()` reset alle nieuwe velden samen met de bestaande.

### View: `resources/views/livewire/invitations/send.blade.php`

Nieuwe naam-velden bovenaan (tussen modal-heading en de bestaande locale-select):

```blade
<flux:input wire:model="firstName" label="{{ __('Voornaam') }}" required autofocus />
<flux:input wire:model="middleName" label="{{ __('Tussenvoegsel') }}" />
<flux:input wire:model="lastName" label="{{ __('Achternaam') }}" required />
<flux:input wire:model="email" label="{{ __('E-mailadres') }}" type="email" required />
```

`autofocus` schuift van `email` naar `firstName`.

Conditionele org-dropdown (tussen locale en de actie-knoppen):

```blade
@if (count($this->availableOrganisations()) > 0)
    <flux:select wire:model="organisationId" label="{{ __('Organisatie') }}" required>
        <option value="">{{ __('Kies een organisatie') }}</option>
        @foreach ($this->availableOrganisations() as $id => $name)
            <option value="{{ $id }}">{{ $name }}</option>
        @endforeach
    </flux:select>
@endif
```

## Data flow

### Regular admin op tenant-subdomein (bv. `demo1.skv1.test`)

```
1. ResolveTenant middleware → currentOrganisation = demo1
2. Klik "Gebruiker uitnodigen" → openModal() reset state
3. Form-input → wire:model bindings (organisationId blijft null)
4. Submit → can('invitations.send') gate
5. validate() → naam/email/locale/roles
6. tenant() != null → organisationId = tenant()->id (input genegeerd)
7. service->invite(...) met expliciete org_id
8. User::create() — trait ziet org_id al gezet, slaat over
9. Invitation::create + Mail::queue + activity log
10. Modal sluit, status flash
```

### Super-admin op apex (`skv1.test`)

```
1. ResolveTenant middleware: host == apex → géén currentOrganisation
2. Klik "Gebruiker uitnodigen" → openModal()
3. View: availableOrganisations() levert lijst → dropdown rendert
4. Form-input incl. dropdown-keuze
5. Submit → can('invitations.send') gate
6. validate() → naam/email/locale/roles
7. tenant() == null → extra validate(['organisationId' => required|exists:organisations,id])
8. service->invite(...) met org_id uit input
9. Rest identiek aan tenant-flow
```

## Autorisatie

Vier checks, in volgorde:

| Check | Wie | Wat | Waar |
|---|---|---|---|
| Permission gate | iedereen | `invitations.send` permission | `Send::send()` regel 1 |
| Apex-flow gate | apex-context | `is_super_admin` vereist als geen tenant | `Send::send()` regel 2 |
| Validation rules | iedereen | naam/email/locale + conditioneel org_id | `Send::send()` regel 3-4 |
| Org-binding override | tenant-context | `tenant()->id` overschrijft input | `Send::send()` regel 5 |

Belangrijk:
- Zelfs als een regular admin op een tenant-subdomein een gespoofde `organisationId`-payload meestuurt, wordt die genegeerd. `tenant()->id` wint binnen tenant-context.
- Zelfs als een regular admin via apex weet in te loggen (de Login-component filtert daar niet op `organisation_id`), faalt de Apex-flow gate met 403. De org-dropdown is daarnaast óók verborgen door `availableOrganisations()` — defense-in-depth op twee lagen.

## Error handling

- **Validatie-fouten** worden door Flux/Livewire automatisch onder elk veld gerenderd.
- **403 (geen permission)** → bestaande error-page.
- **Service-throws** (bv. duplicate email) → `DB::transaction()` rolt terug; geen extra try/catch in `Send::send()`. Duplicate-email is een edge case die we niet stilletjes willen negeren.
- **Placeholder-namen verdwijnen.** Een caller die geen `last_name` meegeeft krijgt nu een PHP TypeError (string required) — goede vangrail.

## Tests

### Update bestaande callers

21 bestaande `->invite(...)` calls in `tests/Feature/Invitations/InviteUiTest.php` en `tests/Feature/Invitations/InvitationServiceTest.php` krijgen mechanisch 4 extra named arguments (`firstName`, `middleName`, `lastName`, `organisationId`).

### Nieuwe tests in `InviteUiTest.php` (binnen `describe('Send invitation Livewire component', ...)`)

| Test | Verifieert |
|---|---|
| `requires first_name and last_name` | leeg laten → `assertHasErrors(['firstName','lastName'])` |
| `accepts an empty middle_name` | only first/last gevuld → no errors + DB row heeft `middle_name = null` |
| `auto-fills organisation from tenant context` | tenant gebonden, `organisationId` niet meegestuurd → user krijgt tenant->id |
| `ignores spoofed organisationId from tenant context` | regular admin op tenant zet `organisationId = otherOrg->id` → user krijgt tenant->id, niet de gespoofde |
| `requires organisationId on apex (no tenant context)` | `app()->forgetInstance('currentOrganisation')` + super-admin actor + leeg `organisationId` → `assertHasErrors(['organisationId'])` |
| `super-admin on apex can invite into chosen org` | apex + valid org_id input → user in die org |
| `rejects unknown organisationId on apex` | apex + bogus id → `assertHasErrors(['organisationId'])` |
| `forbids non-super-admin from inviting on apex` | apex + regular admin actor → `assertStatus(403)` (apex-flow gate) |
| `hides organisation dropdown from non-super-admin on apex` | apex + regular admin actor → `availableOrganisations()` returns [] |

### Nieuwe tests in `InvitationServiceTest.php`

| Test | Verifieert |
|---|---|
| `persists provided name fields on invite` | service.invite met expliciete naam → user row heeft exacte waardes |
| `persists explicit organisation_id and bypasses tenant trait` | service.invite met org_id A binnen tenant-context van org B → user in org A |

## Niet-doelen / weloverwogen weglatingen

- Geen nieuwe permission. `invitations.send` blijft de enige; `is_super_admin` + `Gate::before` regelt de cross-org bypass.
- Geen self-invitation check op org_id in de service. Dat is component-verantwoordelijkheid; service blijft "domme creator".
- Geen wijziging in Activate-flow. Inviter-input is canoniek; invitee zet alleen wachtwoord.
- Geen UI-tweaks aan `PendingList`. Die toont al `email`; namen worden cosmetisch beter omdat de DB nu echte waardes bevat, maar dat is gratis bijwerking.

## Open punten

Geen — alle ontwerpkeuzes zijn met de gebruiker afgestemd in de brainstormsessie.
