<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Service;

use OCA\OpenAgenda\AppInfo\Application;
use OCA\OpenAgenda\Domain\Exception\ValidationException;
use OCA\OpenAgenda\Domain\Role;
use OCA\OpenAgenda\OpenCase\OpenCaseAdapterFactory;
use OCP\IAppConfig;

/**
 * App configuration: municipality name and the mapping from roles to Nextcloud groups.
 */
class SettingsService {
	public const KEY_MUNICIPALITY = 'municipality_name';
	public const DEFAULT_MUNICIPALITY = 'Demo Kommune (fiktiv)';

	/** @var array<string, string> role => default group id */
	public const DEFAULT_GROUPS = [
		'admin' => 'openagenda_admins',
		'secretary' => 'openagenda_secretaries',
		'caseworker' => 'openagenda_caseworkers',
		'politician' => 'openagenda_politicians',
	];

	public function __construct(
		private IAppConfig $appConfig,
		private OpenCaseAdapterFactory $adapterFactory,
	) {
	}

	public function getMunicipalityName(): string {
		return $this->appConfig->getValueString(Application::APP_ID, self::KEY_MUNICIPALITY, self::DEFAULT_MUNICIPALITY);
	}

	public function getGroupForRole(Role $role): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'group_' . $role->value, self::DEFAULT_GROUPS[$role->value]);
	}

	public function getAll(): array {
		$groups = [];
		foreach (Role::cases() as $role) {
			$groups[$role->value] = $this->getGroupForRole($role);
		}
		return [
			'municipalityName' => $this->getMunicipalityName(),
			'groups' => $groups,
			'openCase' => [
				'adapter' => $this->adapterFactory->configured(),
				'available' => $this->adapterFactory->isOpenCaseAvailable(),
				'version' => $this->adapterFactory->openCaseVersion(),
				'publicInsightLevels' => $this->appConfig->getValueString(Application::APP_ID, 'opencase_public_insight_levels', ''),
				'journalCategoryId' => $this->appConfig->getValueString(Application::APP_ID, 'opencase_journal_category_id', '3'),
				'journalDocumentType' => $this->appConfig->getValueString(Application::APP_ID, 'opencase_journal_document_type', 'Referat'),
			],
		];
	}

	/**
	 * @param array{municipalityName?: mixed, groups?: mixed} $data
	 */
	public function update(array $data): array {
		$errors = [];
		if (array_key_exists('municipalityName', $data)) {
			$name = is_string($data['municipalityName']) ? trim($data['municipalityName']) : '';
			if ($name === '' || mb_strlen($name) > 255) {
				$errors['municipalityName'] = 'Kommunens navn skal udfyldes (maks. 255 tegn).';
			}
		}
		$groups = [];
		if (array_key_exists('groups', $data)) {
			if (!is_array($data['groups'])) {
				$errors['groups'] = 'Ugyldig gruppekonfiguration.';
			} else {
				foreach (Role::cases() as $role) {
					if (!array_key_exists($role->value, $data['groups'])) {
						continue;
					}
					$gid = is_string($data['groups'][$role->value]) ? trim($data['groups'][$role->value]) : '';
					if ($gid === '' || mb_strlen($gid) > 64) {
						$errors['groups.' . $role->value] = 'Gruppenavn skal udfyldes (maks. 64 tegn).';
					}
					$groups[$role->value] = $gid;
				}
			}
		}
		$openCase = [];
		if (array_key_exists('openCase', $data)) {
			$oc = is_array($data['openCase']) ? $data['openCase'] : [];
			if (array_key_exists('adapter', $oc)) {
				if (!in_array($oc['adapter'], [OpenCaseAdapterFactory::ADAPTER_MOCK, OpenCaseAdapterFactory::ADAPTER_OPENCASE], true)) {
					$errors['openCase.adapter'] = 'Ukendt OpenCase-adapter.';
				} elseif ($oc['adapter'] === OpenCaseAdapterFactory::ADAPTER_OPENCASE && !$this->adapterFactory->isOpenCaseAvailable()) {
					$errors['openCase.adapter'] = 'OpenCase-appen er ikke aktiveret på denne server.';
				} else {
					$openCase[OpenCaseAdapterFactory::CONFIG_KEY] = $oc['adapter'];
				}
			}
			if (array_key_exists('publicInsightLevels', $oc)) {
				$levels = trim((string)$oc['publicInsightLevels']);
				if ($levels !== '' && preg_match('/^[1-4](\s*,\s*[1-4])*$/', $levels) !== 1) {
					$errors['openCase.publicInsightLevels'] = 'Angiv indsigtsgrader som kommasepareret liste af tal 1-4 (eller tomt).';
				} else {
					$openCase['opencase_public_insight_levels'] = preg_replace('/\s+/', '', $levels);
				}
			}
			if (array_key_exists('journalCategoryId', $oc)) {
				if (!ctype_digit((string)$oc['journalCategoryId'])) {
					$errors['openCase.journalCategoryId'] = 'Dokumentkategori skal være et tal.';
				} else {
					$openCase['opencase_journal_category_id'] = (string)$oc['journalCategoryId'];
				}
			}
			if (array_key_exists('journalDocumentType', $oc)) {
				$type = trim((string)$oc['journalDocumentType']);
				if ($type === '' || mb_strlen($type) > 64) {
					$errors['openCase.journalDocumentType'] = 'Dokumenttype skal udfyldes (maks. 64 tegn).';
				} else {
					$openCase['opencase_journal_document_type'] = $type;
				}
			}
		}
		if ($errors !== []) {
			throw new ValidationException($errors);
		}
		foreach ($openCase as $key => $value) {
			$this->appConfig->setValueString(Application::APP_ID, $key, $value);
		}
		if (isset($name)) {
			$this->appConfig->setValueString(Application::APP_ID, self::KEY_MUNICIPALITY, $name);
		}
		foreach ($groups as $role => $gid) {
			$this->appConfig->setValueString(Application::APP_ID, 'group_' . $role, $gid);
		}
		return $this->getAll();
	}

	public function setMunicipalityName(string $name): void {
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_MUNICIPALITY, $name);
	}
}
