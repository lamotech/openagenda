# Sikkerhed – roller, rettigheder og kendte begrænsninger

## Autentifikation og CSRF

- Det interne API kræver Nextcloud-login (`#[NoAdminRequired]`, men ikke `#[PublicPage]`). Nextclouds CSRF-kontrol er aktiv på alle interne endpoints.
- Kun to typer endpoints er offentlige (`#[PublicPage]`): `/public` (portalsiden) og `/api/public/v1/*` (kun GET, `#[AnonRateLimit(120/60s)]`).
- Protokollens HTML-visning (`/protocols/{id}/html`) er en GET uden sideeffekter. Den kræver login og rettighed og leveres med en CSP, der kun tillader inline-styles.

## Roller

Roller afledes **på serveren** af medlemskab af Nextcloud-grupper. Klientens rolleangivelse bruges aldrig.

| Rolle | Default-gruppe | Bemærkning |
|---|---|---|
| admin | `openagenda_admins` | Nextcloud-administratorer har altid rollen |
| secretary | `openagenda_secretaries` | |
| caseworker | `openagenda_caseworkers` | |
| politician | `openagenda_politicians` | |

Gruppenavnene kan ændres under Indstillinger (kun admin). Brugere uden rolle får "Ingen adgang".

## Rettighedsmatrix (`lib/Domain/AccessPolicy.php`)

| Handling | admin | secretary | caseworker | politician |
|---|---|---|---|---|
| Konfigurere demoen | ✔ | | | |
| Oprette og redigere udvalg og møder, skifte mødestatus | ✔ | ✔ | | |
| Søge i OpenCase | ✔ | ✔ | ✔ | |
| Oprette punkt | ✔¹ | ✔¹ | ✔ (møder i `planned`/`agenda_preparation`) | |
| Redigere og slette punkt | ✔² | ✔² | egne kladder | |
| Indsende punkt | ✔ | ✔ | egne kladder | |
| Godkende, afvise, ændre rækkefølge, udsende dagsorden | ✔ | ✔ | | |
| Registrere beslutning, videreføre | ✔ | ✔ | | |
| Læse protokol | ✔ | ✔ | | ✔ |
| Generere og godkende protokol, journalisere, offentliggøre | ✔ | ✔ | | |
| Læse auditlog | ✔ | ✔ | | |

¹ Også supplerende punkter på en udsendt dagsorden.
² Også udsendte punkter, så længe mødet ikke er startet. Rettelsen bliver først synlig ved en ny snapshot-version.

**Læseadgang til punkter:**
- Sekretariat og admin ser alt.
- Sagsbehandlere ser egne punkter og alle *offentlige* punkter.
- Politikere ser udsendte punkter (også lukkede) på møder, hvis dagsorden er udsendt.
- Ulæselige punkter giver 404 og vises som "Lukket punkt" i snapshots og historik.

## Lukkede punkter og offentlighed

- Offentligt indhold bygges **kun** ud fra uforanderlige snapshots og godkendte protokoller via en eksplicit whitelist (`PublicContentFactory`).
- Lukkede punkter reduceres til `{number, closed: true, title: "Lukket punkt"}`. Sagsnummer, titel, tekster og beslutning udelades.
- Kun bilag, som OpenCase-adapteren klassificerede som `public` ved udsendelsen, nævnes (titel og version, intet indhold). Dokumenter med ukendt adgangsniveau behandles som fortrolige.
- Det offentlige API læser kun `oa_publications` og har ingen adgang til de interne tabeller.

## Dokumentadgang

Adgang til OpenCase-sager og -dokumenter afgøres af adapteren, som altid kaldes med den konkrete brugers id. OpenAgenda tjekker adapterens beslutning ved søgning, når bilag tilknyttes, og i dokument-endpointet.
- **Mock:** eksplicit politik `public`/`internal`/`confidential` (se OPENCASE_INTEGRATION.md).
- **OpenCase-appen (`opencase_adapter=opencase`):** OpenCases egen adgangsmodel via `PermissionService`, `CaseService` og `DocumentService` med brugerens id. Fejl betyder afvist adgang. Intet dokument er offentligt, medmindre indsigtsgrader er konfigureret i `opencase_public_insight_levels`.

## Integritet og sporbarhed

- Snapshots og godkendte protokoller er forseglet med SHA-256 over kanonisk JSON, og integriteten verificeres ved visning og offentliggørelse.
- Repository-laget blokerer ændring og sletning af snapshots, godkendte protokoller og audit-events.
- Auditloggen har kun et læse-endpoint. Den registrerer tidspunkt, bruger, handling, objekttype, objekt-id og metadata (feltnavne og statusskift, ikke dokumentindhold).

## Kendte begrænsninger (v0.1)

- Ingen udvalgsmedlemskab: alle politikere kan læse alle udsendte dagsordener, også lukkede punkter i andre udvalg.
- Ingen inhabilitet, ingen differentieret adgang pr. lukket punkt og ingen fortrolighedsgrader ud over offentligt/lukket.
- Bilag kan ikke downloades, hverken internt eller offentligt.
- `occ openagenda:demo:reset --force` sletter alle data inklusive auditloggen. Kommandoen er kun til demo og test og kræver shelladgang.
- Rate limiting gælder kun det offentlige API.
- OpenCase-adgang via den in-process-adapter afhænger af OpenCases interne klasser (verificeret mod 1.3.0).
- Snapshots fastlåser bilagsversioner, som den udsendende sekretær kan se. Dokumenter, sekretæren ikke har adgang til, gemmes uden version og behandles som fortrolige.
- Protokollens HTML er server-escaped. Den vises i en `<iframe sandbox>` i UI'et.
