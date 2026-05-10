# Users-overzicht — filter en sortering per kolom

**Datum:** 2026-05-10
**Status:** Concept — wacht op review

## Doel

Op `/users` (Livewire-component `App\Livewire\Users\Index`) krijgt elke
zichtbare kolom in de tabel een eigen filter én is elke kolom sorteerbaar.
Filters staan als inline rij direct onder de kolomkoppen. Sorteren gebeurt
door op de kolomkop te klikken (3-state toggle).

## Motivatie

- **Operationele werkbaarheid.** Zonder per-kolom filtering is de pagina
  bij groeiende organisaties (>100 gebruikers) niet meer overzichtelijk.
  Eén globaal status-filter is te beperkt; HR-velden (start_date, locale,
  internal_id) hebben hun eigen zoekbehoefte.
- **Vertrouwd UX-patroon.** Inline filterrij + klikbare koppen is het
  standaardpatroon van admin-tabellen (Excel, Airtable, Linear). Geen
  uitleg nodig, geen verborgen affordances.
- **Bouwt voort op bestaande primitieven.** `Livewire\WithPagination`,
  `#[Session]`, en de `selectedColumns`-sanitiser zijn al in gebruik op
  dezelfde component. Geen nieuwe externe afhankelijkheid.

## Scope

### In scope

1. `app/Livewire/Users/Index.php` — nieuwe properties (`$filters`,
   `$sortColumn`, `$sortDirection`), nieuwe sort-methode, nieuwe
   apply-filters/sort-helpers, vervanging van `->get()` door
   gefilterde/gesorteerde paginate-call (paginate is al ingevoerd in
   eerdere release).
2. Verwijdering van de externe status-selectbox uit de view; status wordt
   één van de inline kolomfilters.
3. `resources/views/livewire/users/index.blade.php` — nieuwe toolbar-layout,
   sortable kolomkoppen, inline filterrij, partial-include voor de
   filter-input per kolomtype.
4. Nieuwe partial `resources/views/livewire/users/partials/column-filter.blade.php`.
5. Tests in `tests/Feature/Users/UserCrudTest.php` — bestaand status-filter
   omschrijven, dertien nieuwe tests voor sort/filter-gedrag (zie sectie
   *Tests*).
6. Update `tests/Feature/Users/UserCrudTest.php` "hides unselected
   columns…" om te dekken dat een verborgen kolom zijn filter wist.

### Out of scope

- Multi-column sort (shift-click). Single-column sort is voldoende voor
  een starter kit.
- Save-presets / opgeslagen filterprofielen.
- DB-indexen op `last_name`, `email`, etc. — niet kritisch op verwachte
  schaal; later toe te voegen als measurable.
- Server-side fuzzy search (trigram-index, `pg_trgm`) — pas als latency
  een probleem wordt.
- URL-bookmarkable filters/sort. Bewuste keuze: alle state in `#[Session]`,
  consistent met `perPage` en `selectedColumns`.
- Filteren op kolommen die niet via `availableColumns()` worden
  aangeboden (bijv. `is_super_admin`, `organisation_id`).

## Beslissingen

| Beslissing | Keuze | Reden |
|---|---|---|
| Filter-placement | Inline rij onder kolomkoppen (optie A) | Excel-stijl; alle filters meteen zichtbaar; snelste interactie. |
| Sort-UX | Klikbare kop, 3-state (asc → desc → default) | Standaard, vertrouwd, geen extra controls. |
| Sort-modus | Single-column | Eenvoudiger query en UI; YAGNI op multi-sort. |
| Status-filter overlap | Externe selectbox vervalt; status wordt inline kolomfilter | Eén bron van waarheid; geen sync-logica. |
| Datumfilter-operator | "Vanaf" (`>=`) op één date-input | Past in smalle cel; meest gevraagde semantiek voor HR-data. |
| Naam-kolom sort | `last_name` primair, `first_name` secundair, in beide richtingen | Nederlandse conventie; matcht huidige default. |
| Naam-kolom filter | OR over `first_name`, `middle_name`, `last_name` | Gebruiker weet niet welk veld iemand's naam-deel bevat. |
| Tekst-operator | `ILIKE %x%` (case-insensitive contains) | PostgreSQL-native; geen apart `lower()` nodig. |
| Persistentie | `#[Session]` voor alle filters + sort | Consistent met `perPage`, `selectedColumns`. |
| Hidden-column gedrag | Filter wissen zodra kolom uit `selectedColumns` verdwijnt | Voorkomt onzichtbare filtering. |
| Tekst-debounce | `wire:model.live.debounce.300ms` | Geen full roundtrip per toetsaanslag, blijft responsief. |
| Filter-rij plaatsing in HTML | Eerste rij in `<flux:table.rows>` (tbody) met sticky styling | `<flux:table.columns>` rendert maar één `<tr>`; tweede header-rij niet ondersteund. |

