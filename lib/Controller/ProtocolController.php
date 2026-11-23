<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Controller;

use OCA\OpenAgenda\Domain\Exception\OpenAgendaException;
use OCA\OpenAgenda\Service\AuthorizationService;
use OCA\OpenAgenda\Service\JournalizationService;
use OCA\OpenAgenda\Service\MeetingService;
use OCA\OpenAgenda\Service\ProtocolService;
use OCA\OpenAgenda\Service\ViewBuilder;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class ProtocolController extends BaseApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		AuthorizationService $auth,
		LoggerInterface $logger,
		private ProtocolService $protocols,
		private JournalizationService $journalization,
		private MeetingService $meetings,
		private ViewBuilder $view,
	) {
		parent::__construct($appName, $request, $auth, $logger);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/meetings/{id}/protocols')]
	public function index(string $id): JSONResponse {
		return $this->handle(function () use ($id) {
			$actor = $this->auth->currentActor();
			$meeting = $this->meetings->get($actor, $id);
			return array_map(fn ($p) => $this->view->protocol($p), $this->protocols->listForMeeting($actor, $meeting));
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/meetings/{id}/protocols')]
	public function generate(string $id): JSONResponse {
		return $this->handle(function () use ($id) {
			$protocol = $this->protocols->generate($this->auth->currentActor(), $id);
			return $this->view->protocol($protocol, true, $this->protocols->verify($protocol));
		}, Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/protocols/{id}')]
	public function show(string $id): JSONResponse {
		return $this->handle(function () use ($id) {
			$protocol = $this->protocols->get($this->auth->currentActor(), $id);
			return $this->view->protocol($protocol, true, $this->protocols->verify($protocol));
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/protocols/{id}/approve')]
	public function approve(string $id): JSONResponse {
		return $this->handle(function () use ($id) {
			$protocol = $this->protocols->approve($this->auth->currentActor(), $id);
			return $this->view->protocol($protocol, true, $this->protocols->verify($protocol));
		});
	}

	/** Standalone HTML document (for printing/download). Opened as a normal browser navigation. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/protocols/{id}/html')]
	public function html(string $id): Response {
		try {
			$protocol = $this->protocols->get($this->auth->currentActor(), $id);
		} catch (OpenAgendaException $e) {
			return new DataDisplayResponse(htmlspecialchars($e->getMessage()), Http::STATUS_FORBIDDEN, ['Content-Type' => 'text/plain; charset=utf-8']);
		}
		$response = new DataDisplayResponse($protocol->getHtml(), Http::STATUS_OK, ['Content-Type' => 'text/html; charset=utf-8']);
		$csp = new ContentSecurityPolicy();
		$csp->allowInlineStyle(true);
		$response->setContentSecurityPolicy($csp);
		return $response;
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/protocols/{id}/journalize')]
	public function journalize(string $id): JSONResponse {
		return $this->handle(function () use ($id) {
			$result = $this->journalization->journalize($this->auth->currentActor(), $id);
			return [
				'created' => $result['created'],
				'skipped' => $result['skipped'],
				'failed' => $result['failed'],
				'journalizations' => array_map(fn ($j) => $this->view->journalization($j), $result['journalizations']),
			];
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/protocols/{id}/journalizations')]
	public function journalizations(string $id): JSONResponse {
		return $this->handle(fn () => array_map(
			fn ($j) => $this->view->journalization($j),
			$this->journalization->listForProtocol($this->auth->currentActor(), $id),
		));
	}
}
