# Changelog

Alle noemenswaardige wijzigingen aan dit project. Versies volgen [Semantic Versioning](https://semver.org).

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
