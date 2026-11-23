<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Controller;

use OCA\OpenAgenda\Service\AgendaItemService;
use OCA\OpenAgenda\Service\AuthorizationService;
use OCA\OpenAgenda\Service\MeetingService;
use OCA\OpenAgenda\Service\TreatmentService;
use OCA\OpenAgenda\Service\ViewBuilder;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class AgendaItemController extends BaseApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		AuthorizationService $auth,
		LoggerInterface $logger,
		private AgendaItemService $items,
		private TreatmentService $treatments,
		private MeetingService $meetings,
		private ViewBuilder $view,
	) {
		parent::__construct($appName, $request, $auth, $logger);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/items')]
	public function create(): JSONResponse {
		return $this->handle(function () {
			$actor = $this->auth->currentActor();
			return $this->view->item($actor, $this->items->create($actor, $this->body()));
		}, Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/items/{id}')]
	public function show(string $id): JSONResponse {
		return $this->handle(function () use ($id) {
			$actor = $this->auth->currentActor();
			$item = $this->items->get($actor, $id);
			$data = $this->view->item($actor, $item);
			$data['meeting'] = $this->view->meeting($this->meetings->get($actor, $data['meetingId']));
			$data['history'] = $this->treatments->historyForItem($actor, $id);
			return $data;
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/items/{id}')]
	public function update(string $id): JSONResponse {
		return $this->handle(function () use ($id) {
			$actor = $this->auth->currentActor();
			return $this->view->item($actor, $this->items->update($actor, $id, $this->body()));
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/items/{id}')]
	public function destroy(string $id): JSONResponse {
		return $this->handle(function () use ($id) {
			$this->items->delete($this->auth->currentActor(), $id);
			return ['deleted' => true];
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/items/{id}/submit')]
	public function submit(string $id): JSONResponse {
		return $this->handle(function () use ($id) {
			$actor = $this->auth->currentActor();
			return $this->view->item($actor, $this->items->submit($actor, $id));
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/items/{id}/approve')]
	public function approve(string $id): JSONResponse {
		return $this->handle(function () use ($id) {
			$actor = $this->auth->currentActor();
			return $this->view->item($actor, $this->items->approve($actor, $id));
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/items/{id}/reject')]
	public function reject(string $id, ?string $reason = null): JSONResponse {
		return $this->handle(function () use ($id, $reason) {
			$actor = $this->auth->currentActor();
			return $this->view->item($actor, $this->items->reject($actor, $id, $reason));
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/items/{id}/move')]
	public function move(string $id, string $direction = ''): JSONResponse {
		return $this->handle(function () use ($id, $direction) {
			$actor = $this->auth->currentActor();
			$this->items->move($actor, $id, $direction);
			$meetingUuid = $this->view->item($actor, $this->items->get($actor, $id))['meetingId'];
			$meeting = $this->meetings->get($actor, $meetingUuid);
			return $this->view->items($actor, $meeting, $this->items->listForMeeting($actor, $meeting));
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/items/{id}/history')]
	public function history(string $id): JSONResponse {
		return $this->handle(fn () => $this->treatments->historyForItem($this->auth->currentActor(), $id));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/items/{id}/decision')]
	public function decide(string $id): JSONResponse {
		return $this->handle(function () use ($id) {
			$actor = $this->auth->currentActor();
			$this->treatments->registerDecision($actor, $id, $this->body());
			return $this->view->item($actor, $this->items->get($actor, $id));
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/items/{id}/forward')]
	public function forward(string $id, string $meetingId = ''): JSONResponse {
		return $this->handle(function () use ($id, $meetingId) {
			$actor = $this->auth->currentActor();
			return $this->view->item($actor, $this->treatments->forward($actor, $id, $meetingId));
		}, Http::STATUS_CREATED);
	}
}
