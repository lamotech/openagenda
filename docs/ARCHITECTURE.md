# Arkitektur – OpenAgenda v0.1

OpenAgenda er en **modulær monolit** i form af en Nextcloud-app (`openagenda`, namespace `OCA\OpenAgenda`).
Den kører i Nextcloud 34 (PHP ≥ 8.1, Vue 3, Vite).

## Ansvarsfordeling: OpenCase er master

| OpenCase ejer | OpenAgenda ejer |
|---|---|
| Sager, sagsnumre, dokumenter, dokumentversioner, journalposter, autoritative sagsmetadata | Udvalg, møder, dagsordener, dagsordenspunkter, politiske behandlinger, indstillinger, beslutninger, protokoller, publiceringsstatus |

OpenAgenda gemmer kun **referencer** til OpenCase (sags-id, dokument-id, versions-id) samt en cachet visningstitel og et cachet sagsnummer på `PoliticalCase`.
Al kommunikation går gennem porten `OpenCaseAdapterInterface` (se `OPENCASE_INTEGRATION.md`).

## Lag

```
Presentation   lib/Controller/*            tynde JSON-controllers, fejl → HTTP-statuskoder
               lib/Controller/PublicApiController  separat offentligt API (læser kun publikationer)
               src/ (Vue 3)                intern app (main.ts) + offentlig portal (public.ts)
Application    lib/Service/*               use cases, transaktioner (TTransactional), audit
Domain         lib/Domain/*                statusmaskiner (enums), AccessPolicy, ForwardingRules,
                                           ContentHasher, PublicContentFactory, domæneundtagelser
Infrastructure lib/Db/*                    Entity + QBMapper-repositories (Nextcloud DB-abstraktion)
               lib/Migration/*             skema (inkl. foreign keys)
               lib/OpenCase/*              adapter-interface, DTO'er, MockOpenCaseAdapter
               lib/Command/*               occ-kommandoer (demo-seeder)
```

Regler:
- Domænelaget har ingen Nextcloud-afhængigheder og er unit-testet isoleret.
- Controllers indeholder ingen forretningslogik; de kalder én service og formaterer svaret via `ViewBuilder`.
- Vue-komponenter indeholder ingen forretningsregler. Knapper skjules ud fra `permissions`-flag, som serveren beregner med samme `AccessPolicy`, men serveren kontrollerer altid igen.
- Afhængigheder injiceres via Nextclouds DI-container. Adapteren bindes i `Application::register()`:
  `registerServiceAlias(OpenCaseAdapterInterface::class, MockOpenCaseAdapter::class)`.

## Centrale services

| Service | Ansvar |
|---|---|
| `AuthorizationService` | Afleder roller fra Nextcloud-grupper (aldrig fra klienten), håndhæver `AccessPolicy` |
| `CommitteeService`, `MeetingService` | CRUD, statusovergange for møder |
| `AgendaItemService` | Punkter: opret/rediger/slet, indsend/godkend/afvis, rækkefølge, OpenCase-reference og bilag |
| `AgendaService` | Godkend og udsend dagsorden, uforanderlige snapshots med fastlåste dokumentversioner |
| `TreatmentService` | Beslutninger, videreførelse mellem udvalg, behandlingshistorik |
| `CaseHistoryService` | Læsemodel over en politisk sags behandlinger på tværs af udvalg |
| `ProtocolService`, `ProtocolRenderer` | Beslutningsprotokol (struktureret JSON + HTML), godkendelse og versionering |
| `JournalizationService` | Idempotent journalisering via adapteren, fejlregistrering |
| `PublicationService`, `PublicReadService` | Offentliggørelse (skrive- og læseside) |
| `AuditService` | Append-only auditlog |
| `DemoSeeder` | Idempotente demodata |

## Væsentlige arkitekturbeslutninger

1. **Heltals-PK + UUID.** `OCP\AppFramework\Db\Entity` kræver en heltals-`id`. Alle entiteter har derudover en unik `uuid`, og kun UUID'er eksponeres i API'et.
2. **Tidsstempler som UNIX-sekunder (BIGINT, UTC).** Det undgår tidszoneproblemer i DATETIME-kolonner. Frontenden formaterer i `Europe/Copenhagen`.
3. **JSON i TEXT-kolonner.** Snapshots, protokoller og publikationer gemmes som kanonisk JSON (sorterede nøgler) med en SHA-256-hash, så integriteten kan verificeres (`ContentHasher`).
4. **Uforanderlighed håndhæves i repository-laget.** `AgendaSnapshotMapper` og `AuditEventMapper` blokerer `update`/`delete`. `ProtocolMapper` læser den gemte status og afviser ændringer af godkendte protokoller.
5. **Entity-defaults er `null`.** Nextclouds `Entity::setter` markerer kun et felt som ændret, hvis værdien afviger fra den aktuelle. Med default `false` blev `isPublic = false` aldrig gemt; det blev fundet af integrationstestene og rettet.
6. **Offentliggørelse som materialiseret model.** Ved offentliggørelse bygges et whitelistet JSON-dokument (`PublicContentFactory`) ud fra seneste snapshot og seneste godkendte protokol og gemmes i `oa_publications`. Det offentlige API læser kun denne tabel og har ingen adgang til den interne model. Portalen kan derfor senere flyttes til et separat system (fx ved eksport af publikationerne).
7. **Intern API som almindelige controllers, ikke OCS.** Routes ligger under `/index.php/apps/openagenda/api/v1` med Nextclouds session- og CSRF-beskyttelse (`@nextcloud/axios` sender `requesttoken`). Skeletonets OCS-endpoint `/ocs/v2.php/apps/openagenda/api` er bevaret som discovery-endpoint.
8. **Roller via Nextcloud-grupper.** Gruppenavne kan konfigureres, og Nextcloud-administratorer får altid administratorrollen.
9. **Frontend uden ny state-ramme.** En lille reaktiv `session`-store og hash-routing (`vue-router`). Nye afhængigheder: `vue-router`, `@nextcloud/axios`, `@nextcloud/router` og `@mdi/js`, alle standard i Nextcloud-apps.
