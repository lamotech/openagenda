# API – OpenAgenda v0.1

## Generelt

- **Internt API:** `/index.php/apps/openagenda/api/v1/...`. Det kræver en Nextcloud-login. Browseren sender sessionscookie og `requesttoken` (CSRF) via `@nextcloud/axios`. API-klienter kan bruge basic auth / app-password med headeren `OCS-APIRequest: true`.
- **Offentligt API:** `/index.php/apps/openagenda/api/public/v1/...`. Det kræver intet login, understøtter kun GET og er rate-limited (120 kald/min pr. IP for anonyme).
- **Discovery (OCS, fra skeleton):** `GET /ocs/v2.php/apps/openagenda/api`.
- Alle id'er i API'et er UUID'er. Tidsstempler er UNIX-sekunder. Datoer i input accepteres som ISO 8601 (fx `2026-12-14T16:00:00Z`).
- Request bodies er JSON.

### Statuskoder og fejlformat

| Kode | Betydning |
|---|---|
| 200 / 201 | OK / oprettet |
| 401 | Ikke logget ind (Nextcloud) |
| 403 | Mangler rettighed (`{"error": "..."}`) |
| 404 | Findes ikke eller er ikke synligt for brugeren. Lukkede punkter giver 404, så deres eksistens ikke afsløres |
| 409 | Ugyldig statusovergang eller konflikt (fx dobbelt videreførelse, låst protokol) |
| 422 | Valideringsfejl: `{"error": "...", "fields": {"felt": "besked"}}` |
| 502 | Fejl i OpenCase-integrationen |
| 500 | Uventet fejl (detaljer logges, vises ikke) |

Svar for dagsordenspunkter indeholder `permissions` (edit, delete, submit, approve, reject, reorder, decide, forward). Flagene er kun vejledende for UI'et; serveren kontrollerer altid selv.

## Internt API (`/api/v1`)

### Bruger og overblik
| Metode | Sti | Beskrivelse | Rolle |
|---|---|---|---|
| GET | `/me` | Roller, capabilities, kommunenavn, adapternavn | alle |
| GET | `/dashboard` | Kommende møder, møder under forberedelse, seneste beslutninger, punkter til godkendelse, egne punkter | alle med rolle |
| GET / PUT | `/settings` | Kommunenavn, rolle→gruppe-mapping og `openCase` (`adapter` = `mock` eller `opencase`, `publicInsightLevels`, `journalCategoryId`, `journalDocumentType`; read-only: `available`, `version`) | admin |
| GET | `/audit?objectType=&objectId=&meetingId=&limit=` | Auditlog (kun læsning) | sekretær, admin |

### Udvalg
| Metode | Sti | Body | Rolle |
|---|---|---|---|
| GET | `/committees?includeInactive=true` | – | alle |
| GET | `/committees/{id}` | – | alle |
| POST | `/committees` | `name`*, `type`*, `shortName`, `description`, `active` | sekretær, admin |
| PUT | `/committees/{id}` | samme felter (delvist) | sekretær, admin |
| DELETE | `/committees/{id}` | – (409 hvis der findes møder) | sekretær, admin |

### Møder
| Metode | Sti | Body / query | Rolle |
|---|---|---|---|
| GET | `/meetings?committeeId=&status=a,b&from=&order=asc\|desc` | – | alle |
| GET | `/meetings/{id}` | Mødet, synlige punkter, snapshot-liste, `permissions` | alle |
| POST | `/meetings` | `committeeId`*, `title`*, `startsAt`*, `endsAt`, `location` | sekretær |
| PUT | `/meetings/{id}` | samme (udvalg kan kun ændres, mens mødet er planlagt) | sekretær |
| DELETE | `/meetings/{id}` | kun planlagte møder uden punkter | sekretær |
| POST | `/meetings/{id}/status` | `status`: `planned`, `agenda_preparation`, `in_progress` eller `completed` (afslutning kræver beslutning på alle udsendte punkter) | sekretær |
| POST | `/meetings/{id}/publish-agenda` | `approveSubmitted` (bool). Godkender (valgfrit) og udsender og opretter et nyt snapshot (201) | sekretær |
| GET | `/snapshots/{id}` | Snapshot-indhold + `verified` (hash-kontrol). Lukkede punkter erstattes af en pladsholder for brugere uden adgang | alle |

