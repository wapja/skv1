# Uitgenodigde gebruikers — aparte pagina met filter/sort/paginatie

**Datum:** 2026-05-10
**Status:** Concept — wacht op review

## Doel

Een nieuwe pagina `/admin/invitations` toont alle Invitation-records van de
huidige organisatie (open, geaccepteerd, verlopen, ingetrokken). De pagina
volgt het filter/sort/paginatie-patroon van `App\Livewire\Users\Index`.
De huidige `<livewire:invitations.pending-list />` wordt van de
users-pagina verwijderd; al die functionaliteit verhuist naar de nieuwe
pagina.

## Motivatie

- **Operationele zichtbaarheid:** invitation-historie zit nu onderaan de
  users-pagina, beperkt tot openstaande invites. Geen inzicht in
  geaccepteerd / verlopen / ingetrokken.
- **Consistentie:** één pagina-patroon (inline filterrij, sortable
  headers, paginatie, kolommen-dropdown) hergebruikt voor invitations.
- **Scheiding van zorgen:** users-pagina focust op actieve gebruikers.
  Invitations krijgt eigen pagina met eigen filters die specifiek zijn
  voor invitation-state (status afgeleid uit
  `accepted_at`/`expires_at`/`user.deleted_at`).

## Scope

### In scope

1. **Route** `/admin/invitations` → `App\Livewire\Invitations\Index`
   (name: `invitations.index`).
2. **Nieuwe Livewire-component** `App\Livewire\Invitations\Index`,
   mirror van `Users\Index`: `$filters`/`$sortColumn`/`$sortDirection`/
   `$perPage`/`$selectedColumns` in `#[Session]`, alle bijbehorende
   sanitisatie-hooks, `sort()`/`clearFilters()`/`hasNoFilters()`,
   `applyFilters(Builder)` + `applySort(Builder)` helpers,
   `cancel()`/`resend()` acties (migreren van `PendingList`).
3. **View** `resources/views/livewire/invitations/index.blade.php`
   gestructureerd als `users/index.blade.php`.
4. **Partial** `resources/views/livewire/invitations/partials/column-filter.blade.php`
   met type-dispatch per kolomkey.
5. **Knop** "Uitgenodigde gebruikers" (ghost-variant) op de users-pagina,
   in het header, **vóór** de invite-knop (`<livewire:invitations.send />`).
6. **Verwijderen** van `<livewire:invitations.pending-list />` uit
   `resources/views/livewire/users/index.blade.php`.
7. **Tests** voor de nieuwe component: filter (per kolom), sort (per
   kolom, 3-state), status-afleiding (4 staten), sanitisatie,
   reset-page, hidden-column-clear, cancel-action, resend-action.
8. **Migratie** van bestaande PendingList-tests die niet meer slagen
   omdat de component verdwijnt: of verwijderd, of geport naar
   `Invitations\Index`.

### Out of scope

- Wijzigingen aan `App\Models\Invitation`, `App\Services\InvitationService`,
  of de invitation-creation-flow (`App\Livewire\Invitations\Send` blijft).
- Sidebar-link of breadcrumb-aanpassingen.
- URL-bookmarkable filters/sort (Session-only, conform users-pagina).
- Bulk-acties (multi-select annulering etc.).
- Export naar CSV.
- Een aparte `App\Policies\InvitationPolicy` (we leunen op de bestaande
  `invitations.send` / `invitations.cancel` permissies).

## Beslissingen

