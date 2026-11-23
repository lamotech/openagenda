# Datamodel – OpenAgenda v0.1

Alle tabeller har præfikset `oa_` (plus Nextclouds tabelpræfiks), en auto-increment `id` og en unik `uuid` (UUID v4).
Tidsstempler er UNIX-sekunder (UTC). Migration: `lib/Migration/Version000100Date20261123000000.php`.

```
Committee 1───* Meeting 1───* AgendaItem *───0..1 PoliticalCase ──(reference)──> OpenCase-sag
                   │              │ 1                    │ 1
                   │              └──1 Treatment *───────┘
                   │                    │ 1      └─ previous_treatment_id → Treatment (kæde ved videreførelse)
                   │                    └──0..1 Decision
                   ├──* AgendaSnapshot (version 1..n, uforanderlig)
                   ├──* Protocol (version 1..n, uforanderlig når godkendt) 1──* Journalization
                   └──* Publication (→ AgendaSnapshot, → Protocol)
AuditEvent (append-only, refererer objekter via uuid)
```

## Entiteter

| Entitet / tabel | Vigtige felter | Regler |
|---|---|---|
| `Committee` / `oa_committees` | name (unik), short_name, description, type (`standing_committee`, `finance_committee`, `city_council`, `special_committee`), active | Kan ikke slettes, hvis der findes møder |
| `Meeting` / `oa_meetings` | committee_id (FK), title, starts_at, ends_at, location, status | Status: `planned → agenda_preparation → agenda_published → in_progress → completed` (plus `agenda_preparation → planned`). `agenda_published` nås kun via udsendelse |
| `PoliticalCase` / `oa_pol_cases` | opencase_case_id, case_number*, title*, status (`open`/`decided`) | *Cachet visningskopi. Ét åbent politisk forløb pr. OpenCase-sag genbruges. Bliver `decided`, når et byråd træffer en endelig beslutning |
| `AgendaItem` / `oa_agenda_items` | meeting_id (FK), political_case_id (FK), title, summary, presentation, recommendation, legal_basis, financial_impact, attachments (JSON: documentId, title), is_public, sort_order, status, rejection_reason, forwarded_from_item_id, created_by | Status: `draft → submitted → approved → published`. Afvisning: `submitted/approved → draft` |
| `Treatment` / `oa_treatments` | political_case_id (FK), meeting_id (FK), agenda_item_id (FK, unik), recommendation, status (`pending`, `decided`, `forwarded`), treated_at, previous_treatment_id, forwarded_to_item_id | Ét punkt på ét møde. Oprettes ved udsendelse eller videreførelse og overskrives aldrig ved videreførelse |
| `Decision` / `oa_decisions` | treatment_id (FK, unik), decision_type, decision_text, decided_by, decided_at | Typer: `approved`, `rejected`, `approved_with_amendments`, `postponed`, `referred`. De to sidste typer, der har ændringer eller henvisning, kræver tekst. Låst når mødet er afsluttet, punktet er videreført eller protokollen er godkendt |
| `AgendaSnapshot` / `oa_snapshots` | meeting_id (FK), version, content (JSON), content_hash (SHA-256), created_by | Unik (meeting, version). Indeholder fastlåste OpenCase-dokumentversioner pr. bilag og tidligere behandlinger. Kan aldrig ændres |
| `Protocol` / `oa_protocols` | meeting_id (FK), version, status (`draft`/`approved`), content (JSON), html, content_hash, generated_*, approved_* | Kladden kan regenereres. Godkendelse kræver afsluttet møde og et aktuelt indhold. Godkendte versioner er uforanderlige; ny generering giver version+1 |
| `Journalization` / `oa_journalizations` | protocol_id (FK), opencase_case_id, idempotency_key (unik), status (`pending`/`succeeded`/`failed`), external_ref, error_message, attempts, payload_hash | Unik (protocol, case). En vellykket række sendes aldrig igen |
| `Publication` / `oa_publications` | meeting_id (FK), snapshot_id (FK), protocol_id (FK), status (`published`/`withdrawn`), content (whitelistet JSON) | Højst én aktiv pr. møde; ny offentliggørelse trækker den forrige tilbage |
| `AuditEvent` / `oa_audit_events` | occurred_at, user_id, action, object_type, object_id, meeting_uuid, metadata (JSON) | Kun indsættelse. Metadata indeholder feltnavne og statusskift, ikke dokumentindhold |

## Mock-OpenCase (kun `MockOpenCaseAdapter`)

`oa_mock_cases`, `oa_mock_documents` (versions som JSON, access_level `public`/`internal`/`confidential`) og `oa_mock_journal` (unik idempotency_key).
Tabellerne simulerer OpenCase og er ikke en del af OpenAgendas egen domænemodel.

## Foreign keys

Der er foreign keys på alle centrale relationer: møde→udvalg, punkt→møde/politisk sag, behandling→punkt/møde/politisk sag, beslutning→behandling, snapshot/protokol/publikation→møde, journalisering→protokol og publikation→snapshot/protokol.
Der er ingen kaskadesletning; sletning er kun tilladt for objekter uden afhængigheder.
