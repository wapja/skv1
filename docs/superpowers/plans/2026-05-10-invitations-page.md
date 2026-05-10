# Uitgenodigde gebruikers — pagina implementatieplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bouw `/admin/invitations` met `App\Livewire\Invitations\Index` — filter/sort/paginatie/kolommen-dropdown mirror van `Users\Index`. Toont alle Invitation-records met afgeleide status (pending/accepted/expired/cancelled). Verwijder embedded `PendingList` van users-pagina; voeg knop "Uitgenodigde gebruikers" toe vóór de invite-knop.

**Architecture:** Eén Livewire-component met `WithPagination`-trait, `#[Session]`-state. Filters via `whereHas('user'/'inviter', fn ILIKE)` voor relatie-kolommen, `match`-arms voor status (4 derivation-takken). Sort via `orderBy(subquery)` voor relatie-kolommen, `orderByRaw(CASE)` voor status. View structureel kopie van `users/index.blade.php` met aangepaste kolomrendering en alleen-pending-acties.

**Tech Stack:** Laravel 13, Livewire 4, Flux UI Pro, PostgreSQL 16+ (ILIKE + CASE met subquery), Pest 4.

**Spec:** `docs/superpowers/specs/2026-05-10-invitations-page-design.md`

---

## File Structure

| Pad | Actie | Verantwoordelijkheid |
|---|---|---|
| `app/Livewire/Invitations/Index.php` | Create | Component met staat + filter/sort/paginatie/cancel/resend |
| `resources/views/livewire/invitations/index.blade.php` | Create | Page-view |
| `resources/views/livewire/invitations/partials/column-filter.blade.php` | Create | Type-dispatch filter-input |
| `routes/web.php` | Modify | Route `/admin/invitations` toevoegen |
| `resources/views/livewire/users/index.blade.php` | Modify | Knop "Uitgenodigde gebruikers" toevoegen + `<livewire:invitations.pending-list />` verwijderen |
| `tests/Feature/Invitations/IndexTest.php` | Create | 21 nieuwe tests |
| `tests/Feature/Invitations/InviteUiTest.php` | Modify | PendingList-specifieke tests verwijderen |

---

## Pre-flight

- [ ] **Step 0a: Confirm baseline.**
  ```bash
  ./vendor/bin/pest
  ```
  Expected: 233 passed.

- [ ] **Step 0b: Inspect referenced files** zodat je weet hoe ze eruit zien:
  - `app/Livewire/Users/Index.php` (komt vaak als template)
  - `app/Models/Invitation.php` (model relations)
  - `app/Services/InvitationService.php::cancel()` en `::resendReminder()`
  - `app/Livewire/Invitations/PendingList.php` (cancel/resend handlers verhuizen)
  - `tests/Feature/Invitations/InviteUiTest.php` (welke tests blijven, welke gaan)

  Niet bewerken.

---

## Task 1: Route + component-skeleton + sort 3-state

**Files:**
- Create: `app/Livewire/Invitations/Index.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/Invitations/IndexTest.php`

- [ ] **Step 1: Maak `tests/Feature/Invitations/IndexTest.php` met `beforeEach()` en de eerste 4 sort-tests.**

  ```php
  <?php

  use App\Livewire\Invitations\Index;
  use App\Models\Invitation;
  use App\Models\Organisation;
  use App\Models\User;
  use Database\Seeders\RolesAndPermissionsSeeder;
  use Livewire\Livewire;
  use Spatie\Permission\PermissionRegistrar;

  beforeEach(function () {
      config(['app.apex_domain' => 'skv1.test']);
      $this->seed(RolesAndPermissionsSeeder::class);

      $this->org = Organisation::factory()->create(['slug' => 'demo1']);
      app()->instance('currentOrganisation', $this->org);
      app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);

      $this->actor = User::factory()->for($this->org)->create([
          'first_name' => 'Admin',
          'last_name' => 'Een',
          'email' => 'admin@demo1.local',
      ]);
      $this->actor->assignRole('organisation_admin');
  });

  describe('Invitations Index Livewire', function () {
      it('defaults to created_at desc when no sort is selected', function () {
          $invited1 = User::factory()->for($this->org)->create();
          $invited2 = User::factory()->for($this->org)->create();
          Invitation::factory()->create(['user_id' => $invited1->id, 'invited_by' => $this->actor->id, 'created_at' => now()->subDays(2)]);
          Invitation::factory()->create(['user_id' => $invited2->id, 'invited_by' => $this->actor->id, 'created_at' => now()->subHour()]);

          $this->actingAs($this->actor);

          Livewire::test(Index::class)
              ->assertSet('sortColumn', null)
              ->assertViewHas('invitations', fn ($invs) => $invs->getCollection()->first()->user_id === $invited2->id);
      });

      it('sorts by sent_at asc, then desc, then back to default', function () {
          $invited1 = User::factory()->for($this->org)->create();
          Invitation::factory()->create(['user_id' => $invited1->id, 'invited_by' => $this->actor->id]);

          $this->actingAs($this->actor);

          Livewire::test(Index::class)
              ->call('sort', 'sent_at')
              ->assertSet('sortColumn', 'sent_at')->assertSet('sortDirection', 'asc')
              ->call('sort', 'sent_at')
              ->assertSet('sortColumn', 'sent_at')->assertSet('sortDirection', 'desc')
              ->call('sort', 'sent_at')
              ->assertSet('sortColumn', null);
      });

      it('clamps an unknown sortColumn back to null', function () {
          $this->actingAs($this->actor);
          Livewire::test(Index::class)
              ->set('sortColumn', 'bogus_field')
              ->assertSet('sortColumn', null);
      });

      it('forbids users without invitations.send permission', function () {
          $regular = User::factory()->for($this->org)->create();
          $this->actingAs($regular);
          Livewire::test(Index::class)->assertStatus(403);
      });
  });
  ```

- [ ] **Step 2: Run om te zien dat ze falen.**
  ```bash
  ./vendor/bin/pest tests/Feature/Invitations/IndexTest.php
  ```
  Expected: errors — `App\Livewire\Invitations\Index` bestaat niet.

