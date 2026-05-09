# Invite-flow with Name Fields and Explicit Organisation — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extend the invitation flow so the inviter must provide first/last name (and optional middle name), and explicitly choose an organisation when on the apex domain (super-admin context); auto-fill organisation when on a tenant subdomain.

**Architecture:** Three layers change in dependency order — (1) `InvitationService::invite()` signature grows to accept name fields and a required `organisation_id`; (2) `Send` Livewire component owns form-state, contextual validation, and tenant-vs-apex authorisation; (3) `send.blade.php` adds three name inputs and a conditional organisation dropdown. Defense-in-depth ensures only super-admins can use the apex flow.

**Tech Stack:** Laravel 13 · Livewire 4 · Flux UI (incl. flux-pro) · Pest · Spatie Permissions · Pint

**Spec:** `docs/superpowers/specs/2026-05-09-invite-flow-design.md`

---

## File Structure

| File | Action | Responsibility |
|---|---|---|
| `app/Services/InvitationService.php` | Modify | Accept name fields + explicit `organisation_id`; remove placeholder names |
| `app/Livewire/Invitations/Send.php` | Modify | New form properties, contextual validation, apex-flow gate, org-id resolution, `availableOrganisations()` helper |
| `resources/views/livewire/invitations/send.blade.php` | Modify | Three name inputs (autofocus on firstName) + conditional `<flux:select>` for organisation |
| `tests/Feature/Invitations/InvitationServiceTest.php` | Modify | Migrate 12 existing `invite()` callsites to new signature; add 2 new tests |
| `tests/Feature/Invitations/InviteUiTest.php` | Modify | Migrate 9 existing `invite()` callsites; add 9 new component tests |

No new files. No migrations. No new permissions.

---

## Task 1: InvitationService signature change + first new test

**Goal:** Drive the new service signature with a TDD red→green for `persists provided name fields on invite`. After this task, `InvitationServiceTest` is fully migrated to the new signature and passes.

**Files:**
- Modify: `app/Services/InvitationService.php` (the `invite()` method)
- Modify: `tests/Feature/Invitations/InvitationServiceTest.php` (12 callsites + 1 new test)

- [ ] **Step 1: Add the new failing test at the bottom of `InvitationServiceTest.php`**

Append this `it()` block after the existing `purgeExpired` test (after line 174):

```php
it('persists provided name fields on invite', function () {
    Mail::fake();

    $invitation = app(InvitationService::class)->invite(
        firstName:      'Jan',
        middleName:     'van der',
        lastName:       'Berg',
        email:          'jvdb@demo1.local',
        locale:         'nl',
        roles:          [],
        invitedBy:      $this->actor,
        organisationId: $this->org->id,
    );

    expect($invitation->user->first_name)->toBe('Jan')
        ->and($invitation->user->middle_name)->toBe('van der')
        ->and($invitation->user->last_name)->toBe('Berg')
        ->and($invitation->user->email)->toBe('jvdb@demo1.local')
        ->and($invitation->user->organisation_id)->toBe($this->org->id);
});
```

- [ ] **Step 2: Run the new test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Invitations/InvitationServiceTest.php --filter="persists provided name fields"`
Expected: FAIL — "unknown named parameter $firstName" (or similar — the existing positional signature doesn't accept these).

- [ ] **Step 3: Update `InvitationService::invite()` signature and payload**

Replace lines 18–28 of `app/Services/InvitationService.php` (the method signature and the `User::create()` payload):

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
    ): Invitation {
        return DB::transaction(function () use ($firstName, $middleName, $lastName, $email, $locale, $roles, $invitedBy, $organisationId) {
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

Leave the rest of the method (`foreach ($roles ...)` through `return $invitation->fresh(['user']);`) unchanged.

- [ ] **Step 4: Migrate the 12 existing `invite()` callsites in `InvitationServiceTest.php`**

Each existing call has the form `->invite('email@…', 'nl', [], $this->actor)`. Convert each to the new signature using named arguments. Use the local-part of the email as the first name; `null` for middle name; `'(test)'` for last name; reuse `$this->org->id` as `organisationId`.

For example, line 38–43 becomes:

```php
    $invitation = app(InvitationService::class)->invite(
        firstName:      'newhire',
        middleName:     null,
        lastName:       '(test)',
        email:          'newhire@demo1.local',
        locale:         'nl',
        roles:          ['organisation_admin'],
        invitedBy:      $this->actor,
        organisationId: $this->org->id,
    );
