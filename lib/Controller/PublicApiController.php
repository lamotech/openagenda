<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Controller;

use OCA\OpenAgenda\Domain\Exception\NotFoundException;
use OCA\OpenAgenda\Service\PublicReadService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Separate, read-only public API (no login). It serves only stored publication
 * content (allow-listed fields from approved snapshots/protocols) and has no
 * dependency on the internal services – so it can later move to a standalone portal.
 */
class PublicApiController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private PublicReadService $publicRead,
	) {
		parent::__construct($appName, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/public/v1/meetings')]
	public function meetings(): JSONResponse {
		return new JSONResponse($this->publicRead->listMeetings());
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/public/v1/meetings/{id}')]
	public function meeting(string $id): JSONResponse {
		try {
			return new JSONResponse($this->publicRead->getMeeting($id));
		} catch (NotFoundException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}
	}
}