- [ ] **Step 3: Maak `app/Livewire/Invitations/Index.php` met skeleton + sort.**

  ```php
  <?php

  namespace App\Livewire\Invitations;

  use App\Models\Invitation;
  use App\Models\User;
  use Illuminate\Database\Eloquent\Builder;
  use Livewire\Attributes\Layout;
  use Livewire\Attributes\Session;
  use Livewire\Attributes\Title;
  use Livewire\Component;
  use Livewire\WithPagination;

  class Index extends Component
  {
      use WithPagination;

      public const PER_PAGE_OPTIONS = [5, 10, 25, 50, 100];

      public const SORTABLE = [
          'email', 'name', 'status', 'inviter', 'expires_at', 'sent_at',
      ];

      public const STATUSES = ['pending', 'accepted', 'expired', 'cancelled'];

      public const DEFAULT_FILTERS = [
          'email' => '', 'name' => '', 'status' => '',
          'inviter' => '', 'expires_at' => '', 'sent_at' => '',
      ];

      /** @var array<string,string> */
      #[Session]
      public array $filters = self::DEFAULT_FILTERS;

      #[Session]
      public ?string $sortColumn = null;

      #[Session]
      public string $sortDirection = 'asc';

      #[Session]
      public int $perPage = 10;

      /** @var array<int,string> */
      #[Session]
      public array $selectedColumns = ['email', 'name', 'status'];

      /** @return array<string,string> */
      public function availableColumns(): array
      {
          return [
              'email'      => __('E-mailadres'),
              'name'       => __('Naam'),
              'status'     => __('Status'),
              'inviter'    => __('Uitgenodigd door'),
              'expires_at' => __('Verloopt op'),
              'sent_at'    => __('Verzonden op'),
          ];
      }

      public function sort(string $column): void
      {
          if (! in_array($column, self::SORTABLE, true)) {
              return;
          }
          if ($this->sortColumn !== $column) {
              $this->sortColumn = $column;
              $this->sortDirection = 'asc';
          } elseif ($this->sortDirection === 'asc') {
              $this->sortDirection = 'desc';
          } else {
              $this->sortColumn = null;
              $this->sortDirection = 'asc';
          }
          $this->resetPage();
      }

      public function updatedSortColumn(): void
      {
          if ($this->sortColumn !== null && ! in_array($this->sortColumn, self::SORTABLE, true)) {
              $this->sortColumn = null;
              $this->sortDirection = 'asc';
          }
      }

      public function updatedPerPage(): void
      {
          if (! in_array($this->perPage, self::PER_PAGE_OPTIONS, true)) {
              $this->perPage = 10;
          }
          $this->resetPage();
      }

      public function invitations()
      {
          $query = Invitation::query()->with([
              'user' => fn ($q) => $q->withTrashed(),
              'inviter',
          ]);

          $this->applySort($query);

          return $query->paginate($this->perPage);
      }

      protected function applySort(Builder $query): void
      {
          if ($this->sortColumn === null) {
              $query->orderByDesc('invitations.created_at');
              return;
          }

          $direction = $this->sortDirection === 'desc' ? 'desc' : 'asc';

          match ($this->sortColumn) {
              'sent_at'    => $query->orderBy('invitations.created_at', $direction),
              'expires_at' => $query->orderBy('invitations.expires_at', $direction),
              default      => null, // andere sortable kolommen worden in volgende tasks toegevoegd
          };
      }

      #[Layout('components.layouts.app')]
      #[Title('Uitgenodigde gebruikers')]
      public function render()
      {
          abort_unless(auth()->user()?->can('invitations.send'), 403);

          return view('livewire.invitations.index', [
              'invitations' => $this->invitations(),
              'columns' => $this->availableColumns(),
          ]);
      }
  }
  ```

  > Let op: alle filter/sort op email/name/inviter/status komen in volgende tasks. In dit task alleen sent_at, expires_at en de skeleton.

- [ ] **Step 4: Voeg de route toe in `routes/web.php`.**

  Vind de groep met `users.index`/`roles.index`. Voeg toe:
  ```php
  use App\Livewire\Invitations\Index as InvitationIndex;
  // ...
  Route::get('/admin/invitations', InvitationIndex::class)->name('invitations.index');
  ```
  Plaats de regel logisch (bijv. direct na `roles.edit` of vóór `organisations.index`).

- [ ] **Step 5: Maak een minimale view zodat tests die met `Livewire::test()` op de view-binding leunen niet falen door een ontbrekend bestand.**

  Maak `resources/views/livewire/invitations/index.blade.php`:
  ```blade
  <div>
      {{-- Placeholder — wordt in Task 9 vervangen door volledige view --}}
  </div>
  ```

- [ ] **Step 6: Run de tests.**
  ```bash
  ./vendor/bin/pest tests/Feature/Invitations/IndexTest.php
  ```
  Expected: 4 passed.

- [ ] **Step 7: Commit.**
  ```bash
  git add app/Livewire/Invitations/Index.php resources/views/livewire/invitations/index.blade.php routes/web.php tests/Feature/Invitations/IndexTest.php
  git commit -m "$(cat <<'EOF'
  feat(invitations): nieuwe pagina /admin/invitations met sort skeleton

  Livewire-component met WithPagination, 3-state sort, route invitations.index,
  permission-guard via invitations.send. Filter/status/relatie-sort komen in
  volgende commits. Placeholder-view zodat tests rendert.

  Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
  EOF
  )"
  ```

---

## Task 2: Filter op email + naam (relatie ILIKE)

**Files:**
- Modify: `app/Livewire/Invitations/Index.php`
- Modify: `tests/Feature/Invitations/IndexTest.php`

- [ ] **Step 1: Voeg twee failing tests toe.**

  Plaats binnen het Invitations Index describe-block, na de bestaande tests:

  ```php
  it('filters email case-insensitively via ILIKE contains', function () {
      $u1 = User::factory()->for($this->org)->create(['email' => 'Alice@demo1.local']);
      $u2 = User::factory()->for($this->org)->create(['email' => 'BOB@demo1.local']);
      $u3 = User::factory()->for($this->org)->create(['email' => 'carol@demo1.local']);
      Invitation::factory()->create(['user_id' => $u1->id, 'invited_by' => $this->actor->id]);
      Invitation::factory()->create(['user_id' => $u2->id, 'invited_by' => $this->actor->id]);
      Invitation::factory()->create(['user_id' => $u3->id, 'invited_by' => $this->actor->id]);

      $this->actingAs($this->actor);

      Livewire::test(Index::class)
          ->set('filters.email', 'ALICE')
          ->assertViewHas('invitations', fn ($invs) => $invs->total() === 1
              && $invs->getCollection()->first()->user->email === 'Alice@demo1.local');
  });

  it('name filter matches first_name, middle_name, or last_name on related user', function () {
      $u1 = User::factory()->for($this->org)->create(['first_name' => 'Anna', 'last_name' => 'Zijlstra']);
      $u2 = User::factory()->for($this->org)->create(['first_name' => 'Bart', 'middle_name' => 'Anna', 'last_name' => 'Pieters']);
      $u3 = User::factory()->for($this->org)->create(['first_name' => 'Bert', 'last_name' => 'Anna']);
      $u4 = User::factory()->for($this->org)->create(['first_name' => 'Carl', 'last_name' => 'Yssel']);
      foreach ([$u1, $u2, $u3, $u4] as $u) {
          Invitation::factory()->create(['user_id' => $u->id, 'invited_by' => $this->actor->id]);
      }

      $this->actingAs($this->actor);

      Livewire::test(Index::class)
          ->set('filters.name', 'Anna')
          ->assertViewHas('invitations', fn ($invs) => $invs->total() === 3);
  });
  ```

