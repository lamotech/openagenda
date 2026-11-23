<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Controller;

use OCA\OpenAgenda\Service\AuditService;
use OCA\OpenAgenda\Service\AuthorizationService;
use OCA\OpenAgenda\Service\ViewBuilder;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Read-only access to the audit log. There are intentionally no write endpoints.
 */
class AuditController extends BaseApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		AuthorizationService $auth,
		LoggerInterface $logger,
		private AuditService $audit,
		private ViewBuilder $view,
	) {
		parent::__construct($appName, $request, $auth, $logger);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/audit')]
	public function index(?string $objectType = null, ?string $objectId = null, ?string $meetingId = null, int $limit = 100): JSONResponse {
		return $this->handle(function () use ($objectType, $objectId, $meetingId, $limit) {
			$actor = $this->auth->currentActor();
			$this->auth->require($this->auth->policy()->canReadAudit($actor), 'Kun sekretariatet kan se auditloggen.');
			return array_map(fn ($e) => $this->view->auditEvent($e), $this->audit->find($objectType ?: null, $objectId ?: null, $meetingId ?: null, $limit));
		});
	}
}
