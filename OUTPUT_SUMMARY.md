# skv1 — Output Summary (Phase 1)

> **Status:** Phase 1 (foundation) compleet, tag `phase-1-foundation`. Phase 2 + 3 nog open — zie `docs/superpowers/plans/`.
> **Datum:** 2026-05-08

## 1. Locked Versions

### Composer (production)

| Package | Version |
|---|---|
| `laravel/framework` | v13.8.0 |
| `livewire/livewire` | v4.3.0 |
| `livewire/flux` | v2.14.1 |
| `livewire/flux-pro` | 2.14.1 |
| `spatie/laravel-permission` | 7.4.1 |
| `spatie/laravel-activitylog` | 5.0.0 |
| `spatie/laravel-backup` | 10.2.1 |
| `lab404/laravel-impersonate` | 1.7.8 |
| `pragmarx/google2fa-laravel` | v3.0.1 |
| `bacon/bacon-qr-code` | v3.1.1 |

### Composer (dev)

| Package | Version |
|---|---|
| `pestphp/pest` | v4.7.0 |
| `pestphp/pest-plugin-laravel` | v4.1.0 |
| `pestphp/pest-plugin-browser` | v4.3.1 |
| `laravel/pint` | v1.29.1 |
| `laravel/telescope` | v5.20.0 |
| `barryvdh/laravel-debugbar` | v4.2.8 |

### NPM

| Package | Version |
|---|---|
| `vite` | 8.0.11 |
| `tailwindcss` | 4.2.4 |
| `@tailwindcss/vite` | 4.2.4 |
| `laravel-vite-plugin` | 3.1.0 |

### Runtime

| | |
|---|---|
| PHP | 8.4.19 (Laravel Herd) |
| Node | v22.16.0 |
| PostgreSQL | 18.0 (Herd Pro) |

---

## 2. Local Development Credentials

> **Geseede demo-users komen pas in Phase 2 (macro-step 14, `DemoUsersSeeder`).** Phase 1 heeft alleen de permissions-seeder. Voor handmatig testen: maak een organisation en user via tinker.

```bash
php artisan tinker
> $org = \App\Models\Organisation::factory()->create(['slug' => 'demo1', 'name' => 'Demo 1']);
> \App\Models\User::factory()->for($org)->create([
>     'email' => 'admin@demo1.local',
>     'password' => \Illuminate\Support\Facades\Hash::make('Password123!'),
>     'status' => 'active',
> ]);
```

Dan: `https://demo1.skv1.test/login` → `admin@demo1.local` / `Password123!`.

Voor **super-admin** voeg `is_super_admin: true` toe (zie deviation 4 hieronder).

---

## 3. Deviations from Super Prompt

> **Notatie:** Spec-regel → afwijking → reden. Bij geen afwijking: niet vermeld.

### Deviation 1 — Postgres versie
**Spec §1:** `PostgreSQL 16`
**Reality:** Herd Pro 1.28 ships **PostgreSQL 18.0** lokaal; CI gebruikt nog steeds `postgres:16`-image voor pariteit met spec-tekst.
**Reden:** Niets in skv1 gebruikt PG16-specifieke features anders dan PG14+ syntax (jsonb, soft deletes, FK-restrict). PG18 is volledig backwards-compatibel; lokaal upgraden naar latest stable matcht Herd's defaults zonder een tweede Postgres-instance naast Herd te draaien.

### Deviation 2 — Local DB user
**Spec §2.3:** `DB_USERNAME=${USER}`
**Implementation:** `DB_USERNAME=postgres`
**Reden:** Herd Pro's Postgres maakt geen rol per OS-user aan; default rol is `postgres` met local-trust auth. `${USER}` levert "role does not exist" bij verse Herd-installs. `postgres` werkt out-of-the-box op elke Herd-machine.

