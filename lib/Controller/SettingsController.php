<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Controller;

use OCA\OpenAgenda\Service\AuthorizationService;
use OCA\OpenAgenda\Service\SettingsService;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Demo configuration (OpenAgenda administrators only – checked by the AccessPolicy).
 */
class SettingsController extends BaseApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		AuthorizationService $auth,
		LoggerInterface $logger,
		private SettingsService $settings,
	) {
		parent::__construct($appName, $request, $auth, $logger);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/settings')]
	public function show(): JSONResponse {
		return $this->handle(function () {
			$this->auth->require($this->auth->policy()->canConfigure($this->auth->currentActor()), 'Kun administratorer kan se indstillinger.');
			return $this->settings->getAll();
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/settings')]
	public function update(): JSONResponse {
		return $this->handle(function () {
			$this->auth->require($this->auth->policy()->canConfigure($this->auth->currentActor()), 'Kun administratorer kan ændre indstillinger.');
			return $this->settings->update($this->body());
		});
	}
}
