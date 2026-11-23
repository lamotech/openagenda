<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Controller;

use OCA\OpenAgenda\Domain\Enum\MeetingStatus;
use OCA\OpenAgenda\Service\AgendaItemService;
use OCA\OpenAgenda\Service\AgendaService;
use OCA\OpenAgenda\Service\AuthorizationService;
use OCA\OpenAgenda\Service\MeetingService;
use OCA\OpenAgenda\Service\ViewBuilder;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class MeetingController extends BaseApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		AuthorizationService $auth,
		LoggerInterface $logger,
		private MeetingService $meetings,
		private AgendaItemService $items,
		private AgendaService $agenda,
		private ViewBuilder $view,
	) {
		parent::__construct($appName, $request, $auth, $logger);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/meetings')]
	public function index(?string $committeeId = null, ?string $status = null, ?string $from = null, ?string $order = null): JSONResponse {
		return $this->handle(fn () => $this->view->meetings($this->meetings->list($this->auth->currentActor(), [
			'committeeId' => $committeeId,
			'status' => $status,
			'from' => $from !== null && $from !== '' ? (int)$from : null,
			'order' => $order === 'desc' ? 'DESC' : 'ASC',
		])));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/meetings/{id}')]
	public function show(string $id): JSONResponse {
		return $this->handle(function () use ($id) {
			$actor = $this->auth->currentActor();
			$policy = $this->auth->policy();
			$meeting = $this->meetings->get($actor, $id);
			$status = MeetingStatus::from($meeting->getStatus());
			$items = $this->items->listForMeeting($actor, $meeting);
			$snapshots = $this->agenda->listSnapshots($actor, $meeting);
			return [
				'meeting' => $this->view->meeting($meeting),
				'items' => $this->view->items($actor, $meeting, $items),
				'snapshots' => array_map(fn ($s) => $this->view->snapshot($s), $snapshots),
				'permissions' => [
					'edit' => $policy->canManageMeetings($actor) && $status->isEditable(),
					'delete' => $policy->canManageMeetings($actor) && $status === MeetingStatus::Planned && $items === [],
					'changeStatus' => $policy->canManageMeetings($actor),
					'createItem' => $policy->canCreateItem($actor, $status),
					'publishAgenda' => $policy->canPublishAgenda($actor) && ($status === MeetingStatus::AgendaPublished || $status->canTransitionTo(MeetingStatus::AgendaPublished)),
					'readProtocol' => $policy->canReadProtocol($actor),
					'manageProtocol' => $policy->canManageProtocol($actor) && ($status === MeetingStatus::InProgress || $status === MeetingStatus::Completed),
					'journalize' => $policy->canJournalize($actor),
					'publishPublicly' => $policy->canPublishPublicly($actor) && $snapshots !== [],
					'readAudit' => $policy->canReadAudit($actor),
				],
			];
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/meetings')]
	public function create(): JSONResponse {
		return $this->handle(fn () => $this->view->meeting($this->meetings->create($this->auth->currentActor(), $this->body())), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/meetings/{id}')]
	public function update(string $id): JSONResponse {
		return $this->handle(fn () => $this->view->meeting($this->meetings->update($this->auth->currentActor(), $id, $this->body())));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/meetings/{id}')]
	public function destroy(string $id): JSONResponse {
		return $this->handle(function () use ($id) {
			$this->meetings->delete($this->auth->currentActor(), $id);
			return ['deleted' => true];
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/meetings/{id}/status')]
	public function changeStatus(string $id, string $status = ''): JSONResponse {
		return $this->handle(fn () => $this->view->meeting($this->meetings->changeStatus($this->auth->currentActor(), $id, $status)));
	}

	/** Approve (optionally) and send out the agenda; creates a new immutable snapshot version. */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/meetings/{id}/publish-agenda')]
	public function publishAgenda(string $id, bool $approveSubmitted = false): JSONResponse {
		return $this->handle(fn () => $this->view->snapshot($this->agenda->publish($this->auth->currentActor(), $id, $approveSubmitted)), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/snapshots/{id}')]
	public function snapshot(string $id): JSONResponse {
		return $this->handle(function () use ($id) {
			$result = $this->agenda->getSnapshot($this->auth->currentActor(), $id);
			return $this->view->snapshot($result['snapshot'], $result['content'], $result['verified']);
		});
	}
}