```

Apply this pattern to all 12 calls in the file:

| Line (approx) | email | roles |
|---|---|---|
| 38–43 | newhire@demo1.local | ['organisation_admin'] |
| 64 | x@demo1.local | [] |
| 82 | y@demo1.local | [] |
| 92 | z@demo1.local | [] |
| 102 | cancel@demo1.local | [] |
| 112 | twofa@demo1.local | [] |
| 127 | remind@demo1.local | [] |
| 139 | done@demo1.local | [] |
| 149 | drop@demo1.local | [] |
| 160 | kept@demo1.local | [] |
| 161 | old@demo1.local | [] |
| 162 | done@demo1.local | [] |

Each becomes a multi-line named-arg call following the template above.

Also update the existing assertion in the first test (line 46–52) — the original asserts `$invitation->user->email` etc. without first_name. The migration adds named-args but doesn't change the assertions; they should still pass.

- [ ] **Step 5: Run full `InvitationServiceTest.php`**

Run: `./vendor/bin/pest tests/Feature/Invitations/InvitationServiceTest.php`
Expected: PASS — all 11 tests including the new `persists provided name fields on invite`.

- [ ] **Step 6: Run Pint on touched files**

Run: `./vendor/bin/pint app/Services/InvitationService.php tests/Feature/Invitations/InvitationServiceTest.php`
Expected: `passed` (or auto-fixes; run again to confirm clean).

- [ ] **Step 7: Commit**

```bash
git add app/Services/InvitationService.php tests/Feature/Invitations/InvitationServiceTest.php
git commit -m "$(cat <<'EOF'
feat(invitations): require name fields and explicit organisation_id in service

InvitationService::invite() now accepts firstName/middleName/lastName
and a mandatory organisationId, replacing the placeholder values that
relied on the BelongsToOrganisation trait. All service-layer tests
migrated to the new named-argument signature.
EOF
)"
```

---

## Task 2: Service test for explicit organisation_id bypassing tenant trait

**Goal:** Lock in the behaviour where a caller-provided `organisation_id` always wins over the trait's tenant-context fallback.

**Files:**
- Modify: `tests/Feature/Invitations/InvitationServiceTest.php`

- [ ] **Step 1: Append the new test**

After the test added in Task 1, append:

```php
it('persists explicit organisation_id and bypasses tenant trait', function () {
    Mail::fake();

    $otherOrg = Organisation::factory()->create(['slug' => 'demo-other']);

    // beforeEach binds currentOrganisation = demo1, but we pass demo-other's id explicitly
    $invitation = app(InvitationService::class)->invite(
        firstName:      'Cross',
        middleName:     null,
        lastName:       'Org',
        email:          'cross@demo-other.local',
        locale:         'nl',
        roles:          [],
        invitedBy:      $this->actor,
        organisationId: $otherOrg->id,
    );

    expect($invitation->user->organisation_id)->toBe($otherOrg->id)
        ->and($invitation->user->organisation_id)->not->toBe($this->org->id);
});
```

- [ ] **Step 2: Run the new test**

Run: `./vendor/bin/pest tests/Feature/Invitations/InvitationServiceTest.php --filter="persists explicit organisation_id"`
Expected: PASS — Task 1's payload writes `organisation_id` before the trait runs, so the trait's `if (! $model->organisation_id ...)` guard skips.

- [ ] **Step 3: Pint**

Run: `./vendor/bin/pint tests/Feature/Invitations/InvitationServiceTest.php`
Expected: `passed`.

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/Invitations/InvitationServiceTest.php
git commit -m "$(cat <<'EOF'
test(invitations): assert explicit organisation_id bypasses tenant trait

Pins down the contract that InvitationService::invite() honours the
organisation_id argument even when the BelongsToOrganisation trait
would otherwise auto-fill from currentOrganisation.
EOF
)"
```

---

## Task 3: Send Livewire component — properties, validation, apex gate, org resolution

**Goal:** Replace the contents of the Send component to handle the new flow. View still renders the old form fields after this task — that's fixed in Task 4. Existing UI tests will fail mid-task and pass again after Task 5.

**Files:**
- Modify: `app/Livewire/Invitations/Send.php`

- [ ] **Step 1: Replace `app/Livewire/Invitations/Send.php` integrally**

