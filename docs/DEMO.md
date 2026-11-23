# Demovejledning – OpenAgenda v0.1

Scenariet: Lokalplanen *DEMO-2026-001 "Forslag til lokalplan for nyt boligområde"* går fra OpenCase gennem Teknik- og Miljøudvalget til Byrådet og bliver til sidst offentliggjort.
Alle data er fiktive.

## 1. Forberedelse

```bash
cd /var/www/nextcloud
# Frisk demo (SLETTER alle OpenAgenda-data inkl. auditlog – kun demo/test):
sudo -u www-data php occ openagenda:demo:reset --force
# eller idempotent indlæsning uden sletning:
sudo -u www-data php occ openagenda:demo:seed
```

Seederen opretter:
- kommunen "Demo Kommune (fiktiv)"
- grupperne `openagenda_admins`, `openagenda_secretaries`, `openagenda_caseworkers` og `openagenda_politicians`
- udvalgene Teknik- og Miljøudvalget, Økonomiudvalget og Byrådet
- fire møder: TMU om 7 og 35 dage, ØU om 14 dage og Byrådet om 21 dage
- fem fiktive OpenCase-sager med dokumenter

Seederen opretter **ingen brugere**. Tildel roller til eksisterende brugere:

```bash
sudo -u www-data php occ group:adduser openagenda_caseworkers <sagsbehandler>
sudo -u www-data php occ group:adduser openagenda_secretaries <sekretær>
sudo -u www-data php occ group:adduser openagenda_politicians <politiker>
# alternativt: occ openagenda:demo:seed --role <uid>:caseworker --role <uid>:secretary
```

Nextcloud-administratorer har alle rettigheder og kan gennemføre hele demoen alene.
Brug helst tre browsere eller private vinduer for at vise rollerne.

## 2. Gennemgang

| # | Rolle | Handling i UI | Forventet resultat |
|---|---|---|---|
| 1 | Sagsbehandler | **Søg i OpenCase** → søg `DEMO-2026-001` | Sagen vises med sagsnummer, beskrivelse, status og dokumenter. Det fortrolige høringssvar er skjult af adapterens adgangspolitik |
| 2 | Sagsbehandler | **Opret dagsordenspunkt for denne sag** | Formular med titel og resumé forudfyldt fra sagen |
| 3 | Sagsbehandler | Vælg mødet *Teknik- og Miljøudvalget – Ordinært møde (demo 1)*, vælg bilag, udfyld sagsfremstilling, indstilling m.m. → **Gem og indsend til godkendelse** | Punktet har status *Indsendt*. Auditloggen registrerer oprettelse og indsendelse |
| 4 | Sekretariat | **Møder** → TMU-mødet → fanen *Dagsorden* → **Godkend og udsend** | Mødet får status *Dagsorden udsendt*. Under *Udsendte versioner* → **Vis** ses version 1 med "Integritet verificeret" og fastlåste dokumentversioner (fx v3) |
| 5 | Sekretariat | **Start møde** → fanen *Mødeafvikling* → vælg *Godkendt*, skriv "TMU anbefaler godkendelse" → **Registrér beslutning** | Behandlingen er *Behandlet* |
| 6 | Sekretariat | **Viderefør til andet udvalg** → vælg *Byrådet – Byrådsmøde (demo 1)* → **Viderefør**. Derefter **Afslut møde** | Der oprettes et nyt punkt på byrådsmødet med samme OpenCase-sag. TMU-behandlingen bevares med status *Videreført* |
| 7 | Sekretariat | Byrådsmødet → **Godkend og udsend** | Snapshottet viser "Tidligere behandlinger: Teknik- og Miljøudvalget … Godkendt" |
| 7b | Politiker | Byrådsmødet → punktet | Under *Politiske behandlinger af sagen* ses udvalgets indstilling, før byrådet beslutter |
| 7c | Sekretariat | **Start møde** → *Mødeafvikling* → *Godkendt*, "Byrådet godkender lokalplanen" → **Registrér beslutning** → **Afslut møde** | Den politiske sag får status *Afgjort*. TMU's beslutning er uændret |
| 8 | Sekretariat | Fanen *Beslutningsprotokol* → **Generér beslutningsprotokol** → **Godkend protokol** | HTML-protokollen viser kommune, udvalg, dato, sagsnummer, indstilling, beslutning og tidligere behandling. Efter godkendelse er den "Godkendt og fastlåst" |
| 9 | Sekretariat | **Journalisér i OpenCase**, og gentag klikket | Første klik: "1 nye" med reference `MOCK-JNL-…`. Andet klik: "0 nye, 1 allerede journaliseret" (idempotent) |
| 10 | Sekretariat | Fanen *Offentliggørelse* → **Offentliggør dagsorden og beslutninger** → **Se offentlig visning** | Den offentlige portal (`/index.php/apps/openagenda/public`) viser punktet, indstillingen, TMU's behandling og byrådets beslutning uden login |

## 3. Ekstra demonstrationer

- **Lukket punkt:** Opret et punkt på DEMO-2026-004 (ejendomskøb) og slå "Offentligt punkt" fra. Efter offentliggørelse vises det kun som "Lukket punkt". En anden sagsbehandler får 404.
- **Afvisning:** Sekretariatet klikker **Afvis** med en begrundelse. Punktet går tilbage til *Kladde*, og begrundelsen vises for sagsbehandleren.
- **Rettelse efter udsendelse:** Ret et udsendt punkt (sekretariat) og klik **Udsend ny version**. Version 1 er uændret, og version 2 indeholder rettelsen.
- **Journaliseringsfejl:** `occ config:app:set openagenda mock_journalize_fail --value=1`, journalisér (status *Fejlet* med fejltekst), `occ config:app:delete openagenda mock_journalize_fail`, og journalisér igen (*Journaliseret*, 2 forsøg).
- **Auditlog:** fanen *Auditlog* på et møde eller sektionen nederst på et punkt.
- **Persistens:** Alle data ligger i Nextclouds database og overlever genstart af webserver og PHP-FPM.

## 4. Automatiseret gennemløb

- `tests/Integration/EndToEndHttpTest.php` gennemfører hele scenariet via HTTP-API'et (kører med PHPUnit).
- `tests/e2e/demo-ui.mjs` gennemfører scenariet i en rigtig browser. Det er et valgfrit værktøj, som kræver `playwright-core`, Chromium og tre brugere med roller; se kommentaren øverst i filen.
