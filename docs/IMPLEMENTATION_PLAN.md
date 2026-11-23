# OpenAgenda v0.1 – Implementeringsplan og status

## 1. Udgangspunkt (analyse af skeleton)

| Emne | Fund |
|---|---|
| App-id / namespace | `openagenda` / `OCA\OpenAgenda` (PSR-4 → `lib/`) |
| Nextcloud | Installeret server 34.0.0 (`appinfo/info.xml`: min 34, max 35) |
| PHP | Server kører PHP 8.3.6; `composer.json` kræver `^8.1` med platform 8.1 → kode skrives PHP 8.1-kompatibel (backed enums, readonly-properties) |
| Database | MySQL/MariaDB (`dbtype=mysql`) |
| Frontend | Vue 3.5 + `@nextcloud/vue` 9, Vite 7 via `@nextcloud/vite-config`, TypeScript |
| Eksisterende kode | `PageController` (FrontpageRoute `/`), `ApiController` (OCS "Hello world"), `Application` (IBootstrap), `templates/index.php`, `src/App.vue` |
| Routing | Attribut-baseret (`#[FrontpageRoute]`, `#[ApiRoute]`) – ingen `appinfo/routes.php` |
| Tests | PHPUnit 10.5 via `vendor-bin/phpunit`, `tests/bootstrap.php` forventer server-`tests/` (findes ikke i release-installationen) |
| Kvalitetsværktøjer | psalm, php-cs-fixer, rector, eslint, stylelint (vendor-bin) |
| OpenCase | Ingen integration i projektet. **Bemærk:** En separat Nextcloud-app `opencase` 1.3.0 er installeret på serveren. v0.1 integrerer bevidst **ikke** med den (se `OPENCASE_INTEGRATION.md`) – kun via `MockOpenCaseAdapter` |

Fundne fejl i skeleton:
- `vite.config.ts` pegede på `src/main.js`, men filen hedder `src/main.ts` → rettet.
- `composer install` fejler, fordi `roave/security-advisories` konflikter med platform-PHP 8.1. Løsning i udviklingsmiljøet: `composer dump-autoload` + `composer --working-dir=vendor-bin/phpunit install` (dokumenteret i README).
- `tests/bootstrap.php` krævede server-testbootstrap → udvidet med fallback til `lib/base.php`.

Verificerede Nextcloud-API'er (kontrolleret mod `/var/www/nextcloud/lib/public`):
`OCP\AppFramework\Db\{Entity,QBMapper,TTransactional}`, `OCP\Migration\SimpleMigrationStep`, `OCP\DB\Types`,
`OCP\AppFramework\Http\Attribute\{FrontpageRoute,ApiRoute,NoAdminRequired,NoCSRFRequired,PublicPage,AnonRateLimit,OpenAPI}`,
`OCP\AppFramework\Http\Template\PublicTemplateResponse`, `OCP\IGroupManager`, `OCP\IUserSession`, `OCP\IAppConfig`,
`OCP\AppFramework\Utility\ITimeFactory`. Foreign keys anvendes i core-apps (fx `mail`) og understøttes af `MigrationService`.

## 2. Implementeringsstruktur (modulær monolit)

```
lib/
  Domain/            Forretningsregler uden Nextcloud-afhængigheder
    Enum/            MeetingStatus, AgendaItemStatus, DecisionType, ... (statusmaskiner)
    Exception/       Domæneundtagelser (NotFound, Forbidden, Validation, InvalidTransition, Conflict)
    AccessPolicy     Ren rolle-/adgangspolitik (unit-testbar)
    Role, Permission
    SnapshotBuilder  Byggelogik + hash for uforanderlige snapshots
    PublicModel      Eksplicit whitelist af offentlige felter
  Service/           Application-lag (use cases, transaktioner, audit)
  Db/                Infrastructure: entiteter + QBMapper-repositories
  OpenCase/          Infrastructure: OpenCaseAdapterInterface, DTO'er, MockOpenCaseAdapter
  Migration/         Databasemigrationer
  Command/           occ-kommandoer (demo-seeder)
  Controller/        Presentation: interne JSON-API'er, offentligt API, sider
src/                 Presentation: Vue 3 (intern app + separat offentlig app)
```

## 3. Datamodel (oversigt – detaljer i DATA_MODEL.md)

