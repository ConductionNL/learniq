<?php

/**
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#requirement-an-exam-registration-list-closes-at-its-moment-and-names-who-is-incomplete
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\ExamRegistrationController;
use OCA\Learniq\Kernel\Contracts\AdministrationTodayServiceInterface;
use OCA\Learniq\Kernel\Contracts\CertificateIssueServiceInterface;
use OCA\Learniq\Kernel\Contracts\ExamRegistrationServiceInterface;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\CallerActions;
use OCA\Learniq\Service\CourseDay\CourseDayException;
use OCA\Learniq\Service\ExamRegistration\ExamRegistrationServices;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The administration's exam registration endpoints.
 *
 * @covers \OCA\Learniq\Controller\ExamRegistrationController
 * @uses   \OCA\Learniq\Controller\CallerActionCheck
 * @uses   \OCA\Learniq\Service\CallerActions
 * @uses   \OCA\Learniq\Service\CourseDay\CourseDayException
 */
class ExamRegistrationControllerTest extends TestCase {

	private ExamRegistrationServiceInterface&MockObject $lists;

	private CertificateIssueServiceInterface&MockObject $certificates;

	private AdministrationTodayServiceInterface&MockObject $today;

	private function controller(bool $allowed=true, array $params=[]): ExamRegistrationController {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('rob');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$auth = $this->createMock(originalClassName: ActionAuthService::class);
		$call = $auth->method('requireAction')->with($this->anything(), ExamRegistrationController::ACTION);
		if ($allowed === false) {
			$call->willThrowException(new OCSForbiddenException('Not allowed.'));
		}

		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default=null): mixed => ($params[$key] ?? $default));

		$this->lists        = $this->createMock(originalClassName: ExamRegistrationServiceInterface::class);
		$this->certificates = $this->createMock(originalClassName: CertificateIssueServiceInterface::class);
		$this->today        = $this->createMock(originalClassName: AdministrationTodayServiceInterface::class);

		return new ExamRegistrationController(
			request: $request,
			caller: new CallerActions(userSession: $session, actionAuth: $auth),
			services: new ExamRegistrationServices(lists: $this->lists, certificates: $this->certificates, today: $this->today),
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end controller()

	public function testTheAdministrationOpensAListWithTheBodysFields(): void {
		$controller = $this->controller(params: ['requiredFields' => ['birthDate', 'fullName']]);
		$this->lists->expects($this->once())->method('open')
			->with(
				'c-8',
				[
					'bodyName' => 'Examenbureau',
					'closesAt' => '2026-10-07T12:00:00+02:00',
					'examDate' => null,
					'requiredFields' => ['birthDate', 'fullName']
				]
			)
			->willReturn(['id' => 'l-8', 'lifecycle' => 'open']);

		$response = $controller->open(cohortId: 'c-8', bodyName: 'Examenbureau', closesAt: '2026-10-07T12:00:00+02:00');

		self::assertSame(expected: Http::STATUS_CREATED, actual: $response->getStatus());
		self::assertSame(expected: ['id' => 'l-8', 'lifecycle' => 'open'], actual: $response->getData());
	}//end testTheAdministrationOpensAListWithTheBodysFields()

	public function testCloseSendResultAndIssueActAsTheSignedInUser(): void {
		$controller = $this->controller();
		$this->lists->expects($this->once())->method('close')->with('l-8', 'rob')->willReturn(['lifecycle' => 'closed']);
		$this->lists->expects($this->once())->method('markSent')->with('l-8', 'rob')->willReturn(['lifecycle' => 'sent']);
		$this->lists->expects($this->once())->method('recordResult')->with('l-8', 'e-1', 'pass', 'rob')->willReturn(['lifecycle' => 'sent']);
		$this->certificates->expects($this->once())->method('issue')->with('c-8', 'rob')->willReturn(['summary' => '10 certificaten uitgegeven.']);

		self::assertSame(expected: 'closed', actual: $controller->close(id: 'l-8')->getData()['lifecycle']);
		self::assertSame(expected: 'sent', actual: $controller->markSent(id: 'l-8')->getData()['lifecycle']);
		self::assertSame(expected: Http::STATUS_OK, actual: $controller->result(id: 'l-8', enrolmentId: 'e-1', result: 'pass')->getStatus());
		self::assertSame(expected: '10 certificaten uitgegeven.', actual: $controller->issueCertificates(id: 'c-8')->getData()['summary']);
	}//end testCloseSendResultAndIssueActAsTheSignedInUser()

	public function testAClosedListDownloadsAsCsvAndAnOpenOneIsRefused(): void {
		$controller = $this->controller();
		$this->lists->method('export')->willReturnCallback(
			static function (string $id): array {
				if ($id === 'open') {
					throw new CourseDayException(message: 'Only a closed list can be exported.', status: 409);
				}

				return ['filename' => 'aanmelding.csv', 'csv' => "Naam\r\nTom\r\n"];
			}
		);

		$download = $controller->export(id: 'l-8');
		self::assertInstanceOf(expected: DataDownloadResponse::class, actual: $download);
		self::assertSame(expected: "Naam\r\nTom\r\n", actual: $download->render());
		$headers = (new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers'))->getValue($download);
		self::assertStringContainsString(needle: 'aanmelding.csv', haystack: $headers['Content-Disposition']);
		self::assertSame(expected: 'text/csv; charset=utf-8', actual: $headers['Content-Type']);

		$refused = $controller->export(id: 'open');
		self::assertSame(expected: Http::STATUS_CONFLICT, actual: $refused->getStatus());
		self::assertSame(expected: 'Only a closed list can be exported.', actual: $refused->getData()['error']);
	}//end testAClosedListDownloadsAsCsvAndAnOpenOneIsRefused()

	public function testTodayAndTheExpiringList(): void {
		$controller = $this->controller();
		$this->today->expects($this->once())->method('today')->willReturn(['today' => '2026-10-05']);
		$this->today->expects($this->once())->method('expiring')->with('2027-01-01')->willReturn([['credentialId' => 'k-1']]);

		self::assertSame(expected: ['today' => '2026-10-05'], actual: $controller->today()->getData());
		self::assertSame(
			expected: ['before' => '2027-01-01', 'rows' => [['credentialId' => 'k-1']]],
			actual: $controller->expiring(before: '2027-01-01')->getData()
		);
	}//end testTodayAndTheExpiringList()

	public function testWithoutTheActionNothingIsReadOrWritten(): void {
		$controller = $this->controller(allowed: false);
		$this->lists->expects($this->never())->method($this->anything());
		$this->certificates->expects($this->never())->method($this->anything());
		$this->today->expects($this->never())->method($this->anything());

		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $controller->open(cohortId: 'c-8', bodyName: 'X', closesAt: 'x')->getStatus());
		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $controller->show(id: 'l-8')->getStatus());
		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $controller->export(id: 'l-8')->getStatus());
		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $controller->issueCertificates(id: 'c-8')->getStatus());
		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $controller->today()->getStatus());
	}//end testWithoutTheActionNothingIsReadOrWritten()

	public function testAFailureIsUnavailableWithoutItsCause(): void {
		$controller = $this->controller();
		$this->lists->method('show')->willThrowException(new RuntimeException('database gone'));

		$failed = $controller->show(id: 'l-8');

		self::assertSame(expected: Http::STATUS_SERVICE_UNAVAILABLE, actual: $failed->getStatus());
		self::assertStringNotContainsString(needle: 'database', haystack: $failed->getData()['error']);
	}//end testAFailureIsUnavailableWithoutItsCause()
}//end class