- [ ] **Step 2: Run om red te zien.**
  ```bash
  ./vendor/bin/pest tests/Feature/Invitations/IndexTest.php --filter="filters email|name filter matches"
  ```
  Expected: 2 errors — `filters` property bestaat nog niet in `applyFilters`.

- [ ] **Step 3: Voeg `applyFilters()` toe + roep aan vanuit `invitations()`.**

  In `app/Livewire/Invitations/Index.php`:

  Voeg de helper toe (boven `applySort()`):
  ```php
  protected function applyFilters(Builder $query): void
  {
      foreach ($this->filters as $key => $value) {
          if ($value === '' || $value === null) {
              continue;
          }
          match ($key) {
              'email' => $query->whereHas('user', fn ($q) => $q->withTrashed()
                  ->where('email', 'ILIKE', '%'.$value.'%')),

              'name' => $query->whereHas('user', function ($q) use ($value) {
                  $like = '%'.$value.'%';
                  $q->withTrashed()->where(fn ($qq) => $qq
                      ->where('first_name',  'ILIKE', $like)
                      ->orWhere('middle_name','ILIKE', $like)
                      ->orWhere('last_name', 'ILIKE', $like)
                  );
              }),

              default => null, // andere arms volgen in Tasks 3-5
          };
      }
  }
  ```

  Pas `invitations()` aan zodat het filters toepast vóór sort:
  ```php
  public function invitations()
  {
      $query = Invitation::query()->with([
          'user' => fn ($q) => $q->withTrashed(),
          'inviter',
      ]);

      $this->applyFilters($query);
      $this->applySort($query);

      return $query->paginate($this->perPage);
  }
  ```

- [ ] **Step 4: Run de tests.**
  ```bash
  ./vendor/bin/pest tests/Feature/Invitations/IndexTest.php --filter="filters email|name filter matches"
  ```
  Expected: 2 passed.

- [ ] **Step 5: Commit.**
  ```bash
  git add app/Livewire/Invitations/Index.php tests/Feature/Invitations/IndexTest.php
  git commit -m "$(cat <<'EOF'
  feat(invitations): email- en naam-filter via whereHas + ILIKE

  Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
  EOF
  )"
  ```

---

## Task 3: Filter op inviter

**Files:**
- Modify: `app/Livewire/Invitations/Index.php`
- Modify: `tests/Feature/Invitations/IndexTest.php`

- [ ] **Step 1: Test.**
  ```php
  it('inviter filter matches inviter email', function () {
      $invited = User::factory()->for($this->org)->create();
      $other_inviter = User::factory()->for($this->org)->create(['email' => 'other@demo1.local']);
      Invitation::factory()->create(['user_id' => $invited->id, 'invited_by' => $this->actor->id]);
      Invitation::factory()->create(['user_id' => $invited->id, 'invited_by' => $other_inviter->id]);

      $this->actingAs($this->actor);

      Livewire::test(Index::class)
          ->set('filters.inviter', 'admin')
          ->assertViewHas('invitations', fn ($invs) => $invs->total() === 1);
  });
  ```

- [ ] **Step 2: Run rood.** Filter 'inviter' niet behandeld.

- [ ] **Step 3: Voeg `'inviter'`-arm toe aan `applyFilters()`.**
  ```php
  'inviter' => $query->whereHas('inviter', fn ($q) => $q
      ->where('email', 'ILIKE', '%'.$value.'%')),
  ```

- [ ] **Step 4: Run groen.**

- [ ] **Step 5: Commit.**
  ```
  feat(invitations): inviter-filter op email
  ```

---

## Task 4: Filter op status (4 staten met derivation)

**Files:**
- Modify: `app/Livewire/Invitations/Index.php`
- Modify: `tests/Feature/Invitations/IndexTest.php`

