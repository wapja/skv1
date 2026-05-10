# Users-table per-column filter & sort — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Geef `App\Livewire\Users\Index` per-kolom filter- en sorteer-functionaliteit, met inline filterrij onder de kolomkoppen en 3-state sortable headers. Alle state in `#[Session]`.

**Architecture:** Pure Livewire-component. Properties: `$filters` (associative array, één key per filterbare kolom), `$sortColumn` (whitelisted of null), `$sortDirection`. Filter-logica in `applyFilters(Builder)`-helper, sort-logica in `applySort(Builder)`-helper, beide aangeroepen vanuit de bestaande `users()`-method. View krijgt sortable `<flux:table.column>` met `wire:click="sort('key')"` en een inline-filter-rij als eerste rij in de tbody, met dispatch naar een nieuwe partial per kolomtype.

**Tech Stack:** Laravel 13, Livewire 4, Flux UI Pro, PostgreSQL 16+ (ILIKE), Pest 4. Bestaande `WithPagination`-trait wordt hergebruikt.

**Spec:** `docs/superpowers/specs/2026-05-10-users-table-filter-sort-design.md`

---

## File Structure

| Pad | Actie | Verantwoordelijkheid |
|---|---|---|
| `app/Livewire/Users/Index.php` | Modify | Component-state + sort/filter-helpers + sanitisatie-hooks |
| `resources/views/livewire/users/index.blade.php` | Modify | Toolbar zonder externe status-select; sortable headers; inline filter-rij; clearFilters-empty-state |
| `resources/views/livewire/users/partials/column-filter.blade.php` | Create | Type-dispatched filter-input per kolomkey |
| `tests/Feature/Users/UserCrudTest.php` | Modify | Migreer status-test; voeg 13 nieuwe tests toe (sort, filter, sanitisatie, hidden-column, reset-page) |

`UserCrudTest.php` heeft al een `beforeEach()` (regels 11-25) die `$this->org`, `$this->actor` (met `organisation_admin`-role), tenant-binding en permission-registrar opzet. **Alle nieuwe tests gaan binnen de bestaande `describe('Users Index Livewire', …)`-block en mogen `$this->actor` en `$this->org` rechtstreeks gebruiken.**

---

## Pre-flight (run once)

- [ ] **Step 0a:** Bevestig dat de huidige test-suite groen is.

  Run: `./vendor/bin/pest tests/Feature/Users/UserCrudTest.php`
  Expected: 21 passed (inclusief 4 paginatie-tests uit de vorige release).

- [ ] **Step 0b:** Bekijk het huidige `app/Livewire/Users/Index.php` (98 regels) en `tests/Feature/Users/UserCrudTest.php` (≥150 regels) zodat je weet welke properties en tests er al zijn. **Niet bewerken.**

---

## Task 1: Sort state + 3-state sort-method

**Files:**
- Modify: `app/Livewire/Users/Index.php`
- Modify: `tests/Feature/Users/UserCrudTest.php`

- [ ] **Step 1: Schrijf vier failing sort-tests.**

  Voeg toe aan `tests/Feature/Users/UserCrudTest.php`, **binnen de bestaande `describe('Users Index Livewire', …)`-block**, na de pagineer-tests:

  ```php
  it('defaults to last_name → first_name when no sort is selected', function () {
      User::factory()->for($this->org)->create(['first_name' => 'Anna',  'last_name' => 'Zilver']);
      User::factory()->for($this->org)->create(['first_name' => 'Bart',  'last_name' => 'Aap']);
      $this->actingAs($this->actor);

      Livewire::test(Index::class)
          ->assertSet('sortColumn', null)
          ->assertViewHas('users', function ($users) {
              $rows = $users->getCollection()->pluck('last_name')->all();
              return $rows[0] === 'Aap' && in_array('Zilver', $rows, true);
          });
  });

  it('sorts by name asc, then desc, then back to default on third click', function () {
      User::factory()->for($this->org)->create(['first_name' => 'A', 'last_name' => 'A']);
      User::factory()->for($this->org)->create(['first_name' => 'Z', 'last_name' => 'Z']);
      $this->actingAs($this->actor);

      $component = Livewire::test(Index::class)
          ->call('sort', 'name')
          ->assertSet('sortColumn', 'name')
          ->assertSet('sortDirection', 'asc')
          ->call('sort', 'name')
          ->assertSet('sortColumn', 'name')
          ->assertSet('sortDirection', 'desc')
          ->call('sort', 'name')
          ->assertSet('sortColumn', null);
  });

  it('sorts by email asc and desc when toggled', function () {
      User::factory()->for($this->org)->create(['email' => 'aaa@demo1.local']);
      User::factory()->for($this->org)->create(['email' => 'zzz@demo1.local']);
      $this->actingAs($this->actor);

      Livewire::test(Index::class)
          ->call('sort', 'email')
          ->assertViewHas('users', fn ($users) => $users->getCollection()->first()->email === 'aaa@demo1.local')
          ->call('sort', 'email')
          ->assertViewHas('users', fn ($users) => $users->getCollection()->first()->email === 'zzz@demo1.local');
  });

  it('clamps an unknown sortColumn back to null', function () {
      $this->actingAs($this->actor);

      Livewire::test(Index::class)
          ->set('sortColumn', 'bogus_field')
          ->assertSet('sortColumn', null);
  });
  ```

