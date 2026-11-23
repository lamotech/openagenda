/**
 * Browser end-to-end test of the OpenAgenda demo scenario through the UI.
 *
 * Optional tool – not part of the PHPUnit suite and not a package dependency.
 * Requires `playwright-core` and a Chromium binary, plus three existing users
 * with the roles secretary, caseworker and politician, and freshly seeded demo
 * data (occ openagenda:demo:reset --force).
 *
 * Environment:
 *   OA_BASE_URL, CHROMIUM_PATH, OA_SCREENSHOT_DIR (optional),
 *   OA_SECRETARY_USER/PASS, OA_CASEWORKER_USER/PASS, OA_POLITICIAN_USER/PASS
 *
 * Run: NODE_PATH=<dir with playwright-core> node tests/e2e/demo-ui.mjs
 */
import { createRequire } from 'node:module'
import assert from 'node:assert/strict'

const require = createRequire(import.meta.url)
const { chromium } = require('playwright-core')

const base = process.env.OA_BASE_URL.replace(/\/$/, '')
const shots = process.env.OA_SCREENSHOT_DIR
const app = base + '/index.php/apps/openagenda/'
const TMU = 'Teknik- og Miljøudvalget – Ordinært møde (demo 1)'
const BR = 'Byrådet – Byrådsmøde (demo 1)'

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH, args: ['--no-sandbox'] })
let step = 0

async function shot(page, name) {
	if (shots) {
		await page.screenshot({ path: `${shots}/${String(++step).padStart(2, '0')}-${name}.png`, fullPage: true })
	}
}

