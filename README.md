# skv1

Persoonlijke Laravel-startkit voor multi-tenant invitation-only apps.

> **Status (2026-05-08):** Phase 1 (foundation) compleet — auth, multi-tenancy, RBAC scaffolding draait eind-tot-eind.
> Phase 2 (invitation flow + CRUD + impersonation) en Phase 3 (health check, backups, error pages, template publish) zijn nog niet gebouwd.
> Zie [`OUTPUT_SUMMARY.md`](OUTPUT_SUMMARY.md) voor exacte versies, deviations en wat er werkt.

## Wat is skv1

- **Multi-tenant single-database** — subdomeinen → `organisations` rij via `ResolveTenant` middleware
- **Invitation-only auth** — geen `/register`, alleen door admin uitgenodigde users
- **RBAC** — spatie/laravel-permission in teams-mode, super-admin via `Gate::before`
- **Audit-trail** — spatie/activitylog op alle gevoelige acties
- **Stack** — Laravel 13, Livewire 4, Flux UI Pro, Tailwind 4, Postgres, Pest 4
- **Lokaal** — Laravel Herd Pro (Nginx, Postgres, Mailpit native — geen Docker)

## Bootstrap (Phase 1, lokaal)

> Voorwaarde: [Laravel Herd Pro](https://herd.laravel.com) geïnstalleerd.

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

> **Flux Pro license** moet beschikbaar zijn als http-basic auth voor `composer.fluxui.dev`. `composer config --global http-basic.composer.fluxui.dev <email> <license-key>`. Zonder werkt `composer install` niet.

## Local development credentials

Phase 1 heeft **nog geen demo-seeders**. Maak handmatig een org + user via tinker:

```php
$org = \App\Models\Organisation::factory()->create(['slug' => 'demo1', 'name' => 'Demo 1']);
\App\Models\User::factory()->for($org)->create([
    'email' => 'admin@demo1.local',
    'password' => bcrypt('Password123!'),
    'status' => 'active',
]);
```

Dan: `https://demo1.skv1.test/login` — login met `admin@demo1.local` / `Password123!`.

Voor super-admin: voeg `is_super_admin: true` toe.

## Tests

```bash
vendor/bin/pest --testsuite=Unit,Feature,Arch --compact   # 28 cases, all green
vendor/bin/pint --test                                    # lint check
vendor/bin/pint                                           # auto-fix
```

## Decisions / deviations

Zie [`OUTPUT_SUMMARY.md`](OUTPUT_SUMMARY.md) §3 *Deviations from Super Prompt*.

Hoogtepunt: **`super_admin` is geen spatie role** maar een `users.is_super_admin` boolean + `Gate::before`. Reden: spatie's teams-mode pivot heeft `team_id` in primary key (NOT NULL); een role assignment met `team_id = null` is dus niet mogelijk in PG. De `Gate::before`-aanpak preserveert spec-intent en is idiomatisch Laravel.

## Volgende stappen

| Phase | Plan |
|---|---|
| Phase 2 (steps 10-15) | [`docs/superpowers/plans/2026-05-08-skv1-phase-2.md`](docs/superpowers/plans/2026-05-08-skv1-phase-2.md) |
| Phase 3 (steps 16-21) | [`docs/superpowers/plans/2026-05-08-skv1-phase-3.md`](docs/superpowers/plans/2026-05-08-skv1-phase-3.md) |

## License

MIT.