| Beslissing | Keuze | Reden |
|---|---|---|
| Data-bron | Alle Invitation-records met afgeleide status | Volledige historie: open, geaccepteerd, verlopen, ingetrokken |
| Embedded PendingList | Verwijderen uit users-pagina | Eén bron van waarheid; geen duplicate-UI |
| Permission gate | `invitations.send` | Wie mag uitnodigen mag de lijst zien — geen nieuwe seeder-wijziging |
| Route | `/admin/invitations` (name `invitations.index`) | Past bij `/admin/{users,roles,organisations}` |
| Knop op users-pagina | "Uitgenodigde gebruikers", ghost, vóór invite-knop | Secundaire actie naast primaire invite |
| Page heading | "Uitgenodigde gebruikers" + "Verzonden uitnodigingen voor :org." | Consistent met users-pagina |
| Default zichtbare kolommen | `email`, `name`, `status` | Gebruiker's keuze |
| Optionele kolommen via dropdown | `inviter`, `expires_at`, `sent_at` | Aanvragbaar via Kolommen-dropdown |
| Status-afleiding | 4 staten via match in Builder + helper-method | Geen kolom in DB; berekend uit `accepted_at`/`expires_at`/`user.deleted_at` |
| Filter relatie-kolommen | `whereHas('user'/'inviter', fn ILIKE)` | ORM-friendly, geen expliciete joins |
| Sort relatie-kolommen | `orderBy(subquery)` met `User::withTrashed()->select(...)` | Werkt zonder joins, includes cancelled |
| Sort op status | `orderByRaw` met CASE-expressie | Status is afgeleid; subquery niet praktisch |
| `cancelled`-state detectie | `whereHas('user', onlyTrashed())` | Past bij `InvitationService::cancel()` die user soft-deletes |
| Inline per-row acties | "Herinnering" + "Intrekken" behouden | Migreert uit `PendingList` |
| Default sort | `created_at desc` (nieuwste eerst) | Standaard verwachting bij historielijst |
| Datum-filter operator | `>=` ("vanaf") | Consistent met users-pagina |
| Tekst-debounce | 300ms | Consistent met users-pagina |

## Architectuur

### Routes

In `routes/web.php`, in dezelfde groep waar `users.index` etc. zitten:

```php
use App\Livewire\Invitations\Index as InvitationIndex;

Route::get('/admin/invitations', InvitationIndex::class)->name('invitations.index');
```

### Component-staat

```php
namespace App\Livewire\Invitations;

class Index extends Component
{
    use WithPagination;

    public const PER_PAGE_OPTIONS = [5, 10, 25, 50, 100];

    public const SORTABLE = ['email', 'name', 'status', 'inviter', 'expires_at', 'sent_at'];

    public const STATUSES = ['pending', 'accepted', 'expired', 'cancelled'];

    public const DEFAULT_FILTERS = [
        'email'      => '',
        'name'       => '',
        'status'     => '',
        'inviter'    => '',
        'expires_at' => '',
        'sent_at'    => '',
    ];

    #[Session] public array $filters = self::DEFAULT_FILTERS;
    #[Session] public ?string $sortColumn = null;
    #[Session] public string $sortDirection = 'asc';
    #[Session] public int $perPage = 10;
    #[Session] public array $selectedColumns = ['email', 'name', 'status'];
}
```

### `availableColumns()`

```php
return [
    'email'      => __('E-mailadres'),
    'name'       => __('Naam'),
    'status'     => __('Status'),
    'inviter'    => __('Uitgenodigd door'),
    'expires_at' => __('Verloopt op'),
    'sent_at'    => __('Verzonden op'),
];
```

### Lifecycle-hooks (mirror Users\Index)

`sort()`, `updatedSortColumn()`, `updatedFilters()`,
`updatedSelectedColumns()` (incl. hidden-column-filter-clear),
`updatedPerPage()`, `clearFilters()`, `hasNoFilters()` —
volgen 1-op-1 het patroon van `Users\Index` met aangepaste
sanitisatie:

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
```

### Cancel/resend acties (migreren van PendingList)

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
public function refresh(): void { /* trigger re-render */ }
```

### Query-laag

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

protected function applyFilters(Builder $query): void
{
    foreach ($this->filters as $key => $value) {
        if ($value === '' || $value === null) continue;

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

            'inviter' => $query->whereHas('inviter', fn ($q) => $q
                ->where('email', 'ILIKE', '%'.$value.'%')),

            'status' => $this->applyStatusFilter($query, $value),

            'expires_at' => $query->whereDate('expires_at', '>=', $value),
            'sent_at'    => $query->whereDate('invitations.created_at', '>=', $value),

            default => null,
        };
    }
}

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