async function login(role, attempt = 1) {
	const context = await browser.newContext({ locale: 'da-DK', viewport: { width: 1400, height: 1000 } })
	// Nextcloud's first-run wizard (shown to new users) would cover the UI.
	await context.route(/\/apps\/firstrunwizard\//, route => route.abort())
	const page = await context.newPage()
	page.setDefaultTimeout(60000)
	await page.goto(base + '/index.php/login', { timeout: 90000, waitUntil: 'domcontentloaded' })
	await page.fill('input[name="user"]', process.env[`OA_${role}_USER`])
	await page.fill('input[name="password"]', process.env[`OA_${role}_PASS`])
	await page.locator('button[type="submit"]').first().click()
	try {
		await page.waitForURL(url => !url.pathname.includes('/login'), { waitUntil: 'domcontentloaded', timeout: 30000 })
	} catch (e) {
		await shot(page, `login-failed-${role}-${attempt}`)
		await context.close()
		if (attempt < 3) {
			return login(role, attempt + 1)
		}
		throw e
	}
	return page
}

async function selectOption(page, label, optionText) {
	const id = await page.locator('label', { hasText: label }).first().getAttribute('for')
	const select = page.locator(`select[id="${id}"]`)
	const value = await select.locator('option', { hasText: optionText }).first().getAttribute('value')
	await select.selectOption(value)
}

async function openMeeting(page, text) {
	await page.goto(app + '#/meetings', { waitUntil: 'domcontentloaded' })
	const [committee, title] = text.split(' – ')
	await page.locator('tr', { hasText: committee }).filter({ hasText: title }).getByRole('link').first().click()
	await page.getByRole('tab', { name: 'Dagsorden' }).waitFor()
}

async function clickButton(page, name) {
	await page.getByRole('button', { name, exact: true }).first().click()
}

// 1-3. Caseworker: find OpenCase case, create item on the TMU meeting, submit.
const cw = await login('CASEWORKER')
await cw.goto(app + '#/opencase', { waitUntil: 'domcontentloaded' })
await cw.getByRole('textbox', { name: 'Søg på sagsnummer, titel eller beskrivelse', exact: true }).fill('DEMO-2026-001')
await clickButton(cw, 'Søg')
await cw.getByText('Forslag til lokalplan for nyt boligområde').first().waitFor()
await shot(cw, 'opencase-search')
await clickButton(cw, 'Opret dagsordenspunkt for denne sag')
await cw.getByText('DEMO-2026-001').first().waitFor()
await selectOption(cw, 'Møde', TMU.split(' – ')[1])
await cw.getByText('Forslag til lokalplan 2026-12 (fiktiv)').click()
await cw.getByText('Miljøscreening af lokalplanforslag (fiktiv)').click()
await cw.getByRole('textbox', { name: 'Sagsfremstilling', exact: true }).fill('Forvaltningen har udarbejdet forslag til lokalplan 2026-12 for et nyt boligområde ved Engvej (fiktivt).')
await cw.getByRole('textbox', { name: 'Indstilling', exact: true }).fill('Forvaltningen indstiller, at forslag til lokalplan 2026-12 vedtages og sendes i offentlig høring.')
await cw.getByRole('textbox', { name: 'Lovgrundlag', exact: true }).fill('Planloven §§ 13 og 24 (fiktivt eksempel).')
await cw.getByRole('textbox', { name: 'Økonomiske konsekvenser', exact: true }).fill('Ingen direkte økonomiske konsekvenser.')
await shot(cw, 'item-form')
await clickButton(cw, 'Gem og indsend til godkendelse')
await cw.getByText('Politiske behandlinger af sagen').waitFor()
assert.ok(await cw.getByText('Indsendt').first().isVisible(), 'item submitted')
await shot(cw, 'item-submitted')
// Close sessions that are no longer needed (long-polling pages occupy PHP workers).
await cw.context().close()

// 4. Secretariat approves and sends out the agenda.
const sec = await login('SECRETARY')
await openMeeting(sec, TMU)
await clickButton(sec, 'Godkend og udsend')
await sec.getByText('Dagsordenen er udsendt').waitFor()
await shot(sec, 'tmu-agenda-published')
await sec.getByRole('tab', { name: /Udsendte versioner/ }).click()
await clickButton(sec, 'Vis')
await sec.getByText('Integritet verificeret').waitFor()
await shot(sec, 'tmu-snapshot')

// 5. Committee treats the item and registers its recommendation.
await clickButton(sec, 'Start møde')
await sec.getByText('Mødestatus er opdateret').waitFor()
await sec.getByRole('tab', { name: 'Mødeafvikling' }).click()
await selectOption(sec, 'Beslutningstype', 'Godkendt')
await sec.getByRole('textbox', { name: 'Beslutningstekst / indstilling til næste instans', exact: true }).fill('Teknik- og Miljøudvalget anbefaler over for Byrådet, at lokalplanforslaget godkendes.')
await clickButton(sec, 'Registrér beslutning')
await sec.getByRole('button', { name: 'Viderefør til andet udvalg' }).waitFor()

// 6. Forward to the city council meeting.
await clickButton(sec, 'Viderefør til andet udvalg')
await selectOption(sec, 'Vælg møde (fx byrådsmøde)', 'Byrådsmøde (demo 1)')
await shot(sec, 'tmu-forward')
await clickButton(sec, 'Viderefør')
await sec.getByText('Videreført', { exact: true }).first().waitFor()
await clickButton(sec, 'Afslut møde')
await sec.getByText('Mødestatus er opdateret').waitFor()
await shot(sec, 'tmu-completed')

// 7. City council: send out agenda, see earlier recommendation, decide.
await openMeeting(sec, BR)
await clickButton(sec, 'Godkend og udsend')
await sec.getByText('Dagsordenen er udsendt').waitFor()

const pol = await login('POLITICIAN')
await openMeeting(pol, BR)
await pol.getByRole('link', { name: 'Forslag til lokalplan for nyt boligområde' }).first().click()
await pol.getByText('Teknik- og Miljøudvalget anbefaler over for Byrådet').first().waitFor()
await shot(pol, 'politician-sees-history')
await pol.context().close()

await clickButton(sec, 'Start møde')
await sec.getByText('Mødestatus er opdateret').waitFor()
await sec.getByRole('tab', { name: 'Mødeafvikling' }).click()
await selectOption(sec, 'Beslutningstype', 'Godkendt')
await sec.getByRole('textbox', { name: 'Beslutningstekst / indstilling til næste instans', exact: true }).fill('Byrådet godkendte lokalplanforslaget og sender det i offentlig høring.')
await clickButton(sec, 'Registrér beslutning')
await sec.getByText('Byrådet godkendte lokalplanforslaget').first().waitFor()
await clickButton(sec, 'Afslut møde')
await sec.getByText('Mødestatus er opdateret').waitFor()

// 8. Protocol generated and approved.
await sec.getByRole('tab', { name: 'Beslutningsprotokol' }).click()
await clickButton(sec, 'Generér beslutningsprotokol')
await clickButton(sec, 'Godkend protokol')
await sec.getByText('Godkendt og fastlåst').waitFor()

// 9. Journalized in OpenCase (mock) – idempotent.
await clickButton(sec, 'Journalisér i OpenCase')
await sec.getByText(/Journalisering: 1 nye/).waitFor()
await sec.getByText(/MOCK-JNL-/).first().waitFor()
await clickButton(sec, 'Journalisér i OpenCase')
await sec.getByText(/Journalisering: 0 nye, 1 allerede journaliseret/).waitFor()
await shot(sec, 'br-protocol-journalized')

// 10. Public agenda and decision.
await sec.getByRole('tab', { name: 'Offentliggørelse' }).click()
await clickButton(sec, 'Offentliggør dagsorden og beslutninger')
await sec.getByText('Offentliggjort', { exact: true }).first().waitFor()
await shot(sec, 'br-published')

const anon = await (await browser.newContext({ locale: 'da-DK' })).newPage()
await sec.context().close()
await anon.goto(base + '/index.php/apps/openagenda/public', { waitUntil: 'domcontentloaded' })
await anon.getByRole('link', { name: /Byrådet/ }).first().click()
await anon.getByText('Byrådet godkendte lokalplanforslaget').waitFor()
await anon.getByText('Tidligere behandling: Teknik- og Miljøudvalget', { exact: false }).first().waitFor()
await shot(anon, 'public-decision')

await browser.close()
console.log('UI end-to-end scenario completed successfully (10/10 steps).')