`Committee` → `Meeting` → `AgendaItem` ← `PoliticalCase` (reference til OpenCase-sag)
`Treatment` (1 pr. dagsordenspunkt; peger på PoliticalCase, Meeting, AgendaItem, evt. forrige Treatment)
`Decision` (1 pr. Treatment), `AgendaSnapshot` (versioneret, uforanderlig), `Protocol` (versioneret, låses ved godkendelse),
`Journalization` (unik pr. protokol + OpenCase-sag → idempotens), `Publication` (kun fra snapshot/godkendt protokol), `AuditEvent` (append-only).
Alle entiteter har heltals-PK (krævet af `Entity`) + unik `uuid`, som bruges i API'et.

## 4. API (oversigt – detaljer i API.md)

Interne endpoints: `/index.php/apps/openagenda/api/v1/...` (session + CSRF-token).
Offentligt API: `/index.php/apps/openagenda/api/public/v1/...` (`#[PublicPage]`, kun GET, rate-limited).

## 5. Implementeringsrækkefølge og status

| Trin | Indhold | Status |
|---|---|---|
| 1 Fundament | Entiteter, migration, mappers, AuthorizationService | ✅ |
| 2 Udvalg og møder | Services, API, Vue-visninger, seeder | ✅ |
| 3 OpenCase og punkter | Adapter-interface, mock, søgning, editor | ✅ |
| 4 Godkendelse | Statusovergange, snapshot, udsendelse | ✅ |
| 5 Politisk behandling | Behandling, beslutning, videreførelse, historik | ✅ |
| 6 Protokol og journalisering | HTML-protokol, godkendelse, idempotent journalisering | ✅ |
| 7 Offentliggørelse | Publiceringsmodel, offentligt API, offentlig visning | ✅ |
| 8 Test og dokumentation | Unit-, integrations-, sikkerheds- og E2E-tests, docs | ✅ |

Alle trin er gennemført. Definition of Done er opfyldt; se afsnit 8 for dokumentation af hvert punkt.

### Tekniske beslutninger undervejs
- Appversionen er hævet fra 0.1.0 til 0.1.1, så `occ upgrade` kører migrationen på den allerede aktiverede app.
- Entity-defaults er sat til `null`. Ellers gemmes `isPublic = false` ikke (fundet af integrationstest; se ARCHITECTURE.md).
- ESLint-config er skiftet til `@nextcloud/eslint-config/vue3`, fordi standard-presetet ikke kan parse `<script setup lang="ts">`. `no-explicit-any` er slået fra, da API-svar endnu ikke er typede.
- `vendor/` tilføjet til `.gitignore`.

## 6. Tekniske risici

| Risiko | Håndtering |
|---|---|
| Ukendt rigtigt OpenCase-API | Kun interface + mock; ingen endpoints opfundet |
| Nextcloud-API-ændringer (NC 34/35) | Kun `OCP`-API'er, verificeret mod installeret kode |
| Foreign keys + migrationer på forskellige DB'er | Korte navne, FK'er kun på centrale relationer. Kun testet på MySQL |
| Snapshot-uforanderlighed | Mapper blokerer `update()`/`delete()`; SHA-256-hash gemmes og verificeres |
| Dobbeltjournalisering ved samtidige kald | Unik DB-constraint (protokol, sag) + idempotensnøgle til adapter |
| Lækage af lukkede punkter offentligt | Whitelist-model, separat controller og læsetabel, sikkerhedstests |

## 7. Teststrategi

- **Unit** (`tests/Unit`): statusmaskiner, beslutningstyper, videreførelsesregler, AccessPolicy, snapshot-hash, offentlig whitelist.
- **Integration** (`tests/Integration`): services mod rigtig database med midlertidige brugere (tilfældige adgangskoder, slettes efter test).
- **Sikkerhed** (`SecurityIntegrationTest` m.fl.): uautoriserede skriveoperationer, lukkede punkter, offentligt API, dokumentadgang via adapter.
- **E2E HTTP** (`EndToEndHttpTest`): hele scenariet via rigtige HTTP-kald mod den kørende Nextcloud.
- **E2E browser** (`tests/e2e/demo-ui.mjs`): hele scenariet i Chromium via brugerfladen.

## 8. Resultater (seneste kørsel, 23.11.2026)

| Kontrol | Resultat |
|---|---|
| `phpunit` (alle suites) | **OK – 77 tests, 432 assertions** (55 unit, 21 integration, 1 HTTP-E2E) |
| Browser-E2E `demo-ui.mjs` | **10/10 trin gennemført** (Chromium headless, midlertidige testbrugere) |
| `npm run lint` | 0 fejl, 0 advarsler |
| `npm run build` | OK |
| `occ upgrade` (migration) | OK; app 0.1.1 aktiveret |
| `occ openagenda:demo:seed` ×2 | Idempotent (ingen dubletter ved anden kørsel) |
| psalm / php-cs-fixer | **Ikke kørt.** `composer install` fejler pga. `roave/security-advisories` vs. platform-PHP 8.1, så værktøjerne i vendor-bin er ikke installeret. `php -l` er kørt på alle filer |

