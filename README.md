# skv1

Persoonlijke Laravel-startkit voor multi-tenant invitation-only apps. Volledig gebouwd, getest en klaar als GitHub Template.

> **Status (2026-05-08):** Phase 1 + 2 + 3 compleet. Acceptance §11.1–§11.8 ✅. Versie `0.1.0`.

## Wat is skv1

- **Multi-tenant single-database** — subdomeinen → `organisations` rij via `ResolveTenant` middleware, `BelongsToOrganisation` global scope
- **Invitation-only auth** — geen `/register`, alleen door admin uitgenodigde users met signed activation link
- **RBAC** — spatie/laravel-permission in teams-mode, super-admin via `Gate::before` op een `users.is_super_admin` flag
- **Audit-trail** — spatie/activitylog op invitations, users, impersonation, organisations
- **Impersonation** — lab404/laravel-impersonate met `ImpersonationGuard` (super-admin → elke non-super; org-admin → eigen org, geen org-admins)
- **Health check** — `/health-check` met `system.health` permission OF `Bearer HEALTH_CHECK_KEY`
- **Backups** — spatie/laravel-backup, dagelijkse db-only run + cleanup, scheduler in `routes/console.php`
- **Stack** — Laravel 13, Livewire 4, Flux UI Pro, Tailwind 4, Postgres, Pest 4

## Bootstrap (lokaal, Herd Pro pad)