protected function applySort(Builder $query): void
{
    if ($this->sortColumn === null) {
        $query->orderByDesc('invitations.created_at');
        return;
    }

    $direction = $this->sortDirection === 'desc' ? 'desc' : 'asc';

    match ($this->sortColumn) {
        'email' => $query->orderBy(
            User::withTrashed()->select('email')->whereColumn('users.id', 'invitations.user_id'),
            $direction
        ),

        'name' => $query
            ->orderBy(User::withTrashed()->select('last_name')->whereColumn('users.id', 'invitations.user_id'), $direction)
            ->orderBy(User::withTrashed()->select('first_name')->whereColumn('users.id', 'invitations.user_id'), $direction),

        'inviter' => $query->orderBy(
            User::query()->select('email')->whereColumn('users.id', 'invitations.invited_by'),
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

        'expires_at' => $query->orderBy('invitations.expires_at', $direction),

        'sent_at' => $query->orderBy('invitations.created_at', $direction),

        default => null,
    };
}

public function status(Invitation $invitation): string
{
    if ($invitation->accepted_at !== null)            return 'accepted';
    if ($invitation->user?->trashed())                 return 'cancelled';
    if ($invitation->expires_at?->isPast())            return 'expired';
    return 'pending';
}
```

### View structuur (mirror users/index.blade.php)

```blade
<div class="space-y-6">
    <div class="flex items-end justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Uitgenodigde gebruikers') }}</flux:heading>
            <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
                {{ __('Verzonden uitnodigingen voor :org.', ['org' => tenant()?->name ?? config('app.name')]) }}
            </flux:text>
        </div>
        @can('invitations.send')
            <flux:button :href="route('users.index')" variant="ghost" wire:navigate>
                {{ __('Terug naar gebruikers') }}
            </flux:button>
        @endcan
    </div>

    <div class="flex items-end justify-between gap-4">
        <flux:dropdown>{{-- Kolommen-dropdown --}}</flux:dropdown>
        <flux:select wire:model.live="perPage" label="{{ __('Per pagina') }}">…</flux:select>
    </div>

    @if ($invitations->total() === 0 && $this->hasNoFilters())
        <flux:callout variant="secondary" icon="envelope">{{ __('Er staan geen uitnodigingen.') }}</flux:callout>
    @else
        <flux:table>
            <flux:table.columns>
                @foreach ($columns as $key => $label)
                    @if (in_array($key, $selectedColumns, true))
                        <flux:table.column sortable :sorted="$sortColumn === $key" :direction="$sortColumn === $key ? $sortDirection : null" wire:click="sort('{{ $key }}')" style="cursor:pointer">
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
                <flux:button size="sm" variant="ghost" wire:click="clearFilters">{{ __('Filters wissen') }}</flux:button>
            </flux:callout>
        @endif

        {{ $invitations->links() }}
    @endif
</div>
```

> **Acties-cel:** Herinnering/Intrekken zijn alleen zichtbaar voor
> `pending`-invitations. Geaccepteerde/verlopen/ingetrokken hebben geen
> zinvolle acties.

### Partial `invitations/partials/column-filter.blade.php`

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
        <flux:input type="date" wire:model.live.debounce.300ms="filters.{{ $key }}" size="sm" />
    @break

    @default
        <flux:input wire:model.live.debounce.300ms="filters.{{ $key }}" placeholder="{{ __('Bevat…') }}" size="sm" />
@endswitch
```

### Wijziging op users-pagina (`resources/views/livewire/users/index.blade.php`)

In de header (regel ~9-11), **vóór** de bestaande
`<livewire:invitations.send />`:

```blade
@can('invitations.send')
    <flux:button :href="route('invitations.index')" variant="ghost" wire:navigate>
        {{ __('Uitgenodigde gebruikers') }}
    </flux:button>
@endcan
@can('create', App\Models\User::class)
    <livewire:invitations.send />
@endcan
```

En aan het einde van de view: verwijder de regel
`<livewire:invitations.pending-list />`.

## Tests

### Te verwijderen

`tests/Feature/Invitations/InviteUiTest.php` — alle tests die specifiek
op `App\Livewire\Invitations\PendingList` mikken (cancel-action,
resend-action, render-empty-state). Equivalente tests komen op
`Invitations\Index`. Tests over `Send`-component blijven.

### Toe te voegen op `App\Livewire\Invitations\Index`

| # | Test (it-zin) |
|---|---|
| 1 | `defaults to created_at desc when no sort is selected` |
| 2 | `lists invitations of the current organisation only` |
| 3 | `derives status from accepted_at / expires_at / user.deleted_at` (4 cases) |
| 4 | `filters email case-insensitively via ILIKE contains` |
| 5 | `name filter matches first_name, middle_name, or last_name on related user` |
| 6 | `inviter filter matches inviter email` |
| 7 | `status filter (pending) shows only open + active-user invitations` |
| 8 | `status filter (cancelled) shows only invitations with soft-deleted user` |
| 9 | `expires_at filter is "≥"` |
| 10 | `sent_at filter is "≥" on created_at` |
| 11 | `sorts by email, name, inviter, expires_at, sent_at asc and desc` |
| 12 | `sorts by status with stable cycle (pending → expired → cancelled or alphabetical)` |
| 13 | `clamps an unknown sortColumn back to null` |
| 14 | `sanitises unknown filter keys from session state` |
| 15 | `clamps invalid status filter values back to empty string` |
| 16 | `resets to page 1 when any filter changes` |
| 17 | `resets to page 1 on sort change` |
| 18 | `unselecting a column clears its active filter` |
| 19 | `cancel-action soft-deletes the user (delegates to InvitationService)` |
| 20 | `resend-action calls InvitationService::resendReminder` |
| 21 | `forbids users without invitations.send permission` |

### Aan te passen in users-tests

`tests/Feature/Users/UserCrudTest.php` — als er tests zijn die expliciet
de embedded PendingList rendering of acties testen via
`Livewire::test(Users\Index::class)`, deze migreren naar
`Invitations\Index`. Vermoedelijk zijn er geen — de PendingList werd via
de embed niet door Users-tests geraakt.

## Risico's en aandachtspunten

1. **`User::withTrashed()` in subquery-sort** — moet de
   BelongsToOrganisation-scope niet verbreken. De global scope past
   automatisch `organisation_id`-filter toe; voor cancelled-invitations
   is de user soft-deleted maar staat nog wel in de juiste org. Risico:
   als super-admin op apex-host queryt zonder tenant-binding, is er
   geen org-scope — alle invitations zichtbaar. Conform users-pagina
   gedrag.
2. **Sort op status** gebruikt een ruwe SQL-CASE met een subquery op
   `users.deleted_at`. PostgreSQL-only in deze vorm. Locked OK per
   `project_skv1`.
3. **Whitespace-flikkering bij `whereDate('invitations.created_at')`**
   — kolom-naam met tabel-prefix is nodig omdat na de joins via
   subqueries Eloquent ambigue kolommen kan rapporteren. Test vooral
   met sort + filter actief tegelijk.
4. **`InviteUiTest.php`-migratie:** zorg dat de PendingList-specifieke
   tests in dezelfde commit verdwijnen als de PendingList-embed in de
   view. Anders breekt de suite.
5. **Empty-state semantiek:** als er invitations zijn maar geen
   pending, dan is de "Geen uitnodigingen"-callout niet correct — we
   gebruiken `total() === 0 && hasNoFilters()`. Bij actieve filters
   tonen we de "geen met deze filters"-callout. Conform users-pagina.
6. **Accept-flow nu nog gemonitord via PendingList?** Nee — de
   PendingList toont alleen openstaande. Geen verlies aan
   functionaliteit door de migratie.
7. **Translations** — alle Dutch strings via `__()`. Statuslabels
   (`pending`, `accepted`, `expired`, `cancelled`) zijn momenteel
   onvertaald in `lang/`-files maar gebruiken `__()` voor toekomstige
   translation. Acceptabel.
8. **Tenant-scope op cancelled invitations** — de gekoppelde user is
   soft-deleted maar staat nog in de tenant-org. `whereHas('user', fn $q
   => $q->onlyTrashed())` triggert de User-global-scope met tenant
   binding én de SoftDeletes-scope-uit. Werkt correct.

## Verificatie

- `./vendor/bin/pest tests/Feature/Invitations/` slaagt — bestaand
  (Send-tests) + nieuw.
- `./vendor/bin/pest tests/Feature/Users/UserCrudTest.php` slaagt —
  bestaand minus eventuele PendingList-specifieke checks.
- `./vendor/bin/pest` (volledige suite) slaagt.
- `./vendor/bin/pint --test app/Livewire/Invitations/Index.php
  resources/views/livewire/invitations/ tests/Feature/Invitations/` —
  schoon.
- Handmatige browser-check:
  - `/admin/invitations` rendert de pagina; toolbar werkt; filterrij
    onder kop; sortable koppen.
  - Status-filter toont per state correct (pending/accepted/expired/cancelled).
  - Email/Naam/Inviter ILIKE-filters werken case-insensitief.
  - Datum-filters `>=` werken op verloop-datum en verzend-datum.
  - Sortering werkt op alle kolommen, ook status (CASE-expressie).
  - Page-reload behoudt filters + sort (Session).
  - "Filters wissen"-knop verschijnt bij 0 resultaten + actief filter.
  - Knop "Uitgenodigde gebruikers" op users-pagina (vóór invite-knop)
    navigeert naar de nieuwe pagina.
  - Embedded PendingList is verdwenen van users-pagina.
  - "Herinnering" en "Intrekken" werken alleen voor pending-rijen.

## Open vragen

Geen.