- [ ] **Step 2: Run de tests om te zien dat ze falen.**

  Run: `./vendor/bin/pest tests/Feature/Users/UserCrudTest.php --filter="sort"`
  Expected: 4 errors of failures (`sortColumn`, `sortDirection`, `sort`-method bestaan niet).

- [ ] **Step 3: Voeg sort-state, SORTABLE-constant, `sort()` en `applySort()` toe aan `Index.php`.**

  Vervang het bestaande `class Index extends Component { … }`-blok (regel 13-98) **gedeeltelijk** door onderstaande edits.

  **3a — voeg constante en properties toe** (na regel `public const PER_PAGE_OPTIONS …`):

  ```php
  public const SORTABLE = [
      'name', 'email', 'internal_id', 'phone', 'address',
      'start_date', 'end_date', 'status', 'locale',
  ];

  #[Session]
  public ?string $sortColumn = null;

  #[Session]
  public string $sortDirection = 'asc';
  ```

  **3b — voeg `sort()`-method en `applySort()`-helper toe** (boven `render()`):

  ```php
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
      }
  }

  protected function applySort(\Illuminate\Database\Eloquent\Builder $query): void
  {
      if ($this->sortColumn === null) {
          $query->orderBy('last_name')->orderBy('first_name');
          return;
      }
      $direction = $this->sortDirection === 'desc' ? 'desc' : 'asc';
      match ($this->sortColumn) {
          'name'  => $query->orderBy('last_name', $direction)
                           ->orderBy('first_name', $direction),
          default => $query->orderBy($this->sortColumn, $direction),
      };
  }
  ```

  **3c — vervang de huidige `users()` body** (regels 58-66):

  ```php
  public function users()
  {
      $query = User::query();
      $this->applySort($query);

      return $query->paginate($this->perPage);
  }
  ```

  > Let op: deze stap haalt tijdelijk de `statusFilter`-where weg uit de query. Je hebt nog `$statusFilter` en `updatedStatusFilter()` op het component staan — die bestaande "filters users by status"-test zal nu falen. Dat is **expected red** voor Task 4 — niet hier oplossen. De pre-existing test verwacht filtering op status, maar we gaan die in Task 4 migreren naar de nieuwe `$filters`-array.

  Voeg eventueel een import toe als ze er nog niet staan: bestaande `use Livewire\Attributes\Session;` is al aanwezig.

- [ ] **Step 4: Run de sort-tests + ook de status-test om de breuk te zien.**

  Run: `./vendor/bin/pest tests/Feature/Users/UserCrudTest.php --filter="sort|filters users by status"`
  Expected: 4 sort-tests groen; 1 status-test rood (regression — wordt in Task 4 hersteld).

- [ ] **Step 5: Commit.**

  ```bash
  git add app/Livewire/Users/Index.php tests/Feature/Users/UserCrudTest.php
  git commit -m "$(cat <<'EOF'
  feat(users): sortable kolommen op users-overzicht (3-state)

  Voegt sortColumn/sortDirection toe en sort()-method met asc → desc →
  default toggle. applySort() wordt vanuit users() aangeroepen. Whitelist
  in SORTABLE-constante voorkomt SQL-injection via Session-tampering.

  Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
  EOF
  )"
  ```

---

## Task 2: Filter-state + applyFilters voor tekst-kolommen (ILIKE)

**Files:**
- Modify: `app/Livewire/Users/Index.php`
- Modify: `tests/Feature/Users/UserCrudTest.php`