### Dagsordenspunkter
| Metode | Sti | Body | Rolle |
|---|---|---|---|
| POST | `/items` | `meetingId`*, `title`*, `opencaseCaseId`, `summary`, `presentation`, `recommendation`, `legalBasis`, `financialImpact`, `isPublic`, `attachments` (`[{documentId}]`) | sagsbehandler (åbne møder), sekretær |
| GET | `/items/{id}` | Punkt + møde + `history` (tidligere behandlinger) | læseret jf. SECURITY.md |
| PUT | `/items/{id}` | samme felter, evt. `meetingId` (flyt) | ejer (kladde), sekretær |
| DELETE | `/items/{id}` | kun uden behandling | ejer (kladde), sekretær |
| POST | `/items/{id}/submit` | kræver sagsfremstilling og indstilling | ejer, sekretær |
| POST | `/items/{id}/approve` | – | sekretær |
| POST | `/items/{id}/reject` | `reason`* | sekretær |
| POST | `/items/{id}/move` | `direction`: `up` eller `down`. Returnerer mødets punkter | sekretær |
| GET | `/items/{id}/history` | Behandlingshistorik for den politiske sag | læseret |
| PUT | `/items/{id}/decision` | `decisionType`*, `decisionText` (påkrævet for `approved_with_amendments` og `referred`). Mødet skal være `in_progress` | sekretær |
| POST | `/items/{id}/forward` | `meetingId`* (møde i et andet udvalg, senere dato, åben dagsorden). Returnerer det nye punkt (201) | sekretær |

### OpenCase (via adapteren)
| Metode | Sti | Beskrivelse | Rolle |
|---|---|---|---|
| GET | `/opencase/cases?q=` | Søg i sager med brugerens OpenCase-rettigheder. Hver sag har de dokumenter, brugeren må se. Svaret indeholder `adapter` og `simulated` | sagsbehandler, sekretær, admin |
| GET | `/opencase/cases/{caseId}` | Sag + synlige dokumenter | alle med rolle |
| GET | `/opencase/documents/{documentId}` | Metadata og versioner. 403 hvis adapteren afviser adgang | alle med rolle |

### Protokol og journalisering
| Metode | Sti | Beskrivelse | Rolle |
|---|---|---|---|
| GET | `/meetings/{id}/protocols` | Versioner | sekretær, politiker, admin |
| POST | `/meetings/{id}/protocols` | Generér eller regenerér kladde (mødet skal være i gang eller afsluttet) | sekretær |
| GET | `/protocols/{id}` | Inkl. `html` og `verified` | sekretær, politiker, admin |
| POST | `/protocols/{id}/approve` | Kræver afsluttet møde og aktuel kladde (ellers 409) | sekretær |
| GET | `/index.php/apps/openagenda/protocols/{id}/html` | Selvstændigt HTML-dokument (til print) | sekretær, politiker, admin |
| POST | `/protocols/{id}/journalize` | Idempotent. Svar: `created`, `skipped`, `failed`, `journalizations[]` | sekretær |
| GET | `/protocols/{id}/journalizations` | Status, ekstern reference, fejl, antal forsøg | sekretær, politiker, admin |

### Offentliggørelse
| Metode | Sti | Beskrivelse | Rolle |
|---|---|---|---|
| GET | `/meetings/{id}/publications` | Historik | alle |
| POST | `/meetings/{id}/publications` | Offentliggør (kræver udsendt dagsorden; beslutninger tages med, hvis der er en godkendt protokol) | sekretær |
| POST | `/publications/{id}/withdraw` | Træk tilbage | sekretær |

## Offentligt API (`/api/public/v1`)

| Metode | Sti | Svar |
|---|---|---|
| GET | `/meetings` | Liste over offentliggjorte møder: `meeting` (uuid, title, startsAt, location), `committee`, `municipality`, `hasDecisions`, `itemCount`, `publishedAt` |
| GET | `/meetings/{uuid}` | Det offentliggjorte dokument (404 hvis ikke offentliggjort eller trukket tilbage) |

Et offentligt punkt indeholder **kun** felterne: `number`, `closed`, `title`, `caseNumber`, `summary`, `presentation`, `recommendation`, `legalBasis`, `financialImpact`, `attachments[{title, versionNumber}]` (kun offentlige dokumenter), `previousTreatments[{committeeName, meetingStartsAt, decisionLabel, decisionText}]` (kun offentlige behandlinger) og `decision{type, label, text}`.
Et lukket punkt returneres kun som `{"number": n, "closed": true, "title": "Lukket punkt"}`.

Eksempel:
```bash
curl https://<server>/index.php/apps/openagenda/api/public/v1/meetings
```