### Deviation 3 — Mailpit & subdomeinen via Herd
**Spec §1, §10:** `docker-compose.yml` voor Mailpit, `dnsmasq` of `/etc/hosts` voor subdomains.
**Implementation:** Beide native via Laravel Herd Pro (zoals al voorzien in spec-deviation-tabel van super prompt §0).
**Reden:** Owner draait Herd Pro structureel; geen Docker. Subdomeinen via Herd's ingebouwde dnsmasq.

### Deviation 4 — `super_admin` mechanisme (de grootste)
**Spec §4.3:** `super_admin` is een spatie role met `team_id = null`; alle permissions met `team_id = null` zijn globaal en alleen super-admin krijgt ze.
**Implementation:** `super_admin` is **geen** spatie role. Het is een `users.is_super_admin` boolean kolom + `Gate::before(fn ($u) => $u->isSuperAdmin() ? true : null)` in `AppServiceProvider`. Spatie teams-mode wordt alleen gebruikt voor `organisation_admin` en toekomstige org-scoped roles.
**Reden:** Spatie's `model_has_roles.team_id` zit in de PRIMARY KEY van de pivot en is dus NOT NULL. Postgres staat geen NULL toe in een PK-kolom. Een role assignment met `team_id = null` is onmogelijk in spatie's default schema. Workaround opties waren: (a) PK droppen + raw `UNIQUE NULLS NOT DISTINCT` (PG18-specifiek, brittle), (b) sentinel team_id=0 (lekkende abstractie), (c) `Gate::before` super-admin pattern dat Nova/Filament zelf gebruiken. (c) gekozen — preserves spec intent ("super-admin doet alles cross-tenant"), idiomatisch Laravel, geen schema-trickery.
**Hoe toepassen in code:** check `$user->isSuperAdmin()` of `Gate::allows(...)` (gate auto-passt door). Spec-conventie "spatie role super_admin" is **niet beschikbaar** — gebruik nooit `hasRole('super_admin')`.

### Deviation 5 — Spec-files locatie
**Spec impliciet:** spec-files zouden bij de kit horen.
**Implementation:** `skv1_create.md` en `skv1_uitleg.html` blijven op project-root maar zijn `gitignore`d, dus ze landen niet in de template-repo.
**Reden:** De pre-existing `.gitignore` in deze werkdirectory negeerde ze al — owner-intent: lokaal-alleen design-source.

### Deviation 6 — Pest browser plugin & 2FA Phase
**Spec §10 step 9:** "Auth-routes + Flux-styled login/forgot/reset views + 2FA enrolment".
**Implementation:** Login + forgot + reset zijn af in Phase 1. **2FA-enrolment is verschoven naar Phase 2** omdat het op de auth-flow van invitation-acceptatie hoort (waar de TOTP secret tijdens activation wordt gegenereerd). Geen functioneel verlies; meer logische groepering.
**Browser tests** (Pest plugin) zijn **niet uitgevoerd** in Phase 1 — chromium download in headless modus is brittle in de generation-omgeving. Feature tests dekken de login flows volledig (`Livewire::test(...)` met session/redirect assertions). Plan-doc Phase 2 voorziet in browser-test catch-up.

---

## 4. What Phase 1 Delivers

### ✅ Werkend eind-tot-eind

- Subdomain → tenant resolution (`https://demo1.skv1.test` → `Organisation` slug='demo1')
- 404 op onbekend subdomain
- Apex (`skv1.test`) en admin host (`admin.skv1.test`) skippen tenant-binding
- Login via Flux Pro UI met cross-tenant credential blocking
- Forgot-password mail flow (Mailpit)
- Reset-password met getekende route
- Logout + session invalidation
- Dashboard placeholder met tenant-context callouts
- HTTPS lokaal via `herd secure`
- Dark-mode toggle persisted via Alpine `$persist`
- Localisatie NL/EN-skeleton via `lang/nl.json`

### ✅ Test coverage

