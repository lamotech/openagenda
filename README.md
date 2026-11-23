# OpenAgenda

OpenAgenda is a Nextcloud app for the political decision-making process in Danish municipalities: committees, meetings, agendas, approval, political treatment, forwarding between committees, decision protocols, journalization and publication.

**Status: v0.1, a working end-to-end demo.** OpenCase is the master system for cases and documents. By default a simulated OpenCase (`MockOpenCaseAdapter`) with fictitious data is used. Set `opencase_adapter=opencase` (admin settings or `occ config:app:set openagenda opencase_adapter --value=opencase`) to use the OpenCase app in the same Nextcloud instance with each user's own OpenCase permissions (verified against OpenCase 1.3.0).

## Documentation

The documentation in `docs/` is written in Danish.

| Document | Contents |
|---|---|
| [docs/DEMO.md](docs/DEMO.md) | Step-by-step demo guide |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Architecture, layers and design decisions |
| [docs/DATA_MODEL.md](docs/DATA_MODEL.md) | Entities and relations |
| [docs/API.md](docs/API.md) | Internal and public REST API |
| [docs/OPENCASE_INTEGRATION.md](docs/OPENCASE_INTEGRATION.md) | Adapter contract and mock limitations |
| [docs/SECURITY.md](docs/SECURITY.md) | Roles, permissions and known security limitations |
| [docs/IMPLEMENTATION_PLAN.md](docs/IMPLEMENTATION_PLAN.md) | Plan, status, test results and next phase |

## Requirements

- Nextcloud 34–35, PHP ≥ 8.1, MySQL/MariaDB or PostgreSQL
- For building: Node.js ≥ 20.19 and npm. For tests: Composer

## Installation

```bash
cd /var/www/nextcloud/apps
git clone <repo> openagenda && cd openagenda

# Frontend
npm ci
npm run build            # builds js/ and css/ (entries: openagenda-main, openagenda-public)

# Enable the app (runs the database migration)
sudo -u www-data php ../../occ app:enable openagenda
```

When upgrading from an earlier version of the skeleton, run `sudo -u www-data php occ upgrade`. The app version is 0.1.1 because the migration was added after the skeleton's 0.1.0.

## Demo data

```bash
sudo -u www-data php occ openagenda:demo:seed                     # idempotent
sudo -u www-data php occ openagenda:demo:seed --role alice:caseworker --role bob:secretary
sudo -u www-data php occ openagenda:demo:reset --force            # DELETES all OpenAgenda data and seeds again
```

No users are created. Roles are assigned through the Nextcloud groups `openagenda_admins`, `openagenda_secretaries`, `openagenda_caseworkers` and `openagenda_politicians` (the group names are configurable). Nextcloud administrators have all permissions.

The app is listed in the Nextcloud navigation as "Open Agenda". The public portal is at `/index.php/apps/openagenda/public`.

## Development and tests

```bash
npm run lint                 # ESLint (Vue 3 + TypeScript preset)
npm run build

# PHP dependencies: 'composer install' currently fails because roave/security-advisories
# conflicts with platform PHP 8.1. Workaround:
composer dump-autoload --dev
composer --working-dir=vendor-bin/phpunit install

# All PHPUnit tests (unit + integration + HTTP E2E). Run as the web server user:
sudo -u www-data php vendor-bin/phpunit/vendor/bin/phpunit -c tests/phpunit.xml
sudo -u www-data php vendor-bin/phpunit/vendor/bin/phpunit -c tests/phpunit.xml --testsuite unit
```

The integration tests run against Nextcloud's real database. They create temporary users (with random passwords), committees, meetings and mock cases with a unique prefix, and delete them again afterwards.
`EndToEndHttpTest` calls the running server at `overwrite.cli.url` and is skipped if the server cannot be reached.

Browser E2E: `tests/e2e/demo-ui.mjs` (optional; requires `playwright-core` and Chromium; see the file).

## License

AGPL-3.0-or-later
