<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Controller;

use OCA\OpenAgenda\Domain\Exception\ConflictException;
use OCA\OpenAgenda\Domain\Exception\ForbiddenException;
use OCA\OpenAgenda\Domain\Exception\InvalidTransitionException;
use OCA\OpenAgenda\Domain\Exception\NotFoundException;
use OCA\OpenAgenda\Domain\Exception\ValidationException;
use OCA\OpenAgenda\OpenCase\OpenCaseException;
use OCA\OpenAgenda\Service\AuthorizationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Common error handling for the internal JSON API. Controllers stay thin:
 * they parse the request, call one application service and format the result.
 */
abstract class BaseApiController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		protected AuthorizationService $auth,
		protected LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @param callable(): mixed $fn returns response data
	 */
	protected function handle(callable $fn, int $successStatus = Http::STATUS_OK): JSONResponse {
		try {
			$data = $fn();
			return new JSONResponse($data, $successStatus);
		} catch (ValidationException $e) {
			return new JSONResponse(['error' => $e->getMessage(), 'fields' => $e->getErrors()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (NotFoundException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (ForbiddenException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (InvalidTransitionException|ConflictException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
		} catch (OpenCaseException $e) {
			$this->logger->warning('OpenCase integration error', ['exception' => $e]);
			return new JSONResponse(['error' => 'OpenCase: ' . $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		} catch (\Throwable $e) {
			$this->logger->error('OpenAgenda: unexpected error', ['exception' => $e]);
			return new JSONResponse(['error' => 'Der opstod en uventet fejl. Prøv igen, eller kontakt en administrator.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/** JSON request body as array (Nextcloud merges JSON bodies into params). */
	protected function body(): array {
		$params = $this->request->getParams();
		unset($params['_route'], $params['id']);
		return $params;
	}
}