| Suite | Tests | Status |
|---|---|---|
| Unit (`Migrations/SchemaTest`) | 3 | green |
| Feature (`Auth/LoginTest`) | 6 | green |
| Feature (`FactoriesTest`) | 4 | green |
| Feature (`Seeding/RolesAndPermissionsSeederTest`) | 2 | green |
| Feature (`Tenancy/BelongsToOrganisationTest`) | 4 | green |
| Feature (`Tenancy/ResolveTenantTest`) | 6 | green |
| Arch (`TenantOwnedArchTest`) | 3 | green |
| **Totaal** | **28** | **all green** |

### ✅ Tooling

- Pint (laravel preset) — `composer pint -- --test` clean
- CI workflow (`.github/workflows/ci.yml`) met 3 jobs: pint / pest unit+feature+arch / pest browser
- Telescope geïnstalleerd (later locked tot `local` env in Phase 3 macro-step 18)
- Debugbar geïnstalleerd

### ✅ Schema

- `organisations` (id, name, slug, description, soft deletes)
- `users` extended (organisation_id FK, is_super_admin, status enum, activation_*, two_factor_*, locale, soft deletes)
- `invitations` (user_id, invited_by, token, expires_at, reminder_sent_at, accepted_at)
- spatie permission tables (teams=true, team_id nullable on `roles`, NOT NULL on pivots — by design)
- spatie activitylog table
- telescope tables (local-only env will be added in Phase 3)

### ✅ Architecture

- `App\Contracts\TenantOwned` marker interface
- `App\Models\Concerns\BelongsToOrganisation` trait (auto-fill + global scope + `withoutTenantScope()` escape)
- `App\Http\Middleware\ResolveTenant` (web group, post-StartSession)
- `tenant()` helper + `Gate::before` for super-admin
- `App\Providers\AppServiceProvider` forces HTTPS in local/staging/production

---

## 5. What's Next

| Phase | Macro-steps | Plan |
|---|---|---|
| Phase 2 | 10. InvitationService + mail<br>11. Invite UI + pending list<br>12. Users CRUD<br>13. Roles & permissions UI<br>14. Org CRUD + cascade observer + DemoUsers seeder<br>15. ImpersonationGuard + UI | `docs/superpowers/plans/2026-05-08-skv1-phase-2.md` |
| Phase 3 | 16. Activity-log views<br>17. Health check<br>18. Backups + Telescope local-only<br>19. Custom error pages<br>20. README + CHANGELOG<br>21. Template repo publish + verify | `docs/superpowers/plans/2026-05-08-skv1-phase-3.md` |

Acceptance §11 (van super prompt) is **nog niet** allemaal groen. De 8 acceptance-criteria worden gehaald aan het einde van Phase 3.

---

## 6. Notes for Future Sessions

- **Run tests vóór commit:** `vendor/bin/pint && vendor/bin/pest --testsuite=Unit,Feature,Arch`. Dit voorkomt amend-cycli (Pint vond steeds een fix tijdens commit-stap in Phase 1).
- **Asset rebuild:** `npm run build` na elke wijziging in `resources/css/` of nieuwe Flux-component-aanroep is niet nodig in dev — gebruik `npm run dev` voor watch-mode.
- **Spec-files** (`skv1_create.md`, `skv1_uitleg.html`) blijven op root, gitignored. Houden voor referentie tijdens Phase 2/3.
- **Flux Pro license** zit in `~/.composer/auth.json` (global) én in lokale `auth.json` (gitignored). CI-job leest GitHub secrets `FLUX_PRO_USERNAME`/`FLUX_PRO_PASSWORD`.
- **Test-DB** (`skv1_test`) staat los van dev-DB (`skv1`). Beide via `postgres`-rol op localhost 5432.
- **Pest browser tests** zitten in suite `Browser` maar zijn nog leeg. Phase 2 vult ze (login flow + invitation flow + impersonation flow).