## Architectuur

### Component-state

```php
namespace App\Livewire\Users;

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
        'name', 'email', 'internal_id', 'phone', 'address',
        'start_date', 'end_date', 'status', 'locale',
    ];

    public const DEFAULT_FILTERS = [
        'name' => '', 'email' => '', 'internal_id' => '',
        'phone' => '', 'address' => '', 'start_date' => '',
        'end_date' => '', 'status' => '', 'locale' => '',
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
    public array $selectedColumns = ['name', 'email', 'status'];
}
```

De bestaande `#[Url(as: 'status')] public string $statusFilter` wordt
verwijderd; de status-state leeft voortaan in `$filters['status']`.

### Sort-methode

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
```

### Update-hooks

```php
public function updatedFilters(): void
{
    $valid = array_keys(self::DEFAULT_FILTERS);
    $this->filters = array_intersect_key(
        array_merge(self::DEFAULT_FILTERS, $this->filters),
        array_flip($valid),
    );

    $this->filters['status'] = in_array(
        $this->filters['status'], ['', 'active', 'pending_activation', 'disabled'], true
    ) ? $this->filters['status'] : '';

    $this->filters['locale'] = in_array(
        $this->filters['locale'], ['', 'nl', 'en'], true
    ) ? $this->filters['locale'] : '';

    $this->resetPage();
}

