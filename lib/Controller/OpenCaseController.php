<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Controller;

use OCA\OpenAgenda\Domain\Exception\NotFoundException;
use OCA\OpenAgenda\OpenCase\OpenCaseAdapterInterface;
use OCA\OpenAgenda\Service\AuthorizationService;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Read-only proxy to the OpenCase adapter. Document access is decided by the adapter.
 */
class OpenCaseController extends BaseApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		AuthorizationService $auth,
		LoggerInterface $logger,
		private OpenCaseAdapterInterface $openCase,
	) {
		parent::__construct($appName, $request, $auth, $logger);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/opencase/cases')]
	public function search(string $q = ''): JSONResponse {
		return $this->handle(function () use ($q) {
			$actor = $this->auth->currentActor();
			$this->auth->require($this->auth->policy()->canSearchOpenCase($actor), 'Du har ikke adgang til at søge i OpenCase.');
			$results = [];
			foreach ($this->openCase->searchCases(mb_substr($q, 0, 200), $actor->userId, 20) as $case) {
				$data = $case->jsonSerialize();
				$data['documents'] = $this->openCase->listDocuments($case->id, $actor->userId);
				$results[] = $data;
			}
			return ['adapter' => $this->openCase->getName(), 'simulated' => $this->openCase->isSimulated(), 'results' => $results];
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/opencase/cases/{caseId}')]
	public function case(string $caseId): JSONResponse {
		return $this->handle(function () use ($caseId) {
			$actor = $this->auth->currentActor();
			$this->auth->requireAppAccess($actor);
			$case = $this->openCase->getCase($caseId, $actor->userId);
			if ($case === null) {
				throw new NotFoundException('Sagen findes ikke i OpenCase.');
			}
			$data = $case->jsonSerialize();
			$data['documents'] = $this->openCase->listDocuments($caseId, $actor->userId);
			return $data;
		});
	}

	/** Document metadata and versions – only if the adapter grants access to the user. */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/opencase/documents/{documentId}')]
	public function document(string $documentId): JSONResponse {
		return $this->handle(function () use ($documentId) {
			$actor = $this->auth->currentActor();
			$this->auth->requireAppAccess($actor);
			$this->auth->require($this->openCase->canAccessDocument($actor->userId, $documentId), 'OpenCase har afvist adgang til dokumentet.');
			$document = $this->openCase->getDocument($documentId, $actor->userId);
			if ($document === null) {
				throw new NotFoundException('Dokumentet findes ikke i OpenCase.');
			}
			$data = $document->jsonSerialize();
			$data['versions'] = $this->openCase->listDocumentVersions($documentId, $actor->userId);
			return $data;
		});
	}
}
