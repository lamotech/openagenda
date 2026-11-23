<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Service\Demo;

/**
 * Fictitious demo data. Everything here is invented and labelled as such.
 * Used only by the demo seeder – never by production logic.
 */
final class DemoFixtures {
	public const MUNICIPALITY = 'Demo Kommune (fiktiv)';

	/** @return list<array{name: string, shortName: string, type: string, description: string}> */
	public static function committees(): array {
		return [
			['name' => 'Teknik- og Miljøudvalget', 'shortName' => 'TMU', 'type' => 'standing_committee', 'description' => 'Fiktivt udvalg til demo. Behandler sager om planlægning, natur, miljø, veje og trafik.'],
			['name' => 'Økonomiudvalget', 'shortName' => 'ØU', 'type' => 'finance_committee', 'description' => 'Fiktivt udvalg til demo. Behandler budget, økonomi og tværgående sager.'],
			['name' => 'Byrådet', 'shortName' => 'BR', 'type' => 'city_council', 'description' => 'Fiktivt byråd til demo. Træffer endelig beslutning i sager, der kræver byrådets godkendelse.'],
		];
	}

	/**
	 * Meetings relative to "today" (days offset, local hour, minute).
	 *
	 * @return list<array{committee: string, title: string, days: int, hour: int, minute: int, durationHours: int, location: string}>
	 */
	public static function meetings(): array {
		return [
			['committee' => 'Teknik- og Miljøudvalget', 'title' => 'Ordinært møde (demo 1)', 'days' => 7, 'hour' => 16, 'minute' => 0, 'durationHours' => 2, 'location' => 'Mødelokale 2, Demo Rådhus (fiktivt)'],
			['committee' => 'Teknik- og Miljøudvalget', 'title' => 'Ordinært møde (demo 2)', 'days' => 35, 'hour' => 16, 'minute' => 0, 'durationHours' => 2, 'location' => 'Mødelokale 2, Demo Rådhus (fiktivt)'],
			['committee' => 'Økonomiudvalget', 'title' => 'Ordinært møde (demo 1)', 'days' => 14, 'hour' => 15, 'minute' => 0, 'durationHours' => 2, 'location' => 'Borgmesterens mødelokale, Demo Rådhus (fiktivt)'],
			['committee' => 'Byrådet', 'title' => 'Byrådsmøde (demo 1)', 'days' => 21, 'hour' => 17, 'minute' => 0, 'durationHours' => 3, 'location' => 'Byrådssalen, Demo Rådhus (fiktivt)'],
		];
	}

	/**
	 * Simulated OpenCase cases with documents.
	 *
	 * @return list<array{externalId: string, caseNumber: string, title: string, description: string, status: string, unit: string, documents: list<array{externalId: string, title: string, type: string, access: string, versions: int}>}>
	 */
	public static function cases(): array {
		return [
			[
				'externalId' => 'mock-case-0001', 'caseNumber' => 'DEMO-2026-001',
				'title' => 'Forslag til lokalplan for nyt boligområde',
				'description' => 'FIKTIV DEMOSAG. Forslag til lokalplan nr. 2026-12 for et nyt boligområde ved Engvej med ca. 80 boliger, grønne friarealer og stiforbindelser.',
				'status' => 'Under behandling', 'unit' => 'Plan og Byg (fiktiv)',
				'documents' => [
					['externalId' => 'mock-doc-0001', 'title' => 'Forslag til lokalplan 2026-12 (fiktiv)', 'type' => 'Lokalplanforslag', 'access' => 'public', 'versions' => 3],
					['externalId' => 'mock-doc-0002', 'title' => 'Miljøscreening af lokalplanforslag (fiktiv)', 'type' => 'Notat', 'access' => 'public', 'versions' => 1],
					['externalId' => 'mock-doc-0003', 'title' => 'Notat om vejadgang og trafik (fiktiv)', 'type' => 'Notat', 'access' => 'internal', 'versions' => 2],
					['externalId' => 'mock-doc-0004', 'title' => 'Forhøringssvar med personoplysninger (fiktiv)', 'type' => 'Høringssvar', 'access' => 'confidential', 'versions' => 1],
				],
			],
			[
				'externalId' => 'mock-case-0002', 'caseNumber' => 'DEMO-2026-002',
				'title' => 'Budgetopfølgning 3. kvartal 2026',
				'description' => 'FIKTIV DEMOSAG. Kvartalsvis budgetopfølgning for alle udvalg.',
				'status' => 'Under behandling', 'unit' => 'Økonomi (fiktiv)',
				'documents' => [
					['externalId' => 'mock-doc-0005', 'title' => 'Budgetopfølgning Q3 – samlet oversigt (fiktiv)', 'type' => 'Bilag', 'access' => 'public', 'versions' => 2],
				],
			],
			[
				'externalId' => 'mock-case-0003', 'caseNumber' => 'DEMO-2026-003',
				'title' => 'Trafiksikkerhedsplan 2027-2030',
				'description' => 'FIKTIV DEMOSAG. Udkast til ny trafiksikkerhedsplan med fokus på skoleveje.',
				'status' => 'Under behandling', 'unit' => 'Vej og Trafik (fiktiv)',
				'documents' => [
					['externalId' => 'mock-doc-0006', 'title' => 'Udkast til trafiksikkerhedsplan (fiktiv)', 'type' => 'Plan', 'access' => 'public', 'versions' => 1],
					['externalId' => 'mock-doc-0007', 'title' => 'Uheldsstatistik 2022-2026 (fiktiv)', 'type' => 'Bilag', 'access' => 'internal', 'versions' => 1],
				],
			],
			[
				'externalId' => 'mock-case-0004', 'caseNumber' => 'DEMO-2026-004',
				'title' => 'Køb af ejendom, matr.nr. 12a Demo By (fiktiv)',
				'description' => 'FIKTIV DEMOSAG. Fortrolig forhandling om køb af ejendom. Egnet til lukket punkt.',
				'status' => 'Under behandling', 'unit' => 'Ejendomme (fiktiv)',
				'documents' => [
					['externalId' => 'mock-doc-0008', 'title' => 'Vurderingsrapport (fiktiv, fortrolig)', 'type' => 'Rapport', 'access' => 'confidential', 'versions' => 1],
				],
			],
			[
				'externalId' => 'mock-case-0005', 'caseNumber' => 'DEMO-2026-005',
				'title' => 'Renovering af Skolevej Skole',
				'description' => 'FIKTIV DEMOSAG. Anlægsbevilling til renovering af tag og ventilation.',
				'status' => 'Oprettet', 'unit' => 'Ejendomme (fiktiv)',
				'documents' => [],
			],
		];
	}
}