- [ ] **Step 1: Schrijf de failing tekst-filter-test.**

  ```php
  it('filters email/internal_id/phone/address case-insensitively (ILIKE contains)', function () {
      User::factory()->for($this->org)->create(['email' => 'alice@demo1.local', 'phone' => '0612345678', 'internal_id' => 'EMP-1', 'address' => 'Damrak 1, Amsterdam']);
      User::factory()->for($this->org)->create(['email' => 'BOB@demo1.local',   'phone' => '0699999999', 'internal_id' => 'EMP-2', 'address' => 'Coolsingel 5, Rotterdam']);
      User::factory()->for($this->org)->create(['email' => 'carol@other.test',  'phone' => '0611111111', 'internal_id' => 'CON-9', 'address' => 'Stationsplein, Eindhoven']);
      $this->actingAs($this->actor);

      Livewire::test(Index::class)
          ->set('filters.email', 'DEMO1')
          ->assertSee('alice@demo1.local')
          ->assertSee('BOB@demo1.local')
          ->assertDontSee('carol@other.test');

      Livewire::test(Index::class)
          ->set('filters.phone', '0699')
          ->assertSee('BOB@demo1.local')
          ->assertDontSee('alice@demo1.local')
          ->assertDontSee('carol@other.test');

      Livewire::test(Index::class)
          ->set('filters.internal_id', 'emp-')
          ->assertSee('alice@demo1.local')
          ->assertSee('BOB@demo1.local')
          ->assertDontSee('carol@other.test');

      Livewire::test(Index::class)
          ->set('filters.address', 'amsterdam')
          ->assertSee('alice@demo1.local')
          ->assertDontSee('BOB@demo1.local')
          ->assertDontSee('carol@other.test');
  });
  ```

- [ ] **Step 2: Run de test om te zien dat hij faalt.**

  Run: `./vendor/bin/pest tests/Feature/Users/UserCrudTest.php --filter="ILIKE contains"`
  Expected: error — `filters` property bestaat niet.

- [ ] **Step 3: Voeg `$filters`-property en `applyFilters()`-helper toe.**

  **3a — voeg constante en property toe**, na de `SORTABLE`-constante uit Task 1:

  ```php
  public const DEFAULT_FILTERS = [
      'name' => '', 'email' => '', 'internal_id' => '',
      'phone' => '', 'address' => '', 'start_date' => '',
      'end_date' => '', 'status' => '', 'locale' => '',
  ];

  /** @var array<string,string> */
  #[Session]
  public array $filters = self::DEFAULT_FILTERS;
  ```

  **3b — voeg `applyFilters()`-helper toe**, boven `applySort()`:

  ```php
  protected function applyFilters(\Illuminate\Database\Eloquent\Builder $query): void
  {
      foreach ($this->filters as $key => $value) {
          if ($value === '' || $value === null) {
              continue;
          }
          match ($key) {
              'email', 'internal_id', 'phone', 'address'
                  => $query->where($key, 'ILIKE', '%' . $value . '%'),
              default => null,
          };
      }
  }
  ```

  > Andere kolomtypes (name, status, locale, dates) volgen in Task 3-5; in dit task alleen de simpele tekst-kolommen.

  **3c — roep `applyFilters()` aan vanuit `users()`** (toevoegen vlak na `User::query()`):

  ```php
  public function users()
  {
      $query = User::query();
      $this->applyFilters($query);
      $this->applySort($query);

      return $query->paginate($this->perPage);
  }
  ```

- [ ] **Step 4: Run de test.**

  Run: `./vendor/bin/pest tests/Feature/Users/UserCrudTest.php --filter="ILIKE contains"`
  Expected: PASS.

- [ ] **Step 5: Commit.**

  ```bash
  git add app/Livewire/Users/Index.php tests/Feature/Users/UserCrudTest.php
  git commit -m "$(cat <<'EOF'
  feat(users): tekst-filter (ILIKE) op email/internal_id/phone/address

  $filters-array met DEFAULT_FILTERS-constante; applyFilters() draait
  case-insensitief via PostgreSQL ILIKE. Andere kolomtypes volgen in
  vervolg-commits.

  Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
  EOF
  )"
  ```

---

## Task 3: Composite name-filter (OR over first/middle/last)

**Files:**
- Modify: `app/Livewire/Users/Index.php`
- Modify: `tests/Feature/Users/UserCrudTest.php`

- [ ] **Step 1: Schrijf de failing test.**

  ```php
  it('name filter matches first_name, middle_name, or last_name', function () {
      User::factory()->for($this->org)->create(['first_name' => 'Anna',  'middle_name' => null,   'last_name' => 'Zijlstra']);
      User::factory()->for($this->org)->create(['first_name' => 'Bart',  'middle_name' => 'Anna', 'last_name' => 'Pieters']);
      User::factory()->for($this->org)->create(['first_name' => 'Bert',  'middle_name' => null,   'last_name' => 'Anna']);
      User::factory()->for($this->org)->create(['first_name' => 'Carl',  'middle_name' => null,   'last_name' => 'Yssel']);
      $this->actingAs($this->actor);

      Livewire::test(Index::class)
          ->set('filters.name', 'Anna')
          ->assertSee('Zijlstra')
          ->assertSee('Pieters')
          ->assertSee('Bert')
          ->assertDontSee('Yssel');
  });
  ```