- [ ] **Step 1: Vier failing tests, één per status.**
  ```php
  it('status filter pending shows only open + active-user invitations', function () {
      $u_pending  = User::factory()->for($this->org)->create();
      $u_accepted = User::factory()->for($this->org)->create();
      $u_expired  = User::factory()->for($this->org)->create();
      $u_cancelled= User::factory()->for($this->org)->create();

      Invitation::factory()->create(['user_id' => $u_pending->id,  'invited_by' => $this->actor->id, 'expires_at' => now()->addDays(3), 'accepted_at' => null]);
      Invitation::factory()->create(['user_id' => $u_accepted->id, 'invited_by' => $this->actor->id, 'expires_at' => now()->subDays(1), 'accepted_at' => now()]);
      Invitation::factory()->create(['user_id' => $u_expired->id,  'invited_by' => $this->actor->id, 'expires_at' => now()->subDays(1), 'accepted_at' => null]);
      $cancelled_inv = Invitation::factory()->create(['user_id' => $u_cancelled->id, 'invited_by' => $this->actor->id, 'expires_at' => now()->addDays(3), 'accepted_at' => null]);
      $u_cancelled->delete(); // soft-delete = cancelled

      $this->actingAs($this->actor);

      Livewire::test(Index::class)
          ->set('filters.status', 'pending')
          ->assertViewHas('invitations', fn ($invs) => $invs->total() === 1
              && $invs->getCollection()->first()->user_id === $u_pending->id);
  });

  it('status filter accepted shows only accepted invitations', function () {
      $u1 = User::factory()->for($this->org)->create();
      $u2 = User::factory()->for($this->org)->create();
      Invitation::factory()->create(['user_id' => $u1->id, 'invited_by' => $this->actor->id, 'accepted_at' => null]);
      Invitation::factory()->create(['user_id' => $u2->id, 'invited_by' => $this->actor->id, 'accepted_at' => now()]);

      $this->actingAs($this->actor);

      Livewire::test(Index::class)
          ->set('filters.status', 'accepted')
          ->assertViewHas('invitations', fn ($invs) => $invs->total() === 1
              && $invs->getCollection()->first()->accepted_at !== null);
  });

  it('status filter expired shows only expired open invitations', function () {
      $u1 = User::factory()->for($this->org)->create();
      $u2 = User::factory()->for($this->org)->create();
      Invitation::factory()->create(['user_id' => $u1->id, 'invited_by' => $this->actor->id, 'expires_at' => now()->subDays(1), 'accepted_at' => null]);
      Invitation::factory()->create(['user_id' => $u2->id, 'invited_by' => $this->actor->id, 'expires_at' => now()->addDays(3), 'accepted_at' => null]);

      $this->actingAs($this->actor);

      Livewire::test(Index::class)
          ->set('filters.status', 'expired')
          ->assertViewHas('invitations', fn ($invs) => $invs->total() === 1
              && $invs->getCollection()->first()->user_id === $u1->id);
  });

  it('status filter cancelled shows only invitations with soft-deleted user', function () {
      $u1 = User::factory()->for($this->org)->create();
      $u2 = User::factory()->for($this->org)->create();
      Invitation::factory()->create(['user_id' => $u1->id, 'invited_by' => $this->actor->id]);
      Invitation::factory()->create(['user_id' => $u2->id, 'invited_by' => $this->actor->id]);
      $u2->delete();

      $this->actingAs($this->actor);

      Livewire::test(Index::class)
          ->set('filters.status', 'cancelled')
          ->assertViewHas('invitations', fn ($invs) => $invs->total() === 1
              && $invs->getCollection()->first()->user_id === $u2->id);
  });
  ```

- [ ] **Step 2: Run rood.**

- [ ] **Step 3: Voeg `'status'`-arm + helper toe aan `applyFilters()`.**

  Update de `match` om:
  ```php
  'status' => $this->applyStatusFilter($query, $value),
  ```

  Voeg helper toe boven `applySort()`:
  ```php
  protected function applyStatusFilter(Builder $query, string $value): void
  {
      match ($value) {
          'pending' => $query->whereNull('accepted_at')
              ->where('expires_at', '>=', now())
              ->whereHas('user', fn ($q) => $q->whereNull('deleted_at')),

          'accepted' => $query->whereNotNull('accepted_at'),

          'expired' => $query->whereNull('accepted_at')
              ->where('expires_at', '<', now())
              ->whereHas('user', fn ($q) => $q->whereNull('deleted_at')),

          'cancelled' => $query->whereHas('user', fn ($q) => $q->onlyTrashed()),

          default => null,
      };
  }
  ```

- [ ] **Step 4: Run groen.**

- [ ] **Step 5: Commit.**
  ```
  feat(invitations): status-filter met 4 afgeleide staten
  ```

---

## Task 5: Filter op datum (expires_at + sent_at)

**Files:**
- Modify: `app/Livewire/Invitations/Index.php`
- Modify: `tests/Feature/Invitations/IndexTest.php`

- [ ] **Step 1: Twee failing tests.**
  ```php
  it('expires_at filter is "≥"', function () {
      $u1 = User::factory()->for($this->org)->create();
      $u2 = User::factory()->for($this->org)->create();
      Invitation::factory()->create(['user_id' => $u1->id, 'invited_by' => $this->actor->id, 'expires_at' => '2026-01-01 00:00:00']);
      Invitation::factory()->create(['user_id' => $u2->id, 'invited_by' => $this->actor->id, 'expires_at' => '2026-06-01 00:00:00']);

      $this->actingAs($this->actor);

      Livewire::test(Index::class)
          ->set('filters.expires_at', '2026-03-01')
          ->assertViewHas('invitations', fn ($invs) => $invs->total() === 1
              && $invs->getCollection()->first()->user_id === $u2->id);
  });

  it('sent_at filter is "≥" on created_at', function () {
      $u1 = User::factory()->for($this->org)->create();
      $u2 = User::factory()->for($this->org)->create();
      Invitation::factory()->create(['user_id' => $u1->id, 'invited_by' => $this->actor->id, 'created_at' => '2025-12-15 00:00:00', 'updated_at' => '2025-12-15 00:00:00']);
      Invitation::factory()->create(['user_id' => $u2->id, 'invited_by' => $this->actor->id, 'created_at' => '2026-04-15 00:00:00', 'updated_at' => '2026-04-15 00:00:00']);

      $this->actingAs($this->actor);

      Livewire::test(Index::class)
          ->set('filters.sent_at', '2026-01-01')
          ->assertViewHas('invitations', fn ($invs) => $invs->total() === 1
              && $invs->getCollection()->first()->user_id === $u2->id);
  });
  ```

- [ ] **Step 2: Run rood.**

- [ ] **Step 3: Voeg arms toe aan `applyFilters`.**
  ```php
  'expires_at' => $query->whereDate('invitations.expires_at', '>=', $value),
  'sent_at'    => $query->whereDate('invitations.created_at', '>=', $value),
  ```

- [ ] **Step 4: Run groen.**

- [ ] **Step 5: Commit.**
  ```
  feat(invitations): datum-filters voor verloop- en verzenddatum
  ```

---

## Task 6: status() helper + sort op status (CASE)

**Files:**
- Modify: `app/Livewire/Invitations/Index.php`
- Modify: `tests/Feature/Invitations/IndexTest.php`

