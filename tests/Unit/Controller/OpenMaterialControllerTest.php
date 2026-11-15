<?php

/**
 * OpenMaterialController unit tests.
 *
 * The routes refuse before they look anything up: no session, the source
 * switched off, a user without the import action. A refused add answers 422
 * with the reason, a source that does not answer 503.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/open-learning-material/spec.md#requirement-licence-rules-are-enforced-on-the-server
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\OpenMaterialController;
use OCA\Learniq\Exception\IntegriqUnavailableException;
use OCA\Learniq\Exception\MaterialImportRefusedException;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\CallerActions;
use OCA\Learniq\Service\OpenMaterial\OpenMaterialImportService;
use OCA\Learniq\Service\OpenMaterial\OpenMaterialSearch;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class OpenMaterialControllerTest extends TestCase {

	/**
	 * Request parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = ['sourceId' => 'urn:wikiwijsmaken:194413', 'lessonId' => 'les-7'];

	private OpenMaterialSearch&MockObject $search;

	private OpenMaterialImportService&MockObject $imports;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->search  = $this->createMock(OpenMaterialSearch::class);
		$this->imports = $this->createMock(OpenMaterialImportService::class);
	}//end setUp()

	/**
	 * The controller.
	 *
	 * @param array{loggedIn?: bool, allowed?: bool, enabled?: bool} $state Who calls and how learniq is set.
	 *
	 * @return OpenMaterialController The controller.
	 */
	private function controller(array $state = []): OpenMaterialController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => ($this->params[$key] ?? $default));
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(($state['loggedIn'] ?? true) ? $this->createMock(IUser::class) : null);
		$auth = $this->createMock(ActionAuthService::class);
		if (($state['allowed'] ?? true) === false) {
			$auth->method('requireAction')->willThrowException(new OCSForbiddenException('no'));
		}

		$this->search->method('panel')->willReturn(['enabled' => ($state['enabled'] ?? true)]);

		return new OpenMaterialController($request, new CallerActions(userSession: $session, actionAuth: $auth), $this->search, $this->imports);
	}//end controller()

	public function testAnAddReturnsTheNewMaterial(): void {
		$this->imports->expects($this->once())->method('add')
			->with($this->anything(), ['sourceId' => 'urn:wikiwijsmaken:194413', 'lessonId' => 'les-7', 'courseId' => '', 'mode' => ''])
			->willReturn(['id' => 'm-1', 'kind' => 'link']);

		$response = $this->controller()->add();

		$this->assertSame(expected: Http::STATUS_CREATED, actual: $response->getStatus());
		$this->assertSame(expected: 'm-1', actual: $response->getData()['id']);
	}//end testAnAddReturnsTheNewMaterial()

	public function testARefusedAddAnswers422WithTheReason(): void {
		$this->imports->method('add')->willThrowException(new MaterialImportRefusedException(reasonCode: 'no-derivatives', reason: 'The licence forbids changes (ND).'));

		$response = $this->controller()->add();

		$this->assertSame(expected: Http::STATUS_UNPROCESSABLE_ENTITY, actual: $response->getStatus());
		$this->assertSame(expected: ['code' => 'no-derivatives', 'reason' => 'The licence forbids changes (ND).'], actual: $response->getData());
	}//end testARefusedAddAnswers422WithTheReason()

	public function testTheAddIsRefusedBeforeAnyLookup(): void {
		$this->imports->expects($this->never())->method('add');

		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $this->controller(['loggedIn' => false])->add()->getStatus());
		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $this->controller(['allowed' => false])->add()->getStatus());
	}//end testTheAddIsRefusedBeforeAnyLookup()

	public function testASwitchedOffSourceIsNotSearched(): void {
		$this->search->expects($this->never())->method('search');

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $this->controller(['enabled' => false])->search()->getStatus());
	}//end testASwitchedOffSourceIsNotSearched()

	public function testASearchReadsTheUntickedFiltersAndTheCountsSwitch(): void {
		$this->params = ['query' => 'betoog', 'excludeLicenses' => ['CC-BY-3.0'], 'excludeFormats' => ['application/pdf'], 'facets' => 'false'];
		$sent         = null;
		$this->search->method('search')->willReturnCallback(
			static function (array $request) use (&$sent): array {
				$sent = $request;
				return ['total' => 0];
			}
		);

		$this->controller()->search();

		$this->assertSame(expected: ['CC-BY-3.0'], actual: $sent['excludeLicenses']);
		$this->assertSame(expected: ['application/pdf'], actual: $sent['excludeFormats']);
		$this->assertFalse(condition: $sent['facets']);
	}//end testASearchReadsTheUntickedFiltersAndTheCountsSwitch()

	public function testASearchAsksForCountsUnlessToldNot(): void {
		$this->params = ['query' => 'betoog'];
		$sent         = null;
		$this->search->method('search')->willReturnCallback(
			static function (array $request) use (&$sent): array {
				$sent = $request;
				return ['total' => 0];
			}
		);

		$this->controller()->search();

		$this->assertTrue(condition: $sent['facets']);
	}//end testASearchAsksForCountsUnlessToldNot()

	public function testASourceThatDoesNotAnswerIs503(): void {
		$this->search->method('search')->willThrowException(new IntegriqUnavailableException('Edurep did not answer.'));

		$this->assertSame(expected: Http::STATUS_SERVICE_UNAVAILABLE, actual: $this->controller()->search()->getStatus());
	}//end testASourceThatDoesNotAnswerIs503()

	public function testARecordThatIsGoneIs404(): void {
		$this->search->method('record')->willReturn(null);

		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $this->controller()->record()->getStatus());
	}//end testARecordThatIsGoneIs404()

	public function testThePanelNeedsASession(): void {
		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $this->controller(['loggedIn' => false])->panel()->getStatus());
		$this->assertSame(expected: Http::STATUS_OK, actual: $this->controller()->panel()->getStatus());
	}//end testThePanelNeedsASession()
}//end class