- [ ] **Step 2: Run de test om te zien dat hij faalt.**

  Run: `./vendor/bin/pest tests/Feature/Users/UserCrudTest.php --filter="name filter matches"`
  Expected: failure — alle vier de records komen door, want er is nog geen `name`-tak in `applyFilters()`.

- [ ] **Step 3: Breid `applyFilters()` uit met `name`-tak.**

  Voeg de `name`-arm toe aan de bestaande match-statement in `applyFilters()`. De volledige match wordt:

  ```php
  match ($key) {
      'name' => $query->where(function ($q) use ($value) {
          $like = '%' . $value . '%';
          $q->where('first_name',  'ILIKE', $like)
            ->orWhere('middle_name','ILIKE', $like)
            ->orWhere('last_name',  'ILIKE', $like);
      }),
      'email', 'internal_id', 'phone', 'address'
          => $query->where($key, 'ILIKE', '%' . $value . '%'),
      default => null,
  };
  ```

- [ ] **Step 4: Run de test.**

  Run: `./vendor/bin/pest tests/Feature/Users/UserCrudTest.php --filter="name filter matches"`
  Expected: PASS.

- [ ] **Step 5: Commit.**

  ```bash
  git add app/Livewire/Users/Index.php tests/Feature/Users/UserCrudTest.php
  git commit -m "$(cat <<'EOF'
  feat(users): name-filter zoekt in first_name/middle_name/last_name

  Eén filter-input voor de samengestelde Naam-kolom matcht in alle drie
  de naam-kolommen via OR.

  Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
  EOF
  )"
  ```

---

## Task 4: Status- en locale-filter; verwijder oude `$statusFilter`-property

**Files:**
- Modify: `app/Livewire/Users/Index.php`
- Modify: `tests/Feature/Users/UserCrudTest.php`

> Dit is de grootste task. Status migreert van eigen URL-property naar inline
> kolomfilter via `$filters['status']`. De bestaande "filters users by status"-test
> wordt aangepast.

- [ ] **Step 1: Pas de bestaande "filters users by status"-test aan zodat hij `filters.status` gebruikt.**

  Zoek in `tests/Feature/Users/UserCrudTest.php` de bestaande test:
  ```php
  it('filters users by status', function () { … ->set('statusFilter', 'pending_activation') … });
  ```
  Vervang de `set`-regel door:
  ```php
  ->set('filters.status', 'pending_activation')
  ```

- [ ] **Step 2: Voeg een failing locale-filter-test toe.**

  ```php
  it('locale filter limits results to one locale', function () {
      User::factory()->for($this->org)->create(['email' => 'nl1@demo1.local', 'locale' => 'nl']);
      User::factory()->for($this->org)->create(['email' => 'nl2@demo1.local', 'locale' => 'nl']);
      User::factory()->for($this->org)->create(['email' => 'en1@demo1.local', 'locale' => 'en']);
      $this->actingAs($this->actor);

      Livewire::test(Index::class)
          ->set('filters.locale', 'en')
          ->assertSee('en1@demo1.local')
          ->assertDontSee('nl1@demo1.local')
          ->assertDontSee('nl2@demo1.local');
  });
  ```

- [ ] **Step 3: Run beide tests om te zien dat ze falen.**

  Run: `./vendor/bin/pest tests/Feature/Users/UserCrudTest.php --filter="filters users by status|locale filter"`
  Expected: 2 failures (status-test krijgt geen filtering toegepast op `filters.status`; locale-test idem).

- [ ] **Step 4: Verwijder de oude `$statusFilter`-property en `updatedStatusFilter()`-method, en voeg `status`/`locale`-tak toe aan `applyFilters()`.**

  **4a — verwijder uit `Index.php`:**
  ```php
  #[Url(as: 'status')]
  public string $statusFilter = '';
  ```
  En:
  ```php
  public function updatedStatusFilter(): void
  {
      $this->resetPage();
  }
  ```
  Verwijder ook de import als die alleen voor `Url` was:
  ```php
  use Livewire\Attributes\Url;
  ```

  **4b — breid `applyFilters()` uit:**
  ```php
  match ($key) {
      'name' => $query->where(function ($q) use ($value) {
          $like = '%' . $value . '%';
          $q->where('first_name',  'ILIKE', $like)
            ->orWhere('middle_name','ILIKE', $like)
            ->orWhere('last_name',  'ILIKE', $like);
      }),
      'email', 'internal_id', 'phone', 'address'
          => $query->where($key, 'ILIKE', '%' . $value . '%'),
      'status', 'locale'
          => $query->where($key, $value),
      default => null,
  };
  ```

