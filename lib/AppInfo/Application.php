<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\AppInfo;

use OCA\OpenAgenda\OpenCase\OpenCaseAdapterFactory;
use OCA\OpenAgenda\OpenCase\OpenCaseAdapterInterface;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;

class Application extends App implements IBootstrap {
	public const APP_ID = 'openagenda';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		// The OpenCase integration is a port. The adapter (mock or the OpenCase app
		// in this instance) is chosen by app config "opencase_adapter"; domain and
		// application code only depend on OpenCaseAdapterInterface.
		$context->registerService(
			OpenCaseAdapterInterface::class,
			static fn (ContainerInterface $c): OpenCaseAdapterInterface => $c->get(OpenCaseAdapterFactory::class)->create(),
		);
	}

	public function boot(IBootContext $context): void {
	}
}
