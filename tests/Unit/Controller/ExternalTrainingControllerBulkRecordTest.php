<?php

/**
 * ExternalTrainingController: the bulk recording, the upload limits and the credential short-cuts.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\ExternalTrainingController;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\CallerTenantResolver;
use OCA\Learniq\Service\CredentialSigningService;
use OCA\Learniq\Service\ExternalTrainingImport;
use OCA\Learniq\Service\ExternalTrainingService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Controller\ExternalTrainingController
 */
class ExternalTrainingControllerBulkRecordTest extends TestCase {

	/**
	 * The training service double.
	 *
	 * @var ExternalTrainingService&MockObject
	 */
	private ExternalTrainingService $training;

	/**
	 * The import double.
	 *
	 * @var ExternalTrainingImport&MockObject
	 */
	private ExternalTrainingImport $import;

	/**
	 * The tenant resolver double.
	 *
	 * @var CallerTenantResolver&MockObject
	 */
	private CallerTenantResolver $tenant;

	/**
	 * The object service double.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objects;

	/**
	 * The controller with an officer signed in, or nobody.
	 *
	 * @param bool $signedIn Whether a user is in the session.
	 *
	 * @return ExternalTrainingController
	 */
	private function controller(bool $signedIn=true): ExternalTrainingController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('officer-1');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $user : null);

		$this->training = $this->createMock(ExternalTrainingService::class);
		$this->import = $this->createMock(ExternalTrainingImport::class);
		$this->tenant = $this->createMock(CallerTenantResolver::class);
		$this->objects = $this->createMock(ObjectService::class);

		return new ExternalTrainingController(
			request: $this->createMock(IRequest::class),
			userSession: $session,
			actionAuth: $this->createMock(ActionAuthService::class),
			trainingService: $this->training,
			objectService: $this->objects,
			signingService: $this->createMock(CredentialSigningService::class),
			callerTenant: $this->tenant,
			trainingImport: $this->import,
		);
	}//end controller()

	/**
	 * Every door is shut to a request without a session user.
	 *
	 * @return void
	 */
	public function testAnonymousIsUnauthorizedEverywhere(): void {
		$controller = $this->controller(signedIn: false);
		$this->training->expects($this->never())->method('bulkRecord');
		$this->import->expects($this->never())->method('import');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $controller->bulkRecord(['l1'], ['title' => 'BHV'])->getStatus());
		self::assertSame(Http::STATUS_UNAUTHORIZED, $controller->import([['learner' => 'l1']], false)->getStatus());
		self::assertSame(Http::STATUS_UNAUTHORIZED, $controller->issueCredential('r1')->getStatus());
		self::assertSame(Http::STATUS_UNAUTHORIZED, $controller->learnerCoverage('l1', 'bhv')->getStatus());
	}//end testAnonymousIsUnauthorizedEverywhere()

	/**
	 * A bulk record needs learners; the submitter is the session user, whatever the body says.
	 *
	 * @return void
	 */
	public function testBulkRecordStampsTheSubmitter(): void {
		$controller = $this->controller();
		self::assertSame(Http::STATUS_BAD_REQUEST, $controller->bulkRecord([], ['title' => 'BHV'])->getStatus());

		$this->training->expects($this->once())->method('bulkRecord')
			->with(['l1', 'l2', 'l1'], ['title' => 'BHV', 'submittedBy' => 'officer-1'])
			->willReturn('batch-9');

		$response = $controller->bulkRecord(['l1', 'l2', 'l1'], ['title' => 'BHV', 'submittedBy' => 'someone-else']);

		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
		self::assertSame(['batchId' => 'batch-9', 'count' => 2], $response->getData());
	}//end testBulkRecordStampsTheSubmitter()

	/**
	 * A batch the service could not create is a 400 naming the required fields.
	 *
	 * @return void
	 */
	public function testAnIncompleteBatchIsRefused(): void {
		$controller = $this->controller();
		$this->training->method('bulkRecord')->willReturn('');

		$response = $controller->bulkRecord(['l1'], ['title' => 'BHV']);

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertStringContainsString('Missing required training fields', $response->getData()['error']);
	}//end testAnIncompleteBatchIsRefused()

	/**
	 * An over-long file is refused before the import runs.
	 *
	 * @return void
	 */
	public function testAFileOverTheRowLimitIsRefused(): void {
		$controller = $this->controller();
		$this->import->expects($this->never())->method('import');

		$rows = array_fill(0, ExternalTrainingImport::MAX_ROWS + 1, ['learner' => 'l1']);
		$response = $controller->import($rows, true);

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertStringContainsString('at most ' . ExternalTrainingImport::MAX_ROWS . ' rows', $response->getData()['error']);
	}//end testAFileOverTheRowLimitIsRefused()

	/**
	 * The import runs for the caller's tenant, as the caller, in the asked mode.
	 *
	 * @return void
	 */
	public function testTheImportRunsForTheCallersTenant(): void {
		$controller = $this->controller();
		$rows = [['learner' => 'l1'], ['learner' => 'l2']];
		$this->tenant->method('resolve')->willReturn('tenant-a');
		$this->import->expects($this->once())->method('import')
			->with($rows, 'tenant-a', 'officer-1', ['dryRun' => false])
			->willReturn(['ready' => 2, 'rows' => []]);

		$response = $controller->import($rows, false);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['ready' => 2, 'rows' => []], $response->getData());
	}//end testTheImportRunsForTheCallersTenant()

	/**
	 * Only a verified record gets a credential, and only once.
	 *
	 * @return void
	 */
	public function testIssueCredentialNeedsAVerifiedRecordAndIsIdempotent(): void {
		$controller = $this->controller();
		$this->tenant->method('findOwned')->willReturnCallback(
			static fn ($user, string $id) => match ($id) {
				'draft' => ['id' => 'draft', 'lifecycle' => 'submitted'],
				'done' => ['id' => 'done', 'lifecycle' => 'verified', 'credentialId' => 'cred-7'],
				default => null,
			}
		);
		$this->training->expects($this->never())->method('buildManualCredentialPayload');
		$this->objects->expects($this->never())->method('saveObject');

		$draft = $controller->issueCredential('draft');
		self::assertSame(Http::STATUS_CONFLICT, $draft->getStatus());
		self::assertStringContainsString('verified record', $draft->getData()['error']);

		$done = $controller->issueCredential('done');
		self::assertSame(Http::STATUS_OK, $done->getStatus());
		self::assertSame(['credentialId' => 'cred-7'], $done->getData());
	}//end testIssueCredentialNeedsAVerifiedRecordAndIsIdempotent()
}//end class