- [ ] **Step 5: Run beide tests + de hele Users-Index-suite om regressies te vangen.**

  Run: `./vendor/bin/pest tests/Feature/Users/UserCrudTest.php --filter="Users Index"`
  Expected: alle Users-Index-tests groen.

- [ ] **Step 6: Commit.**

  ```bash
  git add app/Livewire/Users/Index.php tests/Feature/Users/UserCrudTest.php
  git commit -m "$(cat <<'EOF'
  feat(users): status en locale als inline kolomfilters

  Verwijdert de losse #[Url(as: 'status')] $statusFilter-property; status
  leeft voortaan als entry in $filters. Voegt locale-filter toe.

  BREAKING: bestaande /users?status=active-bookmarks filteren niet meer
  automatisch — status-state staat nu in #[Session].

  Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
  EOF
  )"
  ```

---

## Task 5: Datum-filter (>=)

**Files:**
- Modify: `app/Livewire/Users/Index.php`
- Modify: `tests/Feature/Users/UserCrudTest.php`

- [ ] **Step 1: Schrijf de failing date-filter-test.**

  ```php
  it('start_date filter is "≥" — earlier dates are excluded', function () {
      User::factory()->for($this->org)->create(['email' => 'past@demo1.local',    'start_date' => '2025-01-15']);
      User::factory()->for($this->org)->create(['email' => 'cutoff@demo1.local',  'start_date' => '2026-01-01']);
      User::factory()->for($this->org)->create(['email' => 'future@demo1.local',  'start_date' => '2026-06-01']);
      $this->actingAs($this->actor);

      Livewire::test(Index::class)
          ->set('filters.start_date', '2026-01-01')
          ->assertSee('cutoff@demo1.local')
          ->assertSee('future@demo1.local')
          ->assertDontSee('past@demo1.local');
  });
  ```

- [ ] **Step 2: Run om te zien dat hij faalt.**

  Run: `./vendor/bin/pest tests/Feature/Users/UserCrudTest.php --filter="start_date filter"`
  Expected: failure — alle 3 records zichtbaar.

- [ ] **Step 3: Breid `applyFilters()` uit met date-tak.**

  De volledige match-statement:

  ```php
  match ($key) {
      'name' => $query->where(function ($q) use ($value) {
          $like = '%' . $value . '%';
          $q->where('first_name',  'ILIKE', $like)
            ->orWhere('middle_name','ILIKE', $like)
            ->orWhere('last_name',  'ILIKE', $like);
      }),
      'email', 'internal_id', 'phone', 'address'
          => $query->where($key, 'ILIKE', '%' . $value . '%'),
      'status', 'locale'
          => $query->where($key, $value),
      'start_date', 'end_date'
          => $query->whereDate($key, '>=', $value),
      default => null,
  };
  ```

- [ ] **Step 4: Run de test.**

  Run: `./vendor/bin/pest tests/Feature/Users/UserCrudTest.php --filter="start_date filter"`
  Expected: PASS.

- [ ] **Step 5: Commit.**

  ```bash
  git add app/Livewire/Users/Index.php tests/Feature/Users/UserCrudTest.php
  git commit -m "$(cat <<'EOF'
  feat(users): start_date / end_date filter als 'vanaf' (≥)

  Eén date-input per kolom met whereDate(>=)-semantiek. Filter past in
  smalle inline-cel; matcht het meest gevraagde HR-gebruik.

  Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
  EOF
  )"
  ```

---

## Task 6: `updatedFilters()` sanitisatie + reset-page

**Files:**
- Modify: `app/Livewire/Users/Index.php`
- Modify: `tests/Feature/Users/UserCrudTest.php`

- [ ] **Step 1: Schrijf drie failing tests.**

  ```php
  it('sanitises unknown filter keys from session state', function () {
      $this->actingAs($this->actor);

      Livewire::test(Index::class)
          ->set('filters', ['name' => 'Bob', 'bogus_key' => 'x', 'email' => 'demo'])
          ->assertSet('filters.name', 'Bob')
          ->assertSet('filters.email', 'demo')
          ->tap(function ($component) {
              expect(array_key_exists('bogus_key', $component->get('filters')))->toBeFalse();
          });
  });

  it('clamps invalid status filter values back to empty string', function () {
      $this->actingAs($this->actor);

      Livewire::test(Index::class)
          ->set('filters.status', 'banana')
          ->assertSet('filters.status', '');
  });

  it('resets to page 1 when any filter changes', function () {
      User::factory()->count(30)->for($this->org)->create();
      $this->actingAs($this->actor);

      Livewire::test(Index::class)
          ->call('gotoPage', 2)
          ->assertSet('paginators.page', 2)
          ->set('filters.name', 'x')
          ->assertSet('paginators.page', 1);
  });
  ```

