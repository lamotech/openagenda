<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Tests\Unit;

use OCA\OpenAgenda\AppInfo\Application;
use OCA\OpenAgenda\Controller\ApiController;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

final class ApiControllerTest extends TestCase {
	public function testIndexAdvertisesApiVersion(): void {
		$request = $this->createMock(IRequest::class);
		$controller = new ApiController(Application::APP_ID, $request);
		$data = $controller->index()->getData();

		$this->assertSame('openagenda', $data['app']);
		$this->assertSame('v1', $data['apiVersion']);
		$this->assertStringEndsWith('/api/public/v1', $data['publicApi']);
	}
}
