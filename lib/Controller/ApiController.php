<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Controller;

use OCA\OpenAgenda\AppInfo\Application;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;

/**
 * OCS discovery endpoint from the original skeleton. Kept for compatibility;
 * it advertises the internal and public REST API base paths.
 *
 * @psalm-suppress UnusedClass
 */
class ApiController extends OCSController {
	/**
	 * API discovery information
	 *
	 * @return DataResponse<Http::STATUS_OK, array{app: string, apiVersion: string, internalApi: string, publicApi: string}, array{}>
	 *
	 * 200: Data returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api')]
	public function index(): DataResponse {
		return new DataResponse([
			'app' => Application::APP_ID,
			'apiVersion' => 'v1',
			'internalApi' => '/index.php/apps/' . Application::APP_ID . '/api/v1',
			'publicApi' => '/index.php/apps/' . Application::APP_ID . '/api/public/v1',
		]);
	}
}