Definition of Done:
- Installation og migration: `occ upgrade` OK.
- Udvalg og møder oprettes via UI og API (tests og browser).
- OpenCase-søgning, punkt med sagsreference og placering på udvalgsmøde: browser-E2E trin 1–3.
- Godkendelse og udsendelse med uforanderligt snapshot: `AgendaPublicationIntegrationTest`, trin 4.
- Indstilling, videreførelse til byrådet, selvstændig byrådsbeslutning og historik: `TreatmentIntegrationTest`, trin 5–7.
- Protokol genereret og godkendt; idempotent journalisering: `ProtocolJournalizationIntegrationTest`, trin 8–9.
- Offentliggørelse med beskyttede lukkede punkter: `SecurityIntegrationTest`, `EndToEndHttpTest`, trin 10.
- Persistens: alle data i Nextclouds database. Læsning på tværs af separate PHP-processer er verificeret via HTTP-E2E; genstart af webserveren er **ikke** udført i testen.

## 8b. Tilføjet efter v0.1-demoen: OpenCase-adapter (mulighed B)

- `InProcessOpenCaseAdapter` bruger OpenCase-appen på samme server med brugerens egne rettigheder. Den vælges via `opencase_adapter` (default `mock`), se OPENCASE_INTEGRATION.md.
- Adapter-interfacet har fået eksplicit `?userId` på alle læsninger og `isSimulated()`.
- Tests: 6 nye unit-tests (stand-ins) og en opt-in live-læsetest mod OpenCase 1.3.0. Den er kørt som `admin` og bestod (10 assertions; den skriver "sag vist" i OpenCases auditlog).
- Fuld suite efter ændringen: **OK – 83 tests, 469 assertions**. Lint 0 fejl, build OK.
- **Journalisering mod rigtig OpenCase testet (24.11.2026):** Testsagen **2026-00129** "OpenAgenda testsag – journalisering af beslutningsprotokol (TEST)" (uuid `5ddcc25e-2173-4bee-a02c-ef268a5dc45d`, Afdeling C, ansvarlig `admin`) er oprettet i OpenCase. `OpenCaseInProcessJournalizationTest` gennemførte hele flowet (punkt på sagen → udsendelse → beslutning → godkendt protokol → journalisering) og bestod (16 assertions):
  - OpenCase-dokumentet **2026-00129-1** blev oprettet (type Referat, kategori Intern, oprettet af admin) med én fil, `beslutningsprotokol-v1.html`.
  - Andet kald gav 0 nye og 1 sprunget over. Et direkte gentaget adapterkald genkendte markøren, og der blev ikke oprettet et ekstra dokument eller en ekstra fil.
  - OpenAgenda-testdata er ryddet op. Dokumentet i OpenCase er bevaret som dokumentation.
- Fuld suite efter ændringen: 85 tests, 469 assertions, 2 sprunget over (de to opt-in live-tests).

## 9. Kendte begrænsninger og næste fase

Begrænsninger: se SECURITY.md (sikkerhed) og OPENCASE_INTEGRATION.md (mock). Desuden:
- Simple tekstfelter (ingen rich text).
- Ingen PDF.
- Ingen notifikationer eller e-mail ved udsendelse.
- Ingen l10n-infrastruktur; teksterne er hardkodet på dansk.
- API-svar er ikke TypeScript-typede.
- Ingen OpenAPI-spec for det interne API.
- Kun MySQL er testet.

Næste fase (forslag, prioriteret):
1. ~~OpenCase-adapter mod den installerede `opencase`-app~~ (in-process implementeret). Næste: journaliseringstest mod en dedikeret testsag, PDF-protokol og aftale med OpenCase-teamet om stabil kontrakt (mulighed C).
2. Udvalgsmedlemskab og adgang pr. udvalg og lukket punkt.
3. Notifikationer og e-mail ved udsendelse; kalenderintegration af møder.
4. Sikker offentlig og intern visning og download af bilag via OpenCase.
5. PDF-protokol, digital underskrift og protokolopfølgning (beslutningsopfølgning).
6. Rich-text-editor (fx Nextcloud Text), l10n og TypeScript-typer for API'et.
7. Psalm og php-cs-fixer i CI (løs composer-konflikten), og browser-E2E i CI.
