# OpenCase-integration – adapterkontrakt og mock

## Princip

OpenCase er master for sager, dokumenter, dokumentversioner og journalposter.
OpenAgenda kalder OpenCase udelukkende gennem porten `OCA\OpenAgenda\OpenCase\OpenCaseAdapterInterface` og gemmer kun referencer.
Domæne- og applikationslaget kender kun interfacet, så adapteren kan udskiftes uden ændringer i domænelogikken.

## Kontrakt (`lib/OpenCase/OpenCaseAdapterInterface.php`)

| Metode | Formål | Brugt af |
|---|---|---|
| `getName(): string` | Adapternavn (vises i UI) | UI |
| `searchCases(query, ?userId, limit): OpenCaseCase[]` | Sagsøgning med brugerens rettigheder | Søgevisning |
| `getCase(caseId, ?userId): ?OpenCaseCase` | Sagsmetadata (null hvis ikke fundet eller ingen adgang). Validerer referencen ved oprettelse af punkt | `AgendaItemService` |
| `listDocuments(caseId, ?userId): OpenCaseDocument[]` | Dokumenter, **filtreret efter brugerens adgang** | Søgning, punkteditor |
| `getDocument(documentId, ?userId)` / `listDocumentVersions(documentId, ?userId)` | Metadata og versioner. Seneste version fastlåses i snapshot ved udsendelse | `AgendaService` |
| `canAccessDocument(?userId, documentId): bool` | **Adgangsbeslutning delegeret til adapteren** (`null` = anonym/offentlig) | Bilagsvalidering, dokument-endpoint |
| `journalize(JournalizationRequest): JournalizationResult` | Opret journalpost. **Skal være idempotent på `idempotencyKey`** | `JournalizationService` |

| `isSimulated(): bool` | Om data er simulerede (styrer demo-mærkning i UI) | UI |

Alle læsninger tager id'et på den bruger, de udføres for (`null` = anonym/system og giver højst offentlig adgang).
Fejl signaleres med `OpenCaseException`. De registreres på journaliseringen og vises som HTTP 502 eller 409.

DTO'er: `OpenCaseCase`, `OpenCaseDocument` (accessLevel `public`/`internal`/`confidential`, latestVersion), `OpenCaseDocumentVersion`, `JournalizationRequest` (caseId, idempotencyKey, title, documentType, contentHtml, requestedBy, metadata) og `JournalizationResult` (journalReference, alreadyExisted).

Idempotensnøglen har formatet `openagenda:protocol:<protocol-uuid>:case:<opencase-case-id>`.

## Antagelser og afgrænsning

- Kontrakten er defineret ud fra OpenAgendas behov. **Den beskriver ikke et konkret OpenCase-API**, og der er ikke opfundet OpenCase-endpoints.
- På testserveren er der installeret en separat Nextcloud-app `opencase` (v1.3.0) med egen API-dokumentation. v0.1 integrerer bevidst **ikke** med den. En rigtig adapter skal skrives mod det dokumenterede OpenCase-API og verificeres der, herunder om OpenCase understøtter idempotente journalposter. Hvis ikke, skal adapteren selv slå op på nøglen, fx via et metadatafelt.
- Adgangsniveauerne `public`/`internal`/`confidential` er en forenkling. En rigtig adapter bør oversætte OpenCases faktiske adgangsmodel til `canAccessDocument()` og `listDocuments()`.

## MockOpenCaseAdapter (`lib/OpenCase/Mock/MockOpenCaseAdapter.php`)

- Data ligger i `oa_mock_cases`, `oa_mock_documents` og `oa_mock_journal` og oprettes af `occ openagenda:demo:seed`. Alle sager er tydeligt mærket "FIKTIV".
- Søgning: case-insensitive `LIKE` på sagsnummer, titel og beskrivelse.
- **Eksplicit adgangspolitik:**
  - `public`: alle, også anonyme
  - `internal`: alle indloggede brugere
  - `confidential`: Nextcloud-administratorer og medlemmer af gruppen i app-config `mock_confidential_group` (default `openagenda_secretaries`)
- Journalisering: returnerer referencer som `MOCK-JNL-2026-1A2B3C4D`. Samme idempotensnøgle giver samme reference (også ved samtidige kald, takket være en unik constraint).
- **Fejlsimulering:** `occ config:app:set openagenda mock_journalize_fail --value=1` får `journalize()` til at fejle. Fejlen registreres på journaliseringen og kan prøves igen efter `occ config:app:delete openagenda mock_journalize_fail`.

Begrænsninger: Der er intet dokumentindhold og ingen download, versionerne er statiske, og sagsstatus ændres ikke ved journalisering.

## Valg af adapter

Adapteren vælges af `OpenCaseAdapterFactory` ud fra app-config `opencase_adapter`. Det kan sættes under **Indstillinger** (kun admin) eller med occ:

```bash
sudo -u www-data php occ config:app:set openagenda opencase_adapter --value=opencase   # rigtig OpenCase
sudo -u www-data php occ config:app:set openagenda opencase_adapter --value=mock       # simuleret (default)
```