```php
<?php

namespace App\Livewire\Invitations;

use App\Models\Organisation;
use App\Services\InvitationService;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Send extends Component
{
    public bool $open = false;

    #[Validate('required|string|max:255')]
    public string $firstName = '';

    #[Validate('nullable|string|max:255')]
    public string $middleName = '';

    #[Validate('required|string|max:255')]
    public string $lastName = '';

    #[Validate('required|email')]
    public string $email = '';

    #[Validate('required|in:nl,en')]
    public string $locale = 'nl';

    #[Validate('array')]
    public array $roles = [];

    public ?int $organisationId = null;

    #[On('open-invite-modal')]
    public function openModal(): void
    {
        $this->reset(['firstName', 'middleName', 'lastName', 'email', 'roles', 'organisationId']);
        $this->open = true;
    }

    /**
     * @return array<int,string>  id => name
     */
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

    public function send(InvitationService $service): void
    {
        $user = auth()->user();
        abort_unless($user?->can('invitations.send'), 403);

        // Apex-flow is super-admin-only. A regular admin who somehow
        // authenticated on the apex host must not be able to invite
        // into an arbitrary organisation.
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
            invitedBy:      $user,
            organisationId: $organisationId,
        );

        $this->reset(['firstName', 'middleName', 'lastName', 'email', 'roles', 'organisationId', 'open']);
        $this->dispatch('invitation-sent');
        session()->flash('status', __('Uitnodiging verzonden.'));
    }

    public function render()
    {
        return view('livewire.invitations.send');
    }
}
```

- [ ] **Step 2: Pint on the component**

Run: `./vendor/bin/pint app/Livewire/Invitations/Send.php`
Expected: `passed`.

- [ ] **Step 3: Do not commit yet** — the view still references old field names. Continue to Task 4.

---

## Task 4: Send blade view — three name inputs + conditional organisation dropdown

**Goal:** Render the new form fields and the conditional organisation dropdown.

**Files:**
- Modify: `resources/views/livewire/invitations/send.blade.php`

- [ ] **Step 1: Replace `resources/views/livewire/invitations/send.blade.php` integrally**

```blade
<div>
    <flux:button variant="primary" icon="paper-airplane" wire:click="$set('open', true)">
        {{ __('Gebruiker uitnodigen') }}
    </flux:button>

    <flux:modal wire:model.self="open" class="md:w-96">
        <form wire:submit="send" class="space-y-4">
            <div>
                <flux:heading size="lg">{{ __('Gebruiker uitnodigen') }}</flux:heading>
                <flux:text class="mt-2 text-zinc-500 dark:text-zinc-400">
                    {{ __('De ontvanger krijgt een e-mail met een activatielink.') }}
                </flux:text>
            </div>

            <flux:input
                wire:model="firstName"
                label="{{ __('Voornaam') }}"
                required
                autofocus />

            <flux:input
                wire:model="middleName"
                label="{{ __('Tussenvoegsel') }}" />

            <flux:input
                wire:model="lastName"
                label="{{ __('Achternaam') }}"
                required />

            <flux:input
                wire:model="email"
                label="{{ __('E-mailadres') }}"
                type="email"
                required />

            <flux:select wire:model="locale" label="{{ __('Taal') }}">
                <option value="nl">{{ __('Nederlands') }}</option>
                <option value="en">{{ __('Engels') }}</option>
            </flux:select>

            @if (count($this->availableOrganisations()) > 0)
                <flux:select wire:model="organisationId" label="{{ __('Organisatie') }}" required>
                    <option value="">{{ __('Kies een organisatie') }}</option>
                    @foreach ($this->availableOrganisations() as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </flux:select>
            @endif

            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="ghost" wire:click="$set('open', false)">
                    {{ __('Annuleren') }}
                </flux:button>
                <flux:button type="submit" variant="primary">
                    {{ __('Versturen') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
```

- [ ] **Step 2: Do not commit yet** — UI tests still call old positional `invite()` and old property names. Continue to Task 5.

---

## Task 5: Migrate existing UI tests to new signature

**Goal:** All 9 existing `invite()` callsites in `InviteUiTest.php` move to the new named-argument signature; the existing `Send`-component test that sets `email` is updated to also set `firstName` + `lastName`.

**Files:**
- Modify: `tests/Feature/Invitations/InviteUiTest.php`

- [ ] **Step 1: Update the first existing Send test (line 38–51) to set the new properties**

Replace the body of `it('queues the invitation when org_admin submits a valid email', ...)` with:

```php
it('queues the invitation when org_admin submits a valid email', function () {
    Mail::fake();
    $this->actingAs($this->actor);

    Livewire::test(Send::class)
        ->set('firstName', 'New')
        ->set('lastName', 'Hire')
        ->set('email', 'newhire@demo1.local')
        ->set('locale', 'nl')
        ->set('roles', ['organisation_admin'])
        ->call('send')
        ->assertHasNoErrors();

    Mail::assertQueued(InvitationMail::class);
    expect(Invitation::query()->whereHas('user', fn ($q) => $q->where('email', 'newhire@demo1.local'))->exists())->toBeTrue();
});
```

- [ ] **Step 2: Update the `rejects invalid emails` test (line 53–60) to provide names**

Replace its body with:

```php
it('rejects invalid emails', function () {
    $this->actingAs($this->actor);

    Livewire::test(Send::class)
        ->set('firstName', 'A')
        ->set('lastName', 'B')
        ->set('email', 'not-an-email')
        ->call('send')
        ->assertHasErrors(['email']);
});
```

- [ ] **Step 3: Update the `forbids users without invitations.send permission` test (line 62–70)**

Replace its body with:

```php
it('forbids users without invitations.send permission', function () {
    $this->actor->removeRole('organisation_admin');
    $this->actingAs($this->actor);

    Livewire::test(Send::class)
        ->set('firstName', 'X')
        ->set('lastName', 'Y')
        ->set('email', 'x@demo1.local')
        ->call('send')
        ->assertStatus(403);
});
```

- [ ] **Step 4: Migrate all 6 remaining `app(InvitationService::class)->invite(...)` callsites**

These appear in `PendingList Livewire component` (lines ~77, 83, 99, 113) and `Activate Livewire component` (lines ~129, 141, 156, 168, 179) describes. Each is a positional call that needs converting to named-args, e.g. line 77:

```php
$localInv = app(InvitationService::class)->invite(
    firstName:      'Local',
    middleName:     null,
    lastName:       'User',
    email:          'local@demo1.local',
    locale:         'nl',
    roles:          [],
    invitedBy:      $this->actor,
    organisationId: $this->org->id,
);
```

Apply the same pattern to each remaining call. The `$otherActor` invitation on line 83 should use `$other->id` as `organisationId` (the demo2 org). Use plausible names (`Local`, `Other`, `Cancel`, `Remind`, `Activate`, `Go`, `Twice`, `Mismatch`, `Unsigned` for first names).

- [ ] **Step 5: Run the full `InviteUiTest.php`**

Run: `./vendor/bin/pest tests/Feature/Invitations/InviteUiTest.php`
Expected: PASS — all existing tests now compatible with the new signature.

- [ ] **Step 6: Pint**

Run: `./vendor/bin/pint app/Livewire/Invitations/Send.php resources/views/livewire/invitations/send.blade.php tests/Feature/Invitations/InviteUiTest.php`
(Pint ignores blade; only PHP is checked. That's fine.)
Expected: `passed`.

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/Invitations/Send.php resources/views/livewire/invitations/send.blade.php tests/Feature/Invitations/InviteUiTest.php
git commit -m "$(cat <<'EOF'
feat(invitations): name fields and conditional organisation dropdown in Send

Send Livewire component now requires firstName/lastName, accepts an
optional middleName, and resolves organisation_id from tenant context
or — for super-admins on apex — from a required dropdown selection.
Defense-in-depth gate refuses non-super-admin actors on apex.
EOF
)"
```

---

## Task 6: New UI tests — name field validation (4 tests)

**Goal:** Lock down the new validation rules for name fields in the `Send` component.

**Files:**
- Modify: `tests/Feature/Invitations/InviteUiTest.php`

- [ ] **Step 1: Add `requires first_name and last_name` test inside `describe('Send invitation Livewire component', ...)`**

Add directly after the `forbids users without invitations.send permission` test:

```php
it('requires first_name and last_name', function () {
    $this->actingAs($this->actor);

    Livewire::test(Send::class)
        ->set('firstName', '')
        ->set('lastName', '')
        ->set('email', 'someone@demo1.local')
        ->call('send')
        ->assertHasErrors(['firstName', 'lastName']);
});
```

- [ ] **Step 2: Add `accepts an empty middle_name` test**

```php
it('accepts an empty middle_name', function () {
    Mail::fake();
    $this->actingAs($this->actor);

    Livewire::test(Send::class)
        ->set('firstName', 'Solo')
        ->set('middleName', '')
        ->set('lastName', 'Name')
        ->set('email', 'solo@demo1.local')
        ->call('send')
        ->assertHasNoErrors();

    $created = User::where('email', 'solo@demo1.local')->first();
    expect($created)->not->toBeNull()
        ->and($created->middle_name)->toBeNull();
});
```

- [ ] **Step 3: Add `auto-fills organisation from tenant context` test**

```php
it('auto-fills organisation from tenant context', function () {
    Mail::fake();
    $this->actingAs($this->actor);

    Livewire::test(Send::class)
        ->set('firstName', 'Auto')
        ->set('lastName', 'Tenant')
        ->set('email', 'auto@demo1.local')
        ->call('send')
        ->assertHasNoErrors();

    $created = User::where('email', 'auto@demo1.local')->first();
    expect($created->organisation_id)->toBe($this->org->id);
});
```

- [ ] **Step 4: Add `ignores spoofed organisationId from tenant context` test**

```php
it('ignores spoofed organisationId from tenant context', function () {
    Mail::fake();
    $other = Organisation::factory()->create(['slug' => 'demo-spoof']);
    $this->actingAs($this->actor);

    Livewire::test(Send::class)
        ->set('firstName', 'Spoof')
        ->set('lastName', 'Attempt')
        ->set('email', 'spoof@demo1.local')
        ->set('organisationId', $other->id)
        ->call('send')
        ->assertHasNoErrors();

    $created = User::where('email', 'spoof@demo1.local')->first();
    expect($created->organisation_id)->toBe($this->org->id)
        ->and($created->organisation_id)->not->toBe($other->id);
});
```

- [ ] **Step 5: Run the four new tests**

Run: `./vendor/bin/pest tests/Feature/Invitations/InviteUiTest.php`
Expected: PASS — all UI tests including the four new ones.

- [ ] **Step 6: Pint + commit**

```bash
./vendor/bin/pint tests/Feature/Invitations/InviteUiTest.php
git add tests/Feature/Invitations/InviteUiTest.php
git commit -m "$(cat <<'EOF'
test(invitations): cover name validation and tenant org auto-fill