- [ ] **Step 1: Twee tests — derivation + sort.**
  ```php
  it('derives status from accepted_at / expires_at / user.deleted_at', function () {
      $u_pending  = User::factory()->for($this->org)->create();
      $u_accepted = User::factory()->for($this->org)->create();
      $u_expired  = User::factory()->for($this->org)->create();
      $u_cancelled= User::factory()->for($this->org)->create();

      $i_p = Invitation::factory()->create(['user_id' => $u_pending->id,   'invited_by' => $this->actor->id, 'expires_at' => now()->addDays(3), 'accepted_at' => null]);
      $i_a = Invitation::factory()->create(['user_id' => $u_accepted->id,  'invited_by' => $this->actor->id, 'expires_at' => now()->subDays(1), 'accepted_at' => now()]);
      $i_e = Invitation::factory()->create(['user_id' => $u_expired->id,   'invited_by' => $this->actor->id, 'expires_at' => now()->subDays(1), 'accepted_at' => null]);
      $i_c = Invitation::factory()->create(['user_id' => $u_cancelled->id, 'invited_by' => $this->actor->id, 'expires_at' => now()->addDays(3), 'accepted_at' => null]);
      $u_cancelled->delete();

      $this->actingAs($this->actor);

      $component = Livewire::test(Index::class);

      $i_p->load(['user' => fn ($q) => $q->withTrashed()]);
      $i_a->load(['user' => fn ($q) => $q->withTrashed()]);
      $i_e->load(['user' => fn ($q) => $q->withTrashed()]);
      $i_c->load(['user' => fn ($q) => $q->withTrashed()]);

      expect($component->instance()->status($i_p))->toBe('pending')
          ->and($component->instance()->status($i_a))->toBe('accepted')
          ->and($component->instance()->status($i_e))->toBe('expired')
          ->and($component->instance()->status($i_c))->toBe('cancelled');
  });

  it('sorts by status in stable order (accepted → pending → expired → cancelled)', function () {
      $u_pending  = User::factory()->for($this->org)->create();
      $u_accepted = User::factory()->for($this->org)->create();
      $u_expired  = User::factory()->for($this->org)->create();
      $u_cancelled= User::factory()->for($this->org)->create();

      Invitation::factory()->create(['user_id' => $u_pending->id,   'invited_by' => $this->actor->id, 'expires_at' => now()->addDays(3), 'accepted_at' => null]);
      Invitation::factory()->create(['user_id' => $u_accepted->id,  'invited_by' => $this->actor->id, 'expires_at' => now()->subDays(1), 'accepted_at' => now()]);
      Invitation::factory()->create(['user_id' => $u_expired->id,   'invited_by' => $this->actor->id, 'expires_at' => now()->subDays(1), 'accepted_at' => null]);
      Invitation::factory()->create(['user_id' => $u_cancelled->id, 'invited_by' => $this->actor->id, 'expires_at' => now()->addDays(3), 'accepted_at' => null]);
      $u_cancelled->delete();

      $this->actingAs($this->actor);

      Livewire::test(Index::class)
          ->call('sort', 'status')
          ->assertViewHas('invitations', function ($invs) use ($u_accepted, $u_pending, $u_expired, $u_cancelled) {
              $userIds = $invs->getCollection()->pluck('user_id')->all();
              return $userIds === [$u_accepted->id, $u_pending->id, $u_expired->id, $u_cancelled->id];
          });
  });
  ```

  > Volgorde gekozen voor de CASE: 1 = accepted, 2 = pending, 3 = expired, 4 = cancelled. Asc dus accepted eerst.

- [ ] **Step 2: Run rood.**

