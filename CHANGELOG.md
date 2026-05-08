# Changelog

Alle noemenswaardige wijzigingen aan dit project. Versies volgen [Semantic Versioning](https://semver.org).

## [0.1.4] — 2026-05-08

### Added

- **User profile fields** — vervangt de enkele `users.name` kolom met
  `first_name`, `middle_name` (Nederlands tussenvoegsel), `last_name`, en
  voegt `internal_id`, `phone`, `address`, `start_date`, `end_date` toe.
- `User::name` blijft bruikbaar als computed accessor (`Attribute::get`)
  die de drie naamvelden samenstelt — bestaande `auth()->user()->name`
  call-sites (sidebar, dashboard, profile widget) blijven werken zonder
  view-aanpassingen.
- Empty-string-to-null conversie in attribute setters voor `middle_name`,
  `internal_id`, `phone`, `address`, `end_date` — Livewire stuurt altijd
  strings, dus de model handelt het automatisch af voor elke writer.
- `UpdateUserRequest` validatieregels uitgebreid met de nieuwe velden;
  `end_date` mag niet vóór `start_date` liggen.
- Admin edit-form (`/admin/users/{user}/edit`) met alle nieuwe inputs
  in een 12-koloms naam-grid plus 2-koloms grids voor optionele velden.
- 8 nieuwe NL-vertaalsleutels in `lang/nl.json` (Voornaam, Tussenvoegsel,
  Achternaam, Personeelsnummer, Telefoon, Adres, Indiensttredingsdatum,
  Uitdiensttredingsdatum).
- Regression test in `tests/Feature/Users/UserProfileFieldsTest.php`
  (factory-completeness, name-accessor compositie, end_date validatie,
  empty-string-to-null persistentie).
- `tests/Unit/Migrations/SchemaTest.php` uitgebreid met assertions voor
  de 8 nieuwe kolommen en de afwezigheid van de `name` kolom.

### Changed

- `Users/Index` sorteert nu op `last_name`, dan `first_name` (NL conventie).
- `InvitationService::invite()` schrijft placeholder-waarden voor
  `first_name` (e-mail local-part) en `last_name = '(uit te nodigen)'` zodat
  de NOT NULL constraints worden gerespecteerd; admin (of een toekomstige
  geüpgrade Activate-flow) vervangt de placeholders.
- `DemoUsersSeeder` gebruikt expliciete `first_name`/`last_name`/`start_date`
  per demo-user.

### Fixed

- **Mail-config in `.env.example`** — Herd Pro's mail-service draait op poort
  **2525** (niet de Mailpit-default 1025). `MAIL_PORT`, `MAIL_USERNAME`,
  `MAIL_ENCRYPTION` aangepast zodat queued mails out-of-the-box werken.
  Handleiding-troubleshooting kreeg een matching entry voor projecten die
  nog op de oude config staan.

### Notes

- **Migratie-strategie:** `2026_05_08_180000_add_profile_fields_to_users_table`
  draait in vier fases: kolommen toevoegen als nullable, bestaande `name`
  splitsen op whitespace (eerste woord → `first_name`, laatste → `last_name`,
  rest → `middle_name`), tighten naar NOT NULL voor required velden, drop
  van `name`. De `down()` is een symmetrische rondtrip. Voor projecten
  gegenereerd vóór v0.1.4: cherry-pick deze migratie + de model/factory/
  seeder/Edit/blade/lang aanpassingen.
- **Out of scope (deferred):** activatie-formulier laat nog geen invitee
  zelf naam invullen — invitees activeren met placeholder-namen die een
  admin via UserEdit corrigeert. Volgende milestone.

## [0.1.3] — 2026-05-08

### Added

- Sidebar navigation: links naar **Gebruikers**, **Rollen**, **Activiteit** en
  **Organisaties** in `resources/views/components/layouts/app.blade.php`. Elke
  link is gegate met `@can(...)` op de bijbehorende permissie, zodat een
  organisation_admin de eerste drie ziet en de super-admin alle vier.
- NL-vertalingen voor de nieuwe menu-items in `lang/nl.json`.
- Regression test in `tests/Feature/Navigation/SidebarMenuTest.php` (3 cases:
  organisation_admin, regular user, super-admin).

### Notes

- Voor projecten gegenereerd vóór v0.1.3: kopieer de `<flux:navlist>`-sectie
  uit de nieuwe `app.blade.php` en voeg de vier translation-keys toe aan
  `lang/nl.json`. De admin-routes zelf (`/admin/users`, `/admin/roles`,
  `/admin/activity`, `/admin/organisations`) bestonden al sinds v0.1.0 — dit
  was puur een ontbrekende UI-link.

## [0.1.2] — 2026-05-08

### Fixed

- `BelongsToOrganisation` global scope no longer recurses into `auth()->user()`
  during SessionGuard's user-resolution query. The previous code caused a C-stack
  overflow → PHP-FPM segfault → empty HTTP 500 on the first authenticated page
  after login. Replaced with an `auth()->hasUser()`-guarded read.
- Regression test added in `tests/Feature/Tenancy/BelongsToOrganisationTest.php`.

## [0.1.1] — 2026-05-08

### Docs

- Toegevoegd: `docs/handleiding.html` voor het bootstrappen van nieuwe projecten
  vanuit het GitHub-template.
- README + composer scripts kleine bijwerking.

## [0.1.0] — 2026-05-08

### Foundation (Phase 1)

- Laravel 13 + Livewire 4 + Flux UI Pro 2 + Tailwind 4 op Postgres 16+
- `organisations` schema + `users` extended met `organisation_id`, `is_super_admin`, status enum, activation/2FA-velden, soft-deletes
- `App\Contracts\TenantOwned` interface + `BelongsToOrganisation` trait (auto-fill + global scope + `withoutTenantScope()` escape)
- `ResolveTenant` middleware (subdomain → org slug, 404 op onbekend, skip op apex/admin host)
- `tenant()` helper + `Gate::before` super-admin
- spatie/laravel-permission in teams-mode + 14 permissions + `organisation_admin` template role
- spatie/laravel-activitylog + spatie/laravel-backup + lab404/laravel-impersonate + pragmarx/google2fa-laravel + bacon/bacon-qr-code installed
- Auth UI (login + forgot + reset) met Flux Pro componenten en cross-tenant credential-blocking
- HTTPS lokaal via `herd secure`, dark-mode toggle via Alpine `$persist`, NL/EN-skeleton via `lang/nl.json`
- Pint (laravel preset) + GitHub Actions CI (pint / pest unit+feature+arch / pest browser)

### Features (Phase 2)

- `InvitationService` met `invite/accept/resendReminder/cancel/purgeExpired` (signed activation route, queued `InvitationMail`)
- Invite UI (Send modal, PendingList, Activate Livewire op signed route)
- Users CRUD met `UserPolicy` (same-org + super-admin protection, soft-delete, status filter)
- Roles & permissions UI met `RolePolicy` (template read-only, per-org custom roles)
- Organisations CRUD + `OrganisationObserver` cascade soft-delete (timestamp-equality op restore) + demo seeders (`demo1`, `demo2`, super-admin)
- `ImpersonationGuard` met locked rules (super → elke non-super, org_admin → eigen org & geen org_admin, depth=1, reason verplicht 1..500 chars), session-banner met stop-knop

### Operability (Phase 3)

- Activity-log Livewire view met log_name/causer/datum filters en pagination
- `/health-check` endpoint met `system.health` permission OR `Bearer HEALTH_CHECK_KEY` (database/queue/mail/backup checks, 200/503)
- Scheduler entries voor `backup:clean`, `backup:run --only-db`, en daily `InvitationService::purgeExpired()`
- Telescope env-guarded naar `local` (provider-level early-return + `TELESCOPE_ENABLED=false` in productie)
- 6 custom error pages (403/404/419/429/500/503) met Dutch copy
- README + CHANGELOG

### Tests

- 125 Pest cases (Unit + Feature + Arch), all green
- Pint clean

### Tags

- `phase-1-foundation` — eind van Phase 1
- `phase-2-features` — eind van Phase 2
- `v0.1.0` — eind van Phase 3