- [ ] **Step 2: Run om te zien dat ze falen.**

  Run: `./vendor/bin/pest tests/Feature/Users/UserCrudTest.php --filter="sanitises unknown filter|clamps invalid status|resets to page 1 when any filter"`
  Expected: 3 failures.

- [ ] **Step 3: Voeg `updatedFilters()` toe aan `Index.php`** (boven `updatedSelectedColumns()` is een logische plek):

  ```php
  public function updatedFilters(): void
  {
      $valid = array_keys(self::DEFAULT_FILTERS);
      $this->filters = array_intersect_key(
          array_merge(self::DEFAULT_FILTERS, $this->filters),
          array_flip($valid),
      );

      if (! in_array($this->filters['status'], ['', 'active', 'pending_activation', 'disabled'], true)) {
          $this->filters['status'] = '';
      }
      if (! in_array($this->filters['locale'], ['', 'nl', 'en'], true)) {
          $this->filters['locale'] = '';
      }

      $this->resetPage();
  }
  ```

- [ ] **Step 4: Run de drie tests.**

  Run: `./vendor/bin/pest tests/Feature/Users/UserCrudTest.php --filter="sanitises unknown filter|clamps invalid status|resets to page 1 when any filter"`
  Expected: PASS.

- [ ] **Step 5: Commit.**

  ```bash
  git add app/Livewire/Users/Index.php tests/Feature/Users/UserCrudTest.php
  git commit -m "$(cat <<'EOF'
  feat(users): sanitisatie en page-reset op filter-mutaties

  updatedFilters() filtert onbekende keys eruit, valideert status- en
  locale-enums, en reset paginatie op pagina 1.

  Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
  EOF
  )"
  ```

---

## Task 7: Hidden-column filter clearing

**Files:**
- Modify: `app/Livewire/Users/Index.php`
- Modify: `tests/Feature/Users/UserCrudTest.php`

- [ ] **Step 1: Schrijf de failing test.**

  ```php
  it('unselecting a column clears its active filter', function () {
      $this->actingAs($this->actor);

      Livewire::test(Index::class)
          ->set('filters.email', 'demo1')
          ->assertSet('filters.email', 'demo1')
          ->set('selectedColumns', ['name', 'status'])
          ->assertSet('filters.email', '');
  });
  ```

- [ ] **Step 2: Run om te zien dat hij faalt.**

  Run: `./vendor/bin/pest tests/Feature/Users/UserCrudTest.php --filter="unselecting a column clears"`
  Expected: failure — `filters.email` blijft `'demo1'`.

- [ ] **Step 3: Pas `updatedSelectedColumns()` aan in `Index.php`.**

  Bestaande implementatie:
  ```php
  public function updatedSelectedColumns(): void
  {
      $valid = array_keys($this->availableColumns());
      $this->selectedColumns = array_values(array_intersect($valid, $this->selectedColumns));
  }
  ```

  Vervang door:
  ```php
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

- [ ] **Step 4: Run de test.**

  Run: `./vendor/bin/pest tests/Feature/Users/UserCrudTest.php --filter="unselecting a column clears"`
  Expected: PASS.

- [ ] **Step 5: Run de hele Users-Index-suite voor extra zekerheid.**

  Run: `./vendor/bin/pest tests/Feature/Users/UserCrudTest.php --filter="Users Index"`
  Expected: alle tests groen.

- [ ] **Step 6: Commit.**

  ```bash
  git add app/Livewire/Users/Index.php tests/Feature/Users/UserCrudTest.php
  git commit -m "$(cat <<'EOF'
  feat(users): wis filter zodra kolom verborgen wordt

  Voorkomt onzichtbare filtering die de gebruiker niet meer kan inspecteren.

  Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
  EOF
  )"
  ```

---

## Task 8: Reset-page-on-sort + sort-page-reset-test (cross-cutting)

**Files:**
- Modify: `tests/Feature/Users/UserCrudTest.php`

> Het `sort()`-method roept al `resetPage()` aan (Task 1, step 3b). Deze task is een
> bewijslast-test om dat gedrag te pinnen.

- [ ] **Step 1: Schrijf de test.**

  ```php
  it('resets to page 1 on sort change', function () {
      User::factory()->count(30)->for($this->org)->create();
      $this->actingAs($this->actor);

      Livewire::test(Index::class)
          ->call('gotoPage', 2)
          ->assertSet('paginators.page', 2)
          ->call('sort', 'email')
          ->assertSet('paginators.page', 1);
  });
  ```

- [ ] **Step 2: Run de test.**

  Run: `./vendor/bin/pest tests/Feature/Users/UserCrudTest.php --filter="resets to page 1 on sort change"`
  Expected: PASS direct (geen impl-wijziging nodig — `sort()` roept al `resetPage()` aan).

- [ ] **Step 3: Commit.**

  ```bash
  git add tests/Feature/Users/UserCrudTest.php
  git commit -m "$(cat <<'EOF'
  test(users): pin reset-page-on-sort gedrag

  Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
  EOF
  )"
  ```

---

## Task 9: View — toolbar, sortable headers, inline filter row, partial, empty-state, clearFilters

**Files:**
- Modify: `app/Livewire/Users/Index.php` (alleen `clearFilters()` + helper-method)
- Modify: `resources/views/livewire/users/index.blade.php`
- Create: `resources/views/livewire/users/partials/column-filter.blade.php`

> Geen nieuwe component-tests; bestaande tests (vooral de filter-tests)
> verifiëren dat de view de juiste model-properties bindt.

- [ ] **Step 1: Voeg `clearFilters()` en `hasNoFilters()` helpers toe aan `Index.php`** (vlak boven `render()`):

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

  Maak `resources/views/livewire/users/partials/column-filter.blade.php` met:

  ```blade
  @switch($key)
      @case('status')
          <flux:select wire:model.live="filters.status" size="sm">
              <option value="">{{ __('Alle') }}</option>
              <option value="active">{{ __('Actief') }}</option>
              <option value="pending_activation">{{ __('Wachtend') }}</option>
              <option value="disabled">{{ __('Uitgeschakeld') }}</option>
          </flux:select>
      @break

      @case('locale')
          <flux:select wire:model.live="filters.locale" size="sm">
              <option value="">{{ __('Alle') }}</option>
              <option value="nl">nl</option>
              <option value="en">en</option>
          </flux:select>
      @break

      @case('start_date')
      @case('end_date')
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