- [ ] **Step 3: Voeg `status()` helper toe + status-arm aan `applySort`.**

  Voeg `status()` helper toe (publiek, zodat de view 'm aan kan roepen):
  ```php
  public function status(Invitation $invitation): string
  {
      if ($invitation->accepted_at !== null)        return 'accepted';
      if ($invitation->user?->trashed())             return 'cancelled';
      if ($invitation->expires_at?->isPast())        return 'expired';
      return 'pending';
  }
  ```

  Voeg arms toe aan `applySort()` (vervang de `default => null` met:):
  ```php
  match ($this->sortColumn) {
      'sent_at'    => $query->orderBy('invitations.created_at', $direction),
      'expires_at' => $query->orderBy('invitations.expires_at', $direction),

      'email' => $query->orderBy(
          User::withTrashed()->select('email')
              ->whereColumn('users.id', 'invitations.user_id'),
          $direction
      ),

      'name' => $query
          ->orderBy(User::withTrashed()->select('last_name')->whereColumn('users.id', 'invitations.user_id'), $direction)
          ->orderBy(User::withTrashed()->select('first_name')->whereColumn('users.id', 'invitations.user_id'), $direction),

      'inviter' => $query->orderBy(
          User::query()->select('email')
              ->whereColumn('users.id', 'invitations.invited_by'),
          $direction
      ),

      'status' => $query->orderByRaw(
          "CASE
              WHEN invitations.accepted_at IS NOT NULL THEN 1
              WHEN (SELECT deleted_at FROM users WHERE users.id = invitations.user_id) IS NOT NULL THEN 4
              WHEN invitations.expires_at < ? THEN 3
              ELSE 2
            END {$direction}",
          [now()]
      ),

      default => null,
  };
  ```

- [ ] **Step 4: Run groen + sanity-check op alle relatie-sorts via één test:**

  Voeg test toe:
  ```php
  it('sorts by email, name, inviter asc and desc', function () {
      $u1 = User::factory()->for($this->org)->create(['email' => 'aaa@demo1.local', 'first_name' => 'A', 'last_name' => 'A']);
      $u2 = User::factory()->for($this->org)->create(['email' => 'zzz@demo1.local', 'first_name' => 'Z', 'last_name' => 'Z']);
      Invitation::factory()->create(['user_id' => $u1->id, 'invited_by' => $this->actor->id]);
      Invitation::factory()->create(['user_id' => $u2->id, 'invited_by' => $this->actor->id]);

      $this->actingAs($this->actor);

      Livewire::test(Index::class)
          ->call('sort', 'email')
          ->assertViewHas('invitations', fn ($i) => $i->getCollection()->first()->user_id === $u1->id)
          ->call('sort', 'email')
          ->assertViewHas('invitations', fn ($i) => $i->getCollection()->first()->user_id === $u2->id)
          ->call('sort', 'name')
          ->assertViewHas('invitations', fn ($i) => $i->getCollection()->first()->user_id === $u1->id);
  });
  ```

  Run alle Invitations-tests om regressies te vangen:
  ```bash
  ./vendor/bin/pest tests/Feature/Invitations/IndexTest.php
  ```

- [ ] **Step 5: Commit.**
  ```
  feat(invitations): status() helper + sort op status/email/name/inviter

  Sort op relatie-kolommen via orderBy(subquery) met User::withTrashed() —
  cancelled-invitations blijven vindbaar. Status-sort via raw CASE (1=accepted,
  2=pending, 3=expired, 4=cancelled).
  ```

---

## Task 7: Sanitisatie (updatedFilters + hidden-column-clear + page-reset)

**Files:**
- Modify: `app/Livewire/Invitations/Index.php`
- Modify: `tests/Feature/Invitations/IndexTest.php`

- [ ] **Step 1: Vier failing tests.**
  ```php
  it('sanitises unknown filter keys from session state', function () {
      $this->actingAs($this->actor);

      Livewire::test(Index::class)
          ->set('filters', ['email' => 'demo', 'bogus_key' => 'x', 'name' => 'Bob'])
          ->assertSet('filters.email', 'demo')
          ->assertSet('filters.name', 'Bob')
          ->tap(fn ($c) => expect(array_key_exists('bogus_key', $c->get('filters')))->toBeFalse());
  });

  it('clamps invalid status filter values back to empty string', function () {
      $this->actingAs($this->actor);
      Livewire::test(Index::class)
          ->set('filters.status', 'banana')
          ->assertSet('filters.status', '');
  });

  it('resets to page 1 when any filter changes', function () {
      $invited = User::factory()->count(15)->for($this->org)->create();
      foreach ($invited as $u) {
          Invitation::factory()->create(['user_id' => $u->id, 'invited_by' => $this->actor->id]);
      }
      $this->actingAs($this->actor);

      Livewire::test(Index::class)
          ->set('perPage', 5)
          ->call('gotoPage', 2)
          ->assertSet('paginators.page', 2)
          ->set('filters.email', 'x')
          ->assertSet('paginators.page', 1);
  });

  it('unselecting a column clears its active filter', function () {
      $this->actingAs($this->actor);
      Livewire::test(Index::class)
          ->set('filters.email', 'demo1')
          ->assertSet('filters.email', 'demo1')
          ->set('selectedColumns', ['name', 'status'])
          ->assertSet('filters.email', '');
  });
  ```

- [ ] **Step 2: Run rood.**

- [ ] **Step 3: Voeg `updatedFilters()` en `updatedSelectedColumns()` toe.**

  ```php
  public function updatedFilters(): void
  {
      $valid = array_keys(self::DEFAULT_FILTERS);
      $this->filters = array_intersect_key(
          array_merge(self::DEFAULT_FILTERS, $this->filters),
          array_flip($valid),
      );

      if (! in_array($this->filters['status'], array_merge([''], self::STATUSES), true)) {
          $this->filters['status'] = '';
      }

      $this->resetPage();
  }

  public function updatedSelectedColumns(): void
  {
      $valid = array_keys($this->availableColumns());
      $this->selectedColumns = array_values(array_intersect($valid, $this->selectedColumns));

      foreach (array_keys($this->filters) as $key) {
          if (! in_array($key, $this->selectedColumns, true)) {
              $this->filters[$key] = '';
          }
      }
  }
  ```

- [ ] **Step 4: Run groen.**

- [ ] **Step 5: Commit.**
  ```
  feat(invitations): sanitisatie en page-reset op filter/column-mutaties
  ```

---

## Task 8: Cancel + resend acties + reset-page-on-sort test

**Files:**
- Modify: `app/Livewire/Invitations/Index.php`
- Modify: `tests/Feature/Invitations/IndexTest.php`

- [ ] **Step 1: Drie tests.**
  ```php
  it('cancel-action soft-deletes the user (delegates to InvitationService)', function () {
      $u = User::factory()->for($this->org)->create();
      $inv = Invitation::factory()->create(['user_id' => $u->id, 'invited_by' => $this->actor->id]);

      $this->actingAs($this->actor);

      Livewire::test(Index::class)
          ->call('cancel', $inv->id)
          ->assertHasNoErrors();

      expect(User::withTrashed()->find($u->id)->trashed())->toBeTrue();
  });

  it('resend-action calls InvitationService::resendReminder', function () {
      $u = User::factory()->for($this->org)->create();
      $inv = Invitation::factory()->create(['user_id' => $u->id, 'invited_by' => $this->actor->id, 'expires_at' => now()->addDays(1)]);

      $this->actingAs($this->actor);

      Livewire::test(Index::class)
          ->call('resend', $inv->id)
          ->assertHasNoErrors();

      $inv->refresh();
      expect($inv->reminder_sent_at)->not->toBeNull()
          ->and($inv->expires_at->greaterThan(now()->addDays(6)))->toBeTrue(); // verlengd met +7 dagen
  });

  it('resets to page 1 on sort change', function () {
      $invited = User::factory()->count(15)->for($this->org)->create();
      foreach ($invited as $u) {
          Invitation::factory()->create(['user_id' => $u->id, 'invited_by' => $this->actor->id]);
      }
      $this->actingAs($this->actor);

      Livewire::test(Index::class)
          ->set('perPage', 5)
          ->call('gotoPage', 2)
          ->assertSet('paginators.page', 2)
          ->call('sort', 'email')
          ->assertSet('paginators.page', 1);
  });
  ```

- [ ] **Step 2: Run rood (cancel/resend bestaan niet, sort-reset werkt al).**

- [ ] **Step 3: Voeg cancel + resend toe op de component.**

  Voeg imports toe:
  ```php
  use App\Services\InvitationService;
  use Livewire\Attributes\On;
  ```

  Voeg methodes toe (na `updatedSelectedColumns()`):
  ```php
  public function cancel(int $invitationId, InvitationService $service): void
  {
      abort_unless(auth()->user()?->can('invitations.cancel'), 403);

      $invitation = Invitation::findOrFail($invitationId);
      $service->cancel($invitation, auth()->user());

      $this->dispatch('invitation-cancelled');
  }

  public function resend(int $invitationId, InvitationService $service): void
  {
      abort_unless(auth()->user()?->can('invitations.send'), 403);

      $invitation = Invitation::findOrFail($invitationId);
      $service->resendReminder($invitation, auth()->user());
  }

  #[On('invitation-sent')]
  #[On('invitation-cancelled')]
  public function refresh(): void
  {
      // Trigger re-render
  }
  ```

- [ ] **Step 4: Run groen.**

- [ ] **Step 5: Commit.**
  ```
  feat(invitations): cancel- en resend-acties op nieuwe Index-component
  ```

---

## Task 9: View + partial + clearFilters/hasNoFilters

**Files:**
- Modify: `app/Livewire/Invitations/Index.php`
- Modify: `resources/views/livewire/invitations/index.blade.php` (overschrijven)
- Create: `resources/views/livewire/invitations/partials/column-filter.blade.php`

- [ ] **Step 1: Voeg `clearFilters()` + `hasNoFilters()` toe op de component** (boven `render()`):
  ```php
  public function clearFilters(): void
  {
      $this->filters = self::DEFAULT_FILTERS;
      $this->resetPage();
  }

  public function hasNoFilters(): bool
  {
      foreach ($this->filters as $value) {
          if ($value !== '' && $value !== null) {
              return false;
          }
      }
      return true;
  }
  ```

- [ ] **Step 2: Maak de partial aan.**

  `resources/views/livewire/invitations/partials/column-filter.blade.php`:
  ```blade
  @switch($key)
      @case('status')
          <flux:select wire:model.live="filters.status" size="sm">
              <option value="">{{ __('Alle') }}</option>
              <option value="pending">{{ __('pending') }}</option>
              <option value="accepted">{{ __('accepted') }}</option>
              <option value="expired">{{ __('expired') }}</option>
              <option value="cancelled">{{ __('cancelled') }}</option>
          </flux:select>
      @break

      @case('expires_at')
      @case('sent_at')
          <flux:input type="date"
              wire:model.live.debounce.300ms="filters.{{ $key }}"
              size="sm" />
      @break

      @default
          <flux:input
              wire:model.live.debounce.300ms="filters.{{ $key }}"
              placeholder="{{ __('Bevat…') }}"
              size="sm" />
  @endswitch
  ```

- [ ] **Step 3: Vervang de placeholder-view volledig.**

  `resources/views/livewire/invitations/index.blade.php`:
  ```blade
  <div class="space-y-6">
      <div class="flex items-end justify-between gap-4">
          <div>
              <flux:heading size="xl">{{ __('Uitgenodigde gebruikers') }}</flux:heading>
              <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
                  {{ __('Verzonden uitnodigingen voor :org.', ['org' => tenant()?->name ?? config('app.name')]) }}
              </flux:text>
          </div>
          <flux:button :href="route('users.index')" variant="ghost" wire:navigate>
              {{ __('Terug naar gebruikers') }}
          </flux:button>
      </div>

      <div class="flex items-end justify-between gap-4">
          <flux:dropdown>
              <flux:button icon="adjustments-horizontal" variant="ghost">
                  {{ __('Kolommen') }}
              </flux:button>
              <flux:menu>
                  <flux:menu.checkbox.group wire:model.live="selectedColumns">
                      @foreach ($columns as $key => $label)
                          <flux:menu.checkbox value="{{ $key }}">{{ $label }}</flux:menu.checkbox>
                      @endforeach
                  </flux:menu.checkbox.group>
              </flux:menu>
          </flux:dropdown>

          <flux:select wire:model.live="perPage" label="{{ __('Per pagina') }}">
              @foreach (App\Livewire\Invitations\Index::PER_PAGE_OPTIONS as $option)
                  <option value="{{ $option }}">{{ $option }}</option>
              @endforeach
          </flux:select>
      </div>

      @if ($invitations->total() === 0 && $this->hasNoFilters())
          <flux:callout variant="secondary" icon="envelope">{{ __('Er staan geen uitnodigingen.') }}</flux:callout>
      @else
          <flux:table>
              <flux:table.columns>
                  @foreach ($columns as $key => $label)
                      @if (in_array($key, $selectedColumns, true))
                          <flux:table.column
                              sortable
                              :sorted="$sortColumn === $key"
                              :direction="$sortColumn === $key ? $sortDirection : null"
                              wire:click="sort('{{ $key }}')"
                              style="cursor:pointer">
                              {{ $label }}
                          </flux:table.column>
                      @endif
                  @endforeach
                  <flux:table.column>{{ __('Acties') }}</flux:table.column>
              </flux:table.columns>
              <flux:table.rows>
                  <flux:table.row class="bg-zinc-50/60 dark:bg-white/5">
                      @foreach ($columns as $key => $label)
                          @if (in_array($key, $selectedColumns, true))
                              <flux:table.cell class="py-2">
                                  @include('livewire.invitations.partials.column-filter', ['key' => $key])
                              </flux:table.cell>
                          @endif
                      @endforeach
                      <flux:table.cell></flux:table.cell>
                  </flux:table.row>

                  @foreach ($invitations as $invitation)
                      <flux:table.row :key="$invitation->id">
                          @foreach ($columns as $key => $label)
                              @if (in_array($key, $selectedColumns, true))
                                  <flux:table.cell>
                                      @switch($key)
                                          @case('email')      {{ $invitation->user?->email ?? '—' }} @break
                                          @case('name')       {{ $invitation->user?->name ?? '—' }} @break
                                          @case('status')     {{ __($this->status($invitation)) }} @break
                                          @case('inviter')    {{ $invitation->inviter?->email ?? '—' }} @break
                                          @case('expires_at') {{ $invitation->expires_at?->isoFormat('LLL') ?? '—' }} @break
                                          @case('sent_at')    {{ $invitation->created_at?->isoFormat('LLL') ?? '—' }} @break
                                      @endswitch
                                  </flux:table.cell>
                              @endif
                          @endforeach
                          <flux:table.cell>
                              <div class="flex gap-2">
                                  @if ($this->status($invitation) === 'pending')
                                      @can('invitations.send')
                                          <flux:button size="sm" variant="ghost" wire:click="resend({{ $invitation->id }})">
                                              {{ __('Herinnering') }}
                                          </flux:button>
                                      @endcan
                                      @can('invitations.cancel')
                                          <flux:button size="sm" variant="danger" wire:click="cancel({{ $invitation->id }})">
                                              {{ __('Intrekken') }}
                                          </flux:button>
                                      @endcan
                                  @endif
                              </div>
                          </flux:table.cell>
                      </flux:table.row>
                  @endforeach
              </flux:table.rows>
          </flux:table>

          @if ($invitations->total() === 0)
              <flux:callout variant="secondary" icon="funnel">
                  {{ __('Geen uitnodigingen met deze filters.') }}
                  <flux:button size="sm" variant="ghost" wire:click="clearFilters">
                      {{ __('Filters wissen') }}
                  </flux:button>
              </flux:callout>
          @endif

          {{ $invitations->links() }}
      @endif
  </div>
  ```

- [ ] **Step 4: Run de hele Invitations-test-suite.**
  ```bash
  ./vendor/bin/pest tests/Feature/Invitations/IndexTest.php
  ```
  Expected: alle tests groen.

- [ ] **Step 5: Commit.**
  ```
  feat(invitations): volledige view + partial + clearFilters/hasNoFilters
  ```

---

## Task 10: Users-pagina integratie

**Files:**
- Modify: `resources/views/livewire/users/index.blade.php`
- Modify: `tests/Feature/Invitations/InviteUiTest.php`

- [ ] **Step 1: Voeg knop "Uitgenodigde gebruikers" toe aan users-page header**, vóór de invite-component.

  In `resources/views/livewire/users/index.blade.php`, vind het header-blok:
  ```blade
  @can('create', App\Models\User::class)
      <livewire:invitations.send />
  @endcan
  ```

  Voeg ervoor toe:
  ```blade
  @can('invitations.send')
      <flux:button :href="route('invitations.index')" variant="ghost" wire:navigate>
          {{ __('Uitgenodigde gebruikers') }}
      </flux:button>
  @endcan
  ```

- [ ] **Step 2: Verwijder de embedded PendingList aan het einde van de users-page.**

  Vind en verwijder de regel:
  ```blade
  <livewire:invitations.pending-list />
  ```

- [ ] **Step 3: Migreer InviteUiTest.php tests die op `PendingList` mikken.**

  Lees `tests/Feature/Invitations/InviteUiTest.php`. Identificeer welke tests:
  - **Send-tests** — blijven (testen op `App\Livewire\Invitations\Send`).
  - **PendingList-tests** — verwijderen (cancel-action, resend-action, render-empty-state). Equivalente assertions zitten al in `IndexTest.php`.
  - **Routes/permissies** — controleer of er een test is die op `<livewire:invitations.pending-list />` in de users-pagina zelf rendert; verwijderen of bijwerken naar de nieuwe knop.

  Concrete actie: kijk naar `InviteUiTest.php`, verwijder elke test waarvan de body `Livewire::test(PendingList::class)` of een `assertSee` op een PendingList-string bevat. Houd de Send-tests intact.

- [ ] **Step 4: Voeg twee tests toe in `tests/Feature/Users/UserCrudTest.php`** om de nieuwe knop te pinnen:
  ```php
  // In de Users Index Livewire describe-block, na bestaande tests:

  it('shows "Uitgenodigde gebruikers"-knop voor gebruikers met invitations.send permissie', function () {
      $this->actingAs($this->actor);
      Livewire::test(\App\Livewire\Users\Index::class)
          ->assertSee('Uitgenodigde gebruikers');
  });

  it('verbergt "Uitgenodigde gebruikers"-knop voor gebruikers zonder invitations.send', function () {
      $regular = User::factory()->for($this->org)->create();
      $this->actingAs($regular);
      Livewire::test(\App\Livewire\Users\Index::class)
          ->assertDontSee('Uitgenodigde gebruikers');
  });
  ```

- [ ] **Step 5: Run alle Users + Invitations tests.**
  ```bash
  ./vendor/bin/pest tests/Feature/Users/UserCrudTest.php tests/Feature/Invitations/
  ```
  Expected: alle tests groen.

- [ ] **Step 6: Commit.**
  ```
  feat(users): knop 'Uitgenodigde gebruikers' + verwijder embedded PendingList

  - Knop in users-header (ghost-variant, vóór invite-knop) navigeert naar
    /admin/invitations.
  - Embedded <livewire:invitations.pending-list /> verwijderd; functionaliteit
    leeft nu volledig op de aparte invitations-pagina.
  - Migratie van PendingList-tests in InviteUiTest naar Invitations\IndexTest.
  ```

---

## Task 11: Final verification

- [ ] **Step 1: Volledige test-suite.**
  ```bash
  ./vendor/bin/pest
  ```
  Expected: groen — bestaand (233) + nieuw (~25 in IndexTest + 2 in UserCrudTest), minus eventueel verwijderde PendingList-tests.

- [ ] **Step 2: Pint clean.**
  ```bash
  ./vendor/bin/pint --test app/Livewire/Invitations/ resources/views/livewire/invitations/ tests/Feature/Invitations/IndexTest.php
  ```
  Expected: PASS. Bij fail: `./vendor/bin/pint app/Livewire/Invitations/ ...` om te fixen, dan opnieuw test.

- [ ] **Step 3: Manual browser smoke (volgens spec-verificatie).**

  In Herd / `php artisan serve`:
  - Klik op users-page de knop "Uitgenodigde gebruikers" → navigatie naar `/admin/invitations`.
  - Controleer dat de embedded PendingList van de users-page verdwenen is.
  - Filter op email werkt en debounced.
  - Klik op kop sorteert; tweede klik = desc; derde klik = default.
  - Status-filter toont per state correct (pending/accepted/expired/cancelled).
  - "Herinnering" en "Intrekken" werken alleen voor pending-rijen.
  - Page-reload behoudt filters + sort (Session).
  - "Filters wissen"-knop verschijnt bij 0 resultaten + actief filter.
  - "Terug naar gebruikers"-knop bovenin werkt.

- [ ] **Step 4: Eventuele Pint/style-fixes commit.**
  ```bash
  ./vendor/bin/pint app/Livewire/Invitations/ resources/views/livewire/invitations/ tests/Feature/Invitations/IndexTest.php tests/Feature/Users/UserCrudTest.php resources/views/livewire/users/index.blade.php
  git add -p
  git commit -m "style(invitations): apply Pint to new files"
  ```

- [ ] **Step 5: Push.**
  ```bash
  git push origin main
  ```

---

## Spec coverage checklist

| Spec-element | Plan-task |
|---|---|
| Route `/admin/invitations` | Task 1 |
| Component skeleton + sort 3-state | Task 1 |
| Permission gate (`invitations.send`) | Task 1 |
| Filter email (whereHas + ILIKE) | Task 2 |
| Filter naam (OR over first/middle/last) | Task 2 |
| Filter inviter (whereHas + ILIKE) | Task 3 |
| Filter status (4 staten met derivation) | Task 4 |
| Filter datum (`expires_at`, `sent_at`) | Task 5 |
| `status()` helper-method | Task 6 |
| Sort op email/name/inviter (subquery) | Task 6 |
| Sort op status (CASE-expressie) | Task 6 |
| Sanitisatie unknown filter keys | Task 7 |
| Sanitisatie ongeldige status enum | Task 7 |
| Page-reset on filter change | Task 7 |
| Hidden-column filter clear | Task 7 |
| Cancel-action | Task 8 |
| Resend-action | Task 8 |
| Page-reset on sort change | Task 8 |
| `clearFilters()` + `hasNoFilters()` | Task 9 |
| View + partial | Task 9 |
| Knop op users-pagina | Task 10 |
| Embedded PendingList verwijderen | Task 10 |
| InviteUiTest migratie | Task 10 |
| Pint clean | Task 11 |
| Manual smoke | Task 11 |