Hvis `opencase` er valgt, men OpenCase-appen ikke er aktiveret, falder OpenAgenda **ikke** stille tilbage til mocken. I stedet fejler alle OpenCase-kald synligt, og dokumentadgang nægtes (`UnavailableOpenCaseAdapter`).

**Bemærk:** Punkter, der er oprettet med mocken, refererer til mock-sager (`mock-case-…`). Efter et skift til `opencase` kan de ikke længere slås op. Vælg adapter, før der oprettes rigtige data.

## InProcessOpenCaseAdapter (OpenCase-appen på samme server)

`lib/OpenCase/InProcess/InProcessOpenCaseAdapter.php` kalder OpenCase-appens PHP-services direkte i samme Nextcloud. Alle kald udføres **med den indloggede brugers id**, så OpenCases egen adgangsmodel afgør, hvad brugeren ser: adgangsprofiler, sagsrettigheder og afvisninger på dokumentniveau. Den er verificeret mod OpenCase 1.3.0.

| Kontrakt | Implementering |
|---|---|
| `searchCases` | `CaseService::list($userId, …, search)`. Indbakke-sager udelades. Status og resumé hentes via `CaseExportService::caseFields` |
| `getCase` | `CaseService::getByUuid($uuid, $userId)`. Ingen adgang giver `null`. OpenCase logger "sag vist" i sin auditlog |
| `listDocuments` | `DocumentService::listByCase($caseId, $userId)`, som er filtreret af OpenCase |
| `getDocument`, `canAccessDocument` | `DocumentMapper::findByUuid` + `PermissionService::userHasReadAccessToDocument($userId, …)`. Fejl betyder afvist adgang (fail closed) |
| Versioner | Et dokuments version er sammensat af `fil-uuid:vN` for dokumentets filer, med checksum. Den fastlåses i snapshot ved udsendelse. Historiske filversioner vises kun for dokumenter med én fil |
| Offentlighed | Indsigtsgrad (dokument, ellers sag) i `opencase_public_insight_levels` giver `public`. **Default: ingen.** Grad 3–4 giver `confidential`, resten `internal` |
| `journalize` | `DocumentService::create` (kategori `opencase_journal_category_id`, default 3 "Intern", type `opencase_journal_document_type`, default "Referat") + `FileService::upload` af protokollen som HTML. Det sker som den journaliserende bruger, som skal have skriveadgang til sagen i OpenCase. Journalreferencen er OpenCases dokumentnummer |
| Idempotens | OpenCase har ingen idempotensnøgle. Adapteren lægger en markør `[OA-<12 hex af nøglen>]` i dokumenttitlen og søger efter den, før den opretter. Et dokument uden fil fra et afbrudt forsøg bliver gjort færdigt |

Krav til brugerne: Sagsbehandlere og politikere skal have læseadgang til sagerne i OpenCase, og sekretariatet skal have skriveadgang for at kunne journalisere.

Begrænsninger:
- Adapteren afhænger af OpenCases interne klasser, som ikke er en stabil kontrakt. Ved andre versioner end 1.3.x logges en advarsel, og ændrede signaturer giver `OpenCaseException` i stedet for fatale fejl.
- Samtidige journaliseringer af samme protokol kan i teorien oprette to dokumenter, fordi OpenCase ikke har nogen unik constraint at falde tilbage på.
- Protokollen journaliseres som HTML, ikke PDF, og dokumentet sættes ikke til status "endelig".
- Der er ingen download af bilag gennem OpenAgenda.

Tests:
- `tests/Unit/InProcessOpenCaseAdapterTest.php` tester mapping, adgang, versioner og idempotens mod stand-ins for OpenCase-services.
- `tests/Integration/OpenCaseInProcessLiveTest.php` er en opt-in læsetest mod den rigtige app: `OA_OPENCASE_LIVE_USER=<uid>`.
- `tests/Integration/OpenCaseInProcessJournalizationTest.php` er en opt-in test af hele flowet, som **skriver i OpenCase**. Den kræver `OA_OPENCASE_LIVE_USER` og `OA_OPENCASE_TEST_CASE=<sags-uuid>` og skal køres alene (`--filter`). Den er kørt mod testsagen 2026-00129 og bestod.

## Ny adapter (fx HTTP mod det offentlige data-API)

1. Implementér `OpenCaseAdapterInterface`.
2. Tilføj den i `OpenCaseAdapterFactory`.
3. Tilføj tests efter samme mønster som ovenfor.

## Analyse: dækker OpenCase 1.3.0 adapterkontrakten? (23.11.2026)

Kilder: `apps/opencase/docs/api.md`, `apps/opencase/API.md`, `apps/opencase/docs/api/public-api.schema.json`, `appinfo/routes.php` og koden i `lib/Controller/PublicApi` og `lib/Service`.