- [ ] **Step 3: Vervang `resources/views/livewire/users/index.blade.php` volledig.**

  Schrijf het nieuwe bestand:

  ```blade
  <div class="space-y-6">
      <div class="flex items-end justify-between gap-4">
          <div>
              <flux:heading size="xl">{{ __('Gebruikers') }}</flux:heading>
              <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
                  {{ __('Beheer de gebruikers van :org.', ['org' => tenant()?->name ?? config('app.name')]) }}
              </flux:text>
          </div>
          @can('create', App\Models\User::class)
              <livewire:invitations.send />
          @endcan
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
              @foreach (App\Livewire\Users\Index::PER_PAGE_OPTIONS as $option)
                  <option value="{{ $option }}">{{ $option }}</option>
              @endforeach
          </flux:select>
      </div>

      @if ($users->total() === 0 && $this->hasNoFilters())
          <flux:callout variant="secondary" icon="users">{{ __('Geen gebruikers gevonden.') }}</flux:callout>
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
                                  @include('livewire.users.partials.column-filter', ['key' => $key])
                              </flux:table.cell>
                          @endif
                      @endforeach
                      <flux:table.cell></flux:table.cell>
                  </flux:table.row>

                  @foreach ($users as $user)
                      <flux:table.row :key="$user->id">
                          @foreach ($columns as $key => $label)
                              @if (in_array($key, $selectedColumns, true))
                                  <flux:table.cell>
                                      @switch($key)
                                          @case('name')
                                              {{ $user->name }}
                                          @break

                                          @case('status')
                                              {{ __($user->status) }}
                                          @break

                                          @case('start_date')
                                          @case('end_date')
                                              {{ $user->{$key}?->format('d-m-Y') }}
                                          @break

                                          @default
                                              {{ $user->{$key} }}
                                      @endswitch
                                  </flux:table.cell>
                              @endif
                          @endforeach
                          <flux:table.cell>
                              <div class="flex gap-2">
                                  @can('update', $user)
                                      <flux:button size="sm" variant="ghost" :href="route('users.edit', $user)" wire:navigate>
                                          {{ __('Bewerken') }}
                                      </flux:button>
                                  @endcan
                                  @if (auth()->user()?->can('users.impersonate') && $user->id !== auth()->id() && ! $user->isSuperAdmin())
                                      <flux:button size="sm" variant="ghost" wire:click="$dispatch('open-impersonate', { userId: {{ $user->id }} })">
                                          {{ __('Impersoneren') }}
                                      </flux:button>
                                  @endif
                                  @can('delete', $user)
                                      <flux:button size="sm" variant="danger" wire:click="delete({{ $user->id }})">
                                          {{ __('Verwijderen') }}
                                      </flux:button>
                                  @endcan
                              </div>
                          </flux:table.cell>
                      </flux:table.row>
                  @endforeach
              </flux:table.rows>
          </flux:table>

          @if ($users->total() === 0)
              <flux:callout variant="secondary" icon="funnel">
                  {{ __('Geen gebruikers met deze filters.') }}
                  <flux:button size="sm" variant="ghost" wire:click="clearFilters">
                      {{ __('Filters wissen') }}
                  </flux:button>
              </flux:callout>
          @endif

          {{ $users->links() }}
      @endif

      <livewire:invitations.pending-list />

      <livewire:users.impersonate />
  </div>
  ```

