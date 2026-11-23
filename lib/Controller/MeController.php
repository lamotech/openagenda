<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Controller;

use OCA\OpenAgenda\Domain\Role;
use OCA\OpenAgenda\OpenCase\OpenCaseAdapterInterface;
use OCA\OpenAgenda\Service\AuthorizationService;
use OCA\OpenAgenda\Service\SettingsService;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class MeController extends BaseApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		AuthorizationService $auth,
		LoggerInterface $logger,
		private SettingsService $settings,
		private OpenCaseAdapterInterface $openCase,
	) {
		parent::__construct($appName, $request, $auth, $logger);
	}

	/** Current user's server-resolved roles and capabilities (informational for the UI). */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/me')]
	public function show(): JSONResponse {
		return $this->handle(function () {
			$actor = $this->auth->currentActor();
			return [
				'userId' => $actor->userId,
				'roles' => array_map(static fn (Role $r) => ['id' => $r->value, 'label' => $r->label()], $actor->roles()),
				'capabilities' => $this->auth->policy()->globalCapabilities($actor),
				'municipalityName' => $this->settings->getMunicipalityName(),
				'openCaseAdapter' => $this->openCase->getName(),
				'openCaseSimulated' => $this->openCase->isSimulated(),
			];
		});
	}
}