Adds four feature tests: required first_name/last_name, optional
middle_name persisted as null, organisation_id auto-fills from
tenant() in tenant context, and a spoofed organisationId payload is
ignored within tenant context.
EOF
)"
```

---

## Task 7: New UI tests — apex / super-admin org-selection (5 tests)

**Goal:** Cover the apex-flow paths: super-admin can choose an org, regular admins are 403'd, dropdown is hidden for non-super-admin, validation rejects unknown ids.

**Files:**
- Modify: `tests/Feature/Invitations/InviteUiTest.php`

**Setup note:** these tests run in apex context. They `app()->forgetInstance('currentOrganisation')` (and reset the permissions team if needed) and use a freshly-created super-admin actor. Because the `BelongsToOrganisation` global scope bypasses for super-admins, queries inside the tests still work.

- [ ] **Step 1: Add `super-admin on apex can invite into chosen org` test**

```php
it('super-admin on apex can invite into chosen org', function () {
    Mail::fake();

    $target = Organisation::factory()->create(['slug' => 'apex-target']);

    // Drop tenant context (simulate apex host)
    app()->forgetInstance('currentOrganisation');

    $superAdmin = User::factory()->superAdmin()->create([
        'email' => 'super@example.local',
        'password' => Hash::make('Password123!'),
        'status' => 'active',
        'organisation_id' => null,
    ]);

    $this->actingAs($superAdmin);

    Livewire::test(Send::class)
        ->set('firstName', 'Apex')
        ->set('lastName', 'Invite')
        ->set('email', 'apex@apex-target.local')
        ->set('organisationId', $target->id)
        ->call('send')
        ->assertHasNoErrors();

    $created = User::withoutTenantScope()->where('email', 'apex@apex-target.local')->first();
    expect($created)->not->toBeNull()
        ->and($created->organisation_id)->toBe($target->id);
});
```

- [ ] **Step 2: Add `requires organisationId on apex (no tenant context)` test**

```php
it('requires organisationId on apex (no tenant context)', function () {
    app()->forgetInstance('currentOrganisation');

    $superAdmin = User::factory()->superAdmin()->create([
        'email' => 'super2@example.local',
        'password' => Hash::make('Password123!'),
        'status' => 'active',
        'organisation_id' => null,
    ]);

    $this->actingAs($superAdmin);

    Livewire::test(Send::class)
        ->set('firstName', 'Missing')
        ->set('lastName', 'Org')
        ->set('email', 'missing@apex.local')
        // no organisationId set
        ->call('send')
        ->assertHasErrors(['organisationId']);
});
```

- [ ] **Step 3: Add `rejects unknown organisationId on apex` test**

```php
it('rejects unknown organisationId on apex', function () {
    app()->forgetInstance('currentOrganisation');

    $superAdmin = User::factory()->superAdmin()->create([
        'email' => 'super3@example.local',
        'password' => Hash::make('Password123!'),
        'status' => 'active',
        'organisation_id' => null,
    ]);

    $this->actingAs($superAdmin);

    Livewire::test(Send::class)
        ->set('firstName', 'Bogus')
        ->set('lastName', 'Org')
        ->set('email', 'bogus@apex.local')
        ->set('organisationId', 999999)
        ->call('send')
        ->assertHasErrors(['organisationId']);
});
```

- [ ] **Step 4: Add `forbids non-super-admin from inviting on apex` test**

```php
it('forbids non-super-admin from inviting on apex', function () {
    // Regular admin actor from beforeEach. Drop tenant context (apex).
    app()->forgetInstance('currentOrganisation');

    $this->actingAs($this->actor);

    Livewire::test(Send::class)
        ->set('firstName', 'Sneaky')
        ->set('lastName', 'Admin')
        ->set('email', 'sneaky@apex.local')
        ->set('organisationId', $this->org->id)
        ->call('send')
        ->assertStatus(403);
});
```

- [ ] **Step 5: Add `hides organisation dropdown from non-super-admin on apex` test**

```php
it('hides organisation dropdown from non-super-admin on apex', function () {
    app()->forgetInstance('currentOrganisation');
    $this->actingAs($this->actor);

    $component = Livewire::test(Send::class);

    expect($component->instance()->availableOrganisations())->toBe([]);
});
```

- [ ] **Step 6: Run all new + existing tests**

Run: `./vendor/bin/pest tests/Feature/Invitations/InviteUiTest.php`
Expected: PASS — full file green (existing + all 9 new component tests).

- [ ] **Step 7: Pint + commit**

```bash
./vendor/bin/pint tests/Feature/Invitations/InviteUiTest.php
git add tests/Feature/Invitations/InviteUiTest.php
git commit -m "$(cat <<'EOF'
test(invitations): cover apex super-admin flow and defense-in-depth