- [ ] **Step 4: Draai de hele Users-Index-suite om regressies in component-bindings te vangen.**

  Run: `./vendor/bin/pest tests/Feature/Users/UserCrudTest.php`
  Expected: alle tests groen (de bestaande "hides unselected columns…" en "soft-deletes a user via the delete action" rendereren ook de view en zouden falen op markup-fouten).

- [ ] **Step 5: Commit.**

  ```bash
  git add app/Livewire/Users/Index.php resources/views/livewire/users/index.blade.php resources/views/livewire/users/partials/column-filter.blade.php
  git commit -m "$(cat <<'EOF'
  feat(users): inline filterrij + sortable headers + clearFilters-empty-state

  - Externe status-selectbox verwijderd; status filtert nu inline
  - Sortable headers met 3-state chevrons via flux:table.column[sortable]
  - Inline filterrij als eerste tbody-rij; partial dispatcht per kolomtype
  - Filtered-empty-state met 'Filters wissen'-knop
  - clearFilters() / hasNoFilters() helpers op het component

  Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
  EOF
  )"
  ```

---

## Task 10: Final verificatie

**Files:** geen wijzigingen — alleen run-checks.

- [ ] **Step 1: Hele Pest-suite.**

  Run: `./vendor/bin/pest`
  Expected: alle tests groen (was 217+ vóór deze plan; verwacht ≥230 met ~13 nieuwe tests).

- [ ] **Step 2: Pint clean.**

  Run: `./vendor/bin/pint --test app/Livewire/Users/Index.php`
  Expected: PASS.

- [ ] **Step 3: Manual browser-smoke (volgens spec-verificatie).**

  In Herd / `php artisan serve` + browser:
  - Filteren op email werkt en debounced (geen request per toetsaanslag — kijk in Network-tab dat één request afgaat na 300ms stilte).
  - Klik op kop sorteert; tweede klik = desc; derde klik = default.
  - Status-filter in tabelkop filtert resultaten; externe status-selectbox is weg.
  - Kolom verbergen via dropdown wist het bijbehorende filter automatisch.
  - Page-reload behoudt filters + sort (Session).
  - "Filters wissen"-knop verschijnt bij 0 resultaten met actief filter, en wist alles.

- [ ] **Step 4: Stage en commit eventuele kleine markup- of style-fixes uit de smoke-test als één laatste commit.**

  Als de smoke-test geen issues geeft: geen commit nodig, branch is klaar.

  Als er issues waren:
  ```bash
  git add <gewijzigde files>
  git commit -m "fix(users): [korte beschrijving van smoke-test fix]"
  ```

- [ ] **Step 5: Push.**

  ```bash
  git push origin main
  ```

  > User heeft eerder permissie gegeven voor direct-to-main pushes op skv1; bij blokkade laat je het uitvoeren aan de gebruiker via `! git push origin main`.

---

## Spec-coverage checklist (zelf-review)

| Spec-element | Plan-task |
|---|---|
| Filter-placement: inline rij | Task 9 |
| Sort-UX: 3-state | Task 1 |
| Sort-modus: single-column (geen shift-click) | Impliciet — `$sortColumn` is `?string`, niet array |
| Status overlap: extern weg, inline in tabel | Task 4, Task 9 |
| Datumfilter: `>=` | Task 5 |
| Naam sort: last_name → first_name | Task 1 (`applySort` `name`-tak) |
| Naam filter: OR over 3 velden | Task 3 |
| Tekst-operator: ILIKE %x% | Task 2 |
| Persistentie: Session voor alle filters/sort | Tasks 1, 2 (#[Session]) |
| Hidden-column: filter wissen | Task 7 |
| Tekst-debounce: 300ms | Task 9 (partial) |
| Filter-rij plek in HTML: eerste tbody-row | Task 9 |
| `WithPagination` reset-page op filter-mutatie | Task 6 (`updatedFilters` → `resetPage`) |
| `WithPagination` reset-page op sort-mutatie | Task 1 (`sort()` → `resetPage`) + Task 8 (test) |
| Migratie van bestaande "filters users by status"-test | Task 4 step 1 |
| Sanitisatie unknown filter keys | Task 6 |
| Sanitisatie ongeldige status/locale | Task 6 |
| Sanitisatie ongeldige sortColumn | Task 1 (`updatedSortColumn`) |
| Lege staat met "Filters wissen"-knop | Task 9 (`clearFilters` + callout) |

Geen open gaten gevonden.