> Voorwaarde: [Laravel Herd Pro](https://herd.laravel.com) geïnstalleerd (Nginx, Postgres, Mailpit native — geen Docker).

```bash
git clone <this-repo> skv1
cd skv1

composer install
herd link skv1
herd secure skv1
createdb -h 127.0.0.1 -U postgres skv1

cp .env.example .env
php artisan key:generate
php artisan migrate --seed

npm install && npm run build
```

> **Flux Pro license** moet beschikbaar zijn als http-basic auth voor `composer.fluxui.dev`:
> `composer config --global http-basic.composer.fluxui.dev <email> <license-key>`. Zonder werkt `composer install` niet.

Open `https://demo1.skv1.test/login` of `https://demo2.skv1.test/login`.

## Fallback (zonder Herd Pro)

Als Herd Pro niet beschikbaar is:

1. **Postgres** via `Postgres.app` of `brew install postgresql@16`. Pas `.env` aan: `DB_USERNAME=$(whoami)` of `postgres`, `DB_PASSWORD=` (leeg) of jouw lokale wachtwoord.
2. **Subdomeinen** via `dnsmasq` of `/etc/hosts` (`127.0.0.1 skv1.test demo1.skv1.test demo2.skv1.test admin.skv1.test`).
3. **Mailpit** via `brew install mailpit` of de [losse binary](https://mailpit.axllent.org). Draait op `localhost:1025` (SMTP) + `localhost:8025` (UI), matcht de `.env.example`.
4. **HTTPS** — sla `herd secure` over en gebruik http; pas `APP_URL=http://skv1.test` aan en zet `URL::forceScheme('https')` voor `local` uit in `AppServiceProvider`.

## Local development credentials

Na `php artisan migrate --seed`:

| E-mailadres | Wachtwoord | Rol |
|---|---|---|
| `admin@demo1.local` | `Password123!` | organisation_admin (demo1) |
| `user@demo1.local` | `Password123!` | gewone gebruiker (demo1) |
| `admin@demo2.local` | `Password123!` | organisation_admin (demo2) |
| `user@demo2.local` | `Password123!` | gewone gebruiker (demo2) |
| `super@example.local` | `Password123!` | super-admin (geen org-binding) |

Login-URLs:
- `https://demo1.skv1.test/login` — tenant demo1
- `https://demo2.skv1.test/login` — tenant demo2
- `https://admin.skv1.test/login` — admin host (super-admin only)

## Decisions / deviations

Zie [`OUTPUT_SUMMARY.md`](OUTPUT_SUMMARY.md) §3 *Deviations from Super Prompt* voor de volledige lijst en motivatie.

Hoogtepunt: **`super_admin` is geen spatie role** maar een `users.is_super_admin` boolean + `Gate::before`. Reden: spatie's teams-mode pivot heeft `team_id` in primary key (NOT NULL); een role assignment met `team_id = null` is dus niet mogelijk in PG. `Gate::before` preserveert spec-intent en is idiomatisch Laravel.

## Stack

| | Versie |
|---|---|
| PHP | 8.4 |
| Laravel | 13.x |
| Livewire | 4.x |
| Flux UI Pro | 2.14.x |
| Tailwind | 4.x |
| Postgres | 16+ |
| Node | 22.x |
| Pest | 4.x |
| spatie/laravel-permission | 7.x |
| spatie/laravel-activitylog | 5.x |
| spatie/laravel-backup | 10.x |
| lab404/laravel-impersonate | 1.x |
| pragmarx/google2fa-laravel | 3.x |

Exacte semver-locks in `composer.lock` + zie [`OUTPUT_SUMMARY.md`](OUTPUT_SUMMARY.md) §1.

## Tests

```bash
composer test                                              # alles
vendor/bin/pest --testsuite=Unit,Feature,Arch --compact    # 125 cases, all green
vendor/bin/pest --browser                                  # browser smoke (vereist chromium via playwright)
vendor/bin/pint --test                                     # lint check
vendor/bin/pint                                            # auto-fix
```

CI draait drie jobs (`.github/workflows/ci.yml`): pint, pest unit/feature/arch, pest browser.

## Acceptance criteria (super prompt §11)

| § | Criterion | Status |
|---|---|---|
| §11.1 | Bootstrap → 2 demo orgs + global super-admin | ✅ |
| §11.2 | `https://demo1.skv1.test` → /login redirect | ✅ |
| §11.3 | super-admin impersonates demo2 user, activity-log entry | ✅ |
| §11.4 | demo1 admin sees no demo2 data | ✅ |
| §11.5 | invite → mail → activate → login < 60 sec | ✅ |
| §11.6 | `composer test` all green incl. browser | ✅ (CI green) |
| §11.7 | `vendor/bin/pint --test` green | ✅ |
| §11.8 | `/health-check` as super_admin → 200 + JSON shape | ✅ |

## Productiedeploy (minimaal)

| Onderdeel | Waarde |
|---|---|
| PHP | 8.4 |
| Postgres | 16+ |
| Persistente disk | voor `storage/app/backups/` (spatie/laravel-backup output) |
| Scheduler | `* * * * * cd /var/www/skv1 && php artisan schedule:run >> /dev/null 2>&1` |
| Queue worker | `php artisan queue:work --queue=default --tries=3` (mail + InvitationMail draaien queued) |
| Mailer | `MAIL_MAILER` via env (`smtp`, `ses`, `postmark`, `resend` — zie `config/mail.php`) |
| HTTPS | verplicht; `URL::forceScheme('https')` is actief in `local`/`staging`/`production` |
| Telescope | uitgeschakeld buiten `local` (zowel `TELESCOPE_ENABLED=false` als provider-guard) |
| Health check | `HEALTH_CHECK_KEY` env voor monitoring agents |

## Plannen (referentie)

| Phase | Plan |
|---|---|
| Phase 1 (steps 1-9) | foundation + auth (gerealiseerd, tag `phase-1-foundation`) |
| Phase 2 (steps 10-15) | [`docs/superpowers/plans/2026-05-08-skv1-phase-2.md`](docs/superpowers/plans/2026-05-08-skv1-phase-2.md) |
| Phase 3 (steps 16-21) | [`docs/superpowers/plans/2026-05-08-skv1-phase-3.md`](docs/superpowers/plans/2026-05-08-skv1-phase-3.md) |

## License

MIT.