public function updatedSortColumn(): void
{
    if (! in_array($this->sortColumn, self::SORTABLE, true)) {
        $this->sortColumn = null;
    }
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

Regel: **elke filter waarvan de kolom niet langer in `$selectedColumns`
zit, wordt op `''` gezet** — geen uitzonderingen.

`updatedPerPage()` blijft zoals nu. `updatedStatusFilter()` vervalt
(property bestaat niet meer; vervangen door entry in `$filters`).

### Query-laag

```php
public function users()
{
    $query = User::query();
    $this->applyFilters($query);
    $this->applySort($query);

    return $query->paginate($this->perPage);
}

protected function applyFilters(Builder $query): void
{
    foreach ($this->filters as $key => $value) {
        if ($value === '' || $value === null) {
            continue;
        }
        match ($key) {
            'name' => $query->where(function ($q) use ($value) {
                $like = '%' . $value . '%';
                $q->where('first_name',  'ILIKE', $like)
                  ->orWhere('middle_name','ILIKE', $like)
                  ->orWhere('last_name', 'ILIKE', $like);
            }),
            'email', 'internal_id', 'phone', 'address'
                => $query->where($key, 'ILIKE', '%' . $value . '%'),
            'status', 'locale'
                => $query->where($key, $value),
            'start_date', 'end_date'
                => $query->whereDate($key, '>=', $value),
        };
    }
}

protected function applySort(Builder $query): void
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

### View `users/index.blade.php` — nieuwe structuur

```blade
<div class="space-y-6">
    {{-- Header (ongewijzigd) --}}
    <div class="flex items-end justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Gebruikers') }}</flux:heading>
            <flux:text>…</flux:text>
        </div>
        @can('create', App\Models\User::class)
            <livewire:invitations.send />
        @endcan
    </div>

    {{-- Toolbar-rij: status verhuist naar tabel; alleen kolommen + perPage hier --}}
    <div class="flex items-end justify-between gap-4">
        <flux:dropdown>{{-- kolommen-checkboxgroep, ongewijzigd --}}</flux:dropdown>
        <flux:select wire:model.live="perPage" label="{{ __('Per pagina') }}">…</flux:select>
    </div>

    @if ($users->total() === 0 && $this->hasNoFilters())
        <flux:callout variant="secondary" icon="users">
            {{ __('Geen gebruikers gevonden.') }}
        </flux:callout>
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
                <flux:table.row class="bg-zinc-50/60 dark:bg-white/5 sticky top-0 z-10">
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
                    <flux:table.row :key="$user->id">…</flux:table.row>
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
</div>
```

`clearFilters()` op de component zet `$filters = self::DEFAULT_FILTERS`
en roept `resetPage()` aan.

### Partial `users/partials/column-filter.blade.php`

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

## Tests

### Aan te passen

- `it('filters users by status', …)` — `set('statusFilter', …)` wordt
  `set('filters.status', 'pending_activation')`. Assertions ongewijzigd.
- `it('hides unselected columns and shows selected ones', …)` —
  uitbreiden of een aparte test toevoegen die pinning is voor het
  hidden-column-clear-gedrag (zie #10 hieronder).

### Toe te voegen

| # | Test (it-zin) |
|---|---|
| 1 | `defaults to last_name → first_name when no sort is selected` |
| 2 | `sorts by name asc, then desc, then back to default on third click` |
| 3 | `sorts by email asc and desc when toggled` |
| 4 | `clamps an unknown sortColumn back to null` |
| 5 | `filters email/internal_id/phone/address case-insensitively (ILIKE contains)` |
| 6 | `name filter matches first_name, middle_name, or last_name` |
| 7 | `inline status filter limits results to one status` |
| 8 | `locale filter limits results to one locale` |
| 9 | `start_date filter is "≥" — earlier dates are excluded` |
| 10 | `unselecting a column clears its active filter` |
| 11 | `sanitises unknown filter keys from session state` |
| 12 | `resets to page 1 when any filter changes` |
| 13 | `resets to page 1 on sort change` |

Patroon-voorbeeld:
```php
it('name filter matches first_name, middle_name, or last_name', function () {
    User::factory()->for($this->org)->create(['first_name' => 'Anna',  'last_name' => 'Zijlstra']);
    User::factory()->for($this->org)->create(['first_name' => 'Bart',  'middle_name' => 'van', 'last_name' => 'Anna']);
    User::factory()->for($this->org)->create(['first_name' => 'Carl',  'last_name' => 'Yssel']);

    $this->actingAs($this->actor);

    Livewire::test(Index::class)
        ->set('filters.name', 'Anna')
        ->assertSee('Zijlstra')
        ->assertSee('van Anna')
        ->assertDontSee('Yssel');
});
```

### Onaangeroerd

Alle tenancy-, policy-, edit-, impersonation-, en activity-tests blijven
onveranderd. De `selectedColumns`-sanitiser-test (regel 80-86) blijft.

## Risico's en aandachtspunten

1. **Backwards-compat van URL-bookmarks.** `/users?status=active` filtert
   na deze release niet meer automatisch. Bewuste regressie van het
   Session-besluit. Documenteren in changelog.
2. **ILIKE is PG-only.** MySQL-fallback is locked out-of-scope per
   `project_skv1`. Geen actie nodig, wel benoemen.
3. **Sticky filter-rij** (`sticky top-0`) werkt binnen Flux's
   `<ui-table-scroll-area class="overflow-auto">`. Bij horizontaal scrollen
   schuift hij mee; verticaal blijft hij plakken. Acceptabel.
4. **Geen DB-indexen op de gefilterde tekstkolommen.** Op realistische
   schaal (per-tenant <10k users) niet kritisch. `pg_trgm` + GIN als
   latency ooit een probleem wordt.
5. **Session-bloat.** ~10 keys extra per gebruiker. Verwaarloosbaar; per
   user-record in de session-tabel.
6. **Tenant-scope** blijft 100% intact. `User::query()` past de
   `BelongsToOrganisation`-scope automatisch toe; de bestaande
   "hides users from other organisations"-test pinnt dit.
7. **Sort op `name`** doet twee `orderBy`'s. Geen prestatieprobleem op
   verwachte sizes; te indexeren als nodig (composite op
   `(last_name, first_name)`).
8. **Locale-filter overlap met `selectedColumns`.** Gebruiker kan locale
   uit de zichtbare kolommen halen — dan verdwijnt ook de filter-input én
   wordt de filter automatisch gewist (zie hidden-column-besluit).

## Verificatie

- `./vendor/bin/pest tests/Feature/Users/UserCrudTest.php` slaagt — bestaand
  + dertien nieuwe tests.
- `./vendor/bin/pest` (volledige suite) slaagt.
- `./vendor/bin/pint --test` schoon op
  `app/Livewire/Users/Index.php`, `resources/views/livewire/users/index.blade.php`,
  en `resources/views/livewire/users/partials/column-filter.blade.php`.
- Handmatige check in browser:
  - Filteren op email werkt en debounced (geen request per toetsaanslag).
  - Klik op kop sorteert; tweede klik = desc; derde klik = default.
  - Status-filter in tabelkop filtert resultaten; externe status-selectbox
    is weg.
  - Kolom verbergen via dropdown wist het bijbehorende filter
    automatisch.
  - Page-reload behoudt filters + sort via session.
  - Pagination werkt onder elke filter-combinatie; resetten gebeurt op
    elke filter- of sort-mutatie.

## Open vragen

Geen.
