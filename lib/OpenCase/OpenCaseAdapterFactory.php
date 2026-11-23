<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\OpenCase;

use OCA\OpenAgenda\AppInfo\Application;
use OCA\OpenAgenda\OpenCase\InProcess\InProcessOpenCaseAdapter;
use OCA\OpenAgenda\OpenCase\Mock\MockOpenCaseAdapter;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Selects the OpenCase adapter from app config "opencase_adapter":
 *  - "mock" (default): simulated OpenCase with demo data
 *  - "opencase": the OpenCase app in this Nextcloud instance (InProcessOpenCaseAdapter)
 *
 * If "opencase" is configured but the app is not enabled, the mock is NOT used
 * silently: an adapter that fails every call is returned so the misconfiguration is visible.
 */
class OpenCaseAdapterFactory {
	public const CONFIG_KEY = 'opencase_adapter';
	public const ADAPTER_MOCK = 'mock';
	public const ADAPTER_OPENCASE = 'opencase';

	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private IAppManager $appManager,
		private LoggerInterface $logger,
	) {
	}

	public function configured(): string {
		$value = $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY, self::ADAPTER_MOCK);
		return $value === self::ADAPTER_OPENCASE ? self::ADAPTER_OPENCASE : self::ADAPTER_MOCK;
	}

	public function isOpenCaseAvailable(): bool {
		return $this->appManager->isEnabledForAnyone('opencase');
	}

	public function openCaseVersion(): ?string {
		return $this->appManager->isInstalled('opencase') ? $this->appManager->getAppVersion('opencase') : null;
	}

	public function create(): OpenCaseAdapterInterface {
		if ($this->configured() !== self::ADAPTER_OPENCASE) {
			return $this->container->get(MockOpenCaseAdapter::class);
		}
		if (!$this->isOpenCaseAvailable()) {
			$this->logger->error('OpenAgenda is configured to use OpenCase, but the opencase app is not enabled.');
			return new UnavailableOpenCaseAdapter('OpenAgenda er sat op til at bruge OpenCase, men OpenCase-appen er ikke aktiveret.');
		}
		$version = (string)$this->openCaseVersion();
		if (!str_starts_with($version, InProcessOpenCaseAdapter::SUPPORTED_VERSION_PREFIX)) {
			$this->logger->warning('OpenAgenda InProcessOpenCaseAdapter is verified against OpenCase 1.3.x; installed version is ' . $version);
		}
		return $this->container->get(InProcessOpenCaseAdapter::class);
	}
}