Adds five feature tests for the apex-context invite flow: super-admin
can choose an organisation, organisationId is required and validated,
non-super-admins are 403'd by the apex-flow gate, and the organisation
dropdown is hidden for non-super-admins on apex.
EOF
)"
```

---

## Task 8: Full-suite verification

**Goal:** Confirm the whole project still passes — no regressions in unrelated tests.

- [ ] **Step 1: Run the full Pest suite**

Run: `./vendor/bin/pest`
Expected: PASS — all tests green. (Pre-change baseline was 137 tests; this PR adds 11 new tests, so target is 148.)

- [ ] **Step 2: If any unrelated tests fail, stop and report**

Do NOT silently fix unrelated failures. Report what failed and why before deciding how to handle.

- [ ] **Step 3: No additional commit needed if step 1 passes**

If a Pint touch-up was missed in earlier tasks, run `./vendor/bin/pint` once more on the touched files and commit any formatting fixes as a separate `style:` commit.

---

## Self-Review (post-write checklist)

**Spec coverage:**
- [x] Service signature accepts firstName/middleName/lastName + organisation_id → Task 1
- [x] Service uses provided organisation_id, bypasses trait → Task 1 + Task 2
- [x] Send component has firstName/middleName/lastName properties with required/nullable validation → Task 3
- [x] Send component auto-fills organisation_id from tenant() → Task 3 + Task 6
- [x] Send component requires organisationId from input on apex → Task 3 + Task 7
- [x] availableOrganisations() helper hidden from non-super-admin → Task 3 + Task 7
- [x] Apex-flow gate (defense-in-depth) → Task 3 + Task 7
- [x] View renders three name inputs → Task 4
- [x] View conditionally renders organisation dropdown → Task 4
- [x] Existing service tests migrated to new signature → Task 1
- [x] Existing UI tests migrated to new signature → Task 5
- [x] All 11 new tests written → Tasks 2, 6, 7
- [x] Full-suite green → Task 8

No spec gaps detected.

**Placeholder scan:** No TBD/TODO/"add validation"/"similar to" placeholders. All steps include either runnable code or explicit commands with expected output.

**Type consistency:** `firstName` / `middleName` / `lastName` / `organisationId` consistent across component, view, service signature, and tests. `availableOrganisations()` consistent across component and view.

Plan ready for execution.
