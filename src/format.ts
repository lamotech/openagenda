const dateTimeFormat = new Intl.DateTimeFormat('da-DK', { dateStyle: 'long', timeStyle: 'short', timeZone: 'Europe/Copenhagen' })
const dateFormat = new Intl.DateTimeFormat('da-DK', { dateStyle: 'medium', timeZone: 'Europe/Copenhagen' })

export function formatDateTime(ts?: number | null): string {
	return ts ? dateTimeFormat.format(new Date(ts * 1000)) : '–'
}

export function formatDate(ts?: number | null): string {
	return ts ? dateFormat.format(new Date(ts * 1000)) : '–'
}

export const decisionTypes = [
	{ value: 'approved', label: 'Godkendt' },
	{ value: 'approved_with_amendments', label: 'Godkendt med ændringer' },
	{ value: 'rejected', label: 'Ikke godkendt' },
	{ value: 'postponed', label: 'Udsat' },
	{ value: 'referred', label: 'Henvist' },
]

export const committeeTypes = [
	{ value: 'standing_committee', label: 'Stående udvalg' },
	{ value: 'finance_committee', label: 'Økonomiudvalg' },
	{ value: 'city_council', label: 'Byråd' },
	{ value: 'special_committee', label: 'Særligt udvalg' },
]

export const auditLabels: Record<string, string> = {
	'committee.created': 'Udvalg oprettet',
	'committee.updated': 'Udvalg ændret',
	'committee.deleted': 'Udvalg slettet',
	'meeting.created': 'Møde oprettet',
	'meeting.updated': 'Møde ændret',
	'meeting.deleted': 'Møde slettet',
	'meeting.status_changed': 'Mødestatus ændret',
	'political_case.created': 'Politisk sag oprettet',
	'agenda_item.created': 'Dagsordenspunkt oprettet',
	'agenda_item.updated': 'Dagsordenspunkt ændret',
	'agenda_item.deleted': 'Dagsordenspunkt slettet',
	'agenda_item.submitted': 'Indsendt til godkendelse',
	'agenda_item.approved': 'Godkendt',
	'agenda_item.rejected': 'Afvist',
	'agenda_item.published': 'Udsendt',
	'agenda_item.reordered': 'Rækkefølge ændret',
	'agenda_item.forwarded': 'Videreført til andet udvalg',
	'agenda.published': 'Dagsorden udsendt',
	'decision.registered': 'Beslutning registreret',
	'decision.changed': 'Beslutning ændret',
	'protocol.generated': 'Protokol genereret',
	'protocol.approved': 'Protokol godkendt',
	'protocol.journalized': 'Journaliseret i OpenCase',
	'protocol.journalization_failed': 'Journalisering fejlede',
	'publication.published': 'Offentliggjort',
	'publication.withdrawn': 'Offentliggørelse trukket tilbage',
}

/**
 * Datetime-local string (local time) <-> Date helpers for forms.
 * @param date Date to serialise (or null)
 */
export function toIso(date: Date | null): string | null {
	return date ? date.toISOString() : null
}