OpenCase har to API'er:
- **Det offentlige data-API** `/index.php/apps/opencase/public/v1/api/...` er beregnet til system-til-system-integration. Klienten autentificerer med et SAML-token fra Serviceplatformen SF1512 (Bearer) og et registreret klientcertifikat. **Alle kald kører som én Nextcloud-bruger, som klienten er mappet til** (`mapped_to_user` i `PublicDataApiMiddleware`).
- **Det interne OCS-API** `/ocs/v2.php/apps/opencase/api/v1/...` bruges af OpenCases egen brugerflade (session).

| Adaptermetode | OpenCase 1.3.0 | Vurdering |
|---|---|---|
| `searchCases` | `GET /cases/search?search=` (metadata) og `GET /search?q=` (fuldtekst) | ✅ Dækket |
| `getCase` | `GET /cases?uuid=` eller `?case_number=` (CaseNumber, Title, Summary, StatusName …) | ✅ Dækket. Brug sagens `Uuid` som `opencaseCaseId` |
| `listDocuments` | `GET /cases/documents?uuid=` | ✅ Dækket, men filtreret som API-klientens bruger, ikke slutbrugeren |
| `getDocument` / `listDocumentVersions` | `GET /documents?uuid=` (indeholder `Files[]` med `Version` og `Checksum`), `GET /files/versions?uuid=` | ⚠️ Delvist. Versioner findes **pr. fil**, ikke pr. dokument. Historiske versioner har intet versionsnummer, kun `Uuid`, `Timestamp` og `Checksum`. Snapshot skal derfor fastlåse (fil-uuid, version, checksum) for hver fil i dokumentet |
| `canAccessDocument(userId, …)` | Intet endpoint, der svarer for en vilkårlig bruger. Internt findes `PermissionService::userHasReadAccessToDocument($userId, $documentId)` | ❌ Ikke via API. Delegeret adgang pr. slutbruger kræver et in-process-kald til OpenCases PHP-services eller en ny API-funktion |
| Offentlig/ikke-offentlig | Dokumenter har `InsightLevelId` (1 "Åben (Offentlig)", 2 "Intern", 3 "Begrænset (Fortrolig)", 4 "Lukket") | ⚠️ Kan mappes, men "Åben" betyder "tilgængelig for alle i organisationen og evt. via aktindsigt", ikke "må publiceres på nettet". Kræver en forretningsbeslutning eller et eksplicit publiceringsflag |
| `journalize` | `POST /cases/documents` (kræver `Title` og `DocumentCategoryId`) + `POST /documents/files` (base64) + evt. `PUT /documents` med status final. Alternativt `POST /cases/journal-notes` | ⚠️ Muligt, men **uden idempotensnøgle**. Det kræver to kald, så et afbrudt forløb kan efterlade et dokument uden fil |

### Konklusion

OpenCase 1.3.0 har API'er til at søge sager, hente sagsmetadata, liste dokumenter og filversioner og oprette journaldokumenter. **To krav i kontrakten er ikke dækket direkte:**

1. **Adgangskontrol pr. slutbruger.** Det offentlige API kører som én systembruger, så OpenAgenda ville se alt, som systembrugeren kan se.
2. **Idempotent journalisering.** API'et har ingen idempotensnøgle, og oprettelse er fordelt over flere kald.

### Muligheder

- **A. Offentligt data-API + systembruger:** Den stabile, dokumenterede kontrakt. Kræver serviceaftale, SF1512-token og certifikat. Adgang pr. bruger kan ikke delegeres, og OpenAgenda må selv filtrere (fx på `InsightLevelId`), hvilket bryder princippet om delegeret adgang. Idempotens skal OpenAgenda selv sikre: gem dokumentets `Uuid` i `oa_journalizations`, før filen uploades, sæt protokollens uuid i titlen, og slå op med `GET /documents/search?title=` før en ny oprettelse.
- **B. In-process-adapter i samme Nextcloud:** OpenAgenda kalder OpenCases services (`CaseService`, `DocumentService`, `PermissionService`) med den indloggede brugers id. Det giver ægte adgangskontrol pr. bruger uden token-opsætning. Til gengæld er det koblet til interne klasser, som ikke er en stabil kontrakt, og begge apps skal ligge på samme instans.
- **C. Udvid OpenCase (anbefalet på sigt):** Tilføj (1) et endpoint eller en PHP-interface til adgangstjek "på vegne af" en bruger, (2) en idempotensnøgle eller ekstern reference på dokumenter og journalnotater og (3) et eksplicit "må offentliggøres"-flag på dokumenter.

**Status:** Mulighed B er implementeret som `InProcessOpenCaseAdapter` (se ovenfor).

Anbefaling: B som næste skridt til demo og pilot på samme server, bag `OpenCaseAdapterInterface`. Samtidig bør C aftales med OpenCase-teamet, så en produktionsadapter kan bygges på A og C.

Kode i begge apps er kun læst. Der er ikke foretaget kald mod OpenCase, og der er ikke ændret noget i OpenCase.
