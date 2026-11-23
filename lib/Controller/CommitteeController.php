<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Controller;

use OCA\OpenAgenda\Service\AuthorizationService;
use OCA\OpenAgenda\Service\CommitteeService;
use OCA\OpenAgenda\Service\ViewBuilder;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class CommitteeController extends BaseApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		AuthorizationService $auth,
		LoggerInterface $logger,
		private CommitteeService $committees,
		private ViewBuilder $view,
	) {
		parent::__construct($appName, $request, $auth, $logger);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/committees')]
	public function index(bool $includeInactive = true): JSONResponse {
		return $this->handle(fn () => array_map(
			fn ($c) => $this->view->committee($c),
			$this->committees->list($this->auth->currentActor(), $includeInactive),
		));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/committees/{id}')]
	public function show(string $id): JSONResponse {
		return $this->handle(fn () => $this->view->committee($this->committees->get($this->auth->currentActor(), $id)));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/committees')]
	public function create(): JSONResponse {
		return $this->handle(fn () => $this->view->committee($this->committees->create($this->auth->currentActor(), $this->body())), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/committees/{id}')]
	public function update(string $id): JSONResponse {
		return $this->handle(fn () => $this->view->committee($this->committees->update($this->auth->currentActor(), $id, $this->body())));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/committees/{id}')]
	public function destroy(string $id): JSONResponse {
		return $this->handle(function () use ($id) {
			$this->committees->delete($this->auth->currentActor(), $id);
			return ['deleted' => true];
		});
	}
}
