<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Controller;

use OCA\OpenAgenda\Service\AuthorizationService;
use OCA\OpenAgenda\Service\MeetingService;
use OCA\OpenAgenda\Service\PublicationService;
use OCA\OpenAgenda\Service\ViewBuilder;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class PublicationController extends BaseApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		AuthorizationService $auth,
		LoggerInterface $logger,
		private PublicationService $publications,
		private MeetingService $meetings,
		private ViewBuilder $view,
	) {
		parent::__construct($appName, $request, $auth, $logger);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/meetings/{id}/publications')]
	public function index(string $id): JSONResponse {
		return $this->handle(function () use ($id) {
			$actor = $this->auth->currentActor();
			$meeting = $this->meetings->get($actor, $id);
			return array_map(fn ($p) => $this->view->publication($p), $this->publications->listForMeeting($actor, $meeting));
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/meetings/{id}/publications')]
	public function publish(string $id): JSONResponse {
		return $this->handle(fn () => $this->view->publication($this->publications->publish($this->auth->currentActor(), $id)), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/publications/{id}/withdraw')]
	public function withdraw(string $id): JSONResponse {
		return $this->handle(fn () => $this->view->publication($this->publications->withdraw($this->auth->currentActor(), $id)));
	}
}
