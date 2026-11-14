<?php

/**
 * Unit tests for CredentialController: who downloads a certificate's PDF.
 *
 * @category  Tests
 * @package   OCA\Learniq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/learniq
 *
 * @spec openspec/changes/credentials-certificate-pdf/specs/certification/spec.md#requirement-only-the-holder-and-the-certificates-managers-download-the-pdf
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\CredentialController;
use OCA\Learniq\Kernel\Contracts\CertificatePdfFileInterface;
use OCA\Learniq\Service\CredentialLearner;
use OCA\Learniq\Service\LearnerRefResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The holder, hr and compliance officers receive the PDF; another learner,
 * a teacher and an unrendered certificate do not.
 */
class CredentialControllerTest extends TestCase {

	private const TOM_PROFILE = '6f1c2a3b-4d5e-4f60-8a7b-9c0d1e2f3a4b';

	/**
	 * The document references read, in order.
	 *
	 * @var array<int, string>
	 */
	private array $reads = [];

	/**
	 * The controller as the given user, in the given groups.
	 *
	 * @param string|null        $userId The signed-in user, or null for none.
	 * @param array<int, string> $groups The user's groups.
	 *
	 * @return CredentialController
	 */
	private function controller(?string $userId, array $groups = []): CredentialController {
		$credentials = [
			'cred-1' => ['id' => 'cred-1', 'learnerId' => self::TOM_PROFILE, 'learnerUserId' => 'tom', 'courseId' => 'c-1', 'issuedAt' => '2026-11-01T10:00:00+01:00', 'lifecycle' => 'issued', 'pdfRenderStatus' => 'rendered', 'pdfDocumentRef' => '4711'],
			'cred-failed' => ['id' => 'cred-failed', 'learnerId' => self::TOM_PROFILE, 'learnerUserId' => 'tom', 'courseId' => 'c-1', 'lifecycle' => 'issued', 'pdfRenderStatus' => 'failed', 'pdfDocumentRef' => '4711', 'pdfRenderError' => 'filinq render error: down'],
			'cred-gone' => ['id' => 'cred-gone', 'learnerId' => self::TOM_PROFILE, 'learnerUserId' => 'tom', 'courseId' => 'c-1', 'lifecycle' => 'issued', 'pdfRenderStatus' => 'rendered', 'pdfDocumentRef' => '9999'],
		];
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			static function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null) use ($credentials): ObjectEntity {
				if ($schema === 'course' && $id === 'c-1') {
					return OrEntityFactory::make(['id' => 'c-1', 'code' => 'VCA basis'], 'course');
				}

				if ($schema === 'learner-profile' && $id === self::TOM_PROFILE) {
					return OrEntityFactory::make(['id' => self::TOM_PROFILE, 'ncUserId' => 'tom', 'lifecycle' => 'active'], 'learner-profile');
				}

				if ($schema === 'credential' && isset($credentials[(string)$id]) === true) {
					return OrEntityFactory::make($credentials[(string)$id], 'credential');
				}

				throw new DoesNotExistException('gone');
			}
		);

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($userId !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($userId);
		}

		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')->willReturnCallback(static fn (string $uid, string $group): bool => in_array($group, $groups, true));
		$groupManager->method('isAdmin')->willReturn(false);

		$files = $this->createMock(CertificatePdfFileInterface::class);
		$files->method('read')->willReturnCallback(
			function (string $documentRef): ?string {
				$this->reads[] = $documentRef;
				return $documentRef === '4711' ? '%PDF-1.7 certificaat' : null;
			}
		);

		return new CredentialController(
			request: $this->createMock(IRequest::class),
			userSession: $session,
			groupManager: $groupManager,
			objects: $objects,
			learners: new CredentialLearner(profiles: new LearnerRefResolver(objectService: $objects)),
			pdfFiles: $files
		);
	}//end controller()

	/**
	 * The response's headers.
	 *
	 * @param Response $response The response.
	 *
	 * @return array<string, string>
	 */
	private function headers(Response $response): array {
		return (new ReflectionProperty(Response::class, 'headers'))->getValue($response);
	}//end headers()

	/**
	 * The holder receives the PDF, named after the course and the issue date.
	 *
	 * @return void
	 */
	public function testTheHolderDownloads(): void {
		$response = $this->controller(userId: 'tom')->pdf(id: 'cred-1');

		self::assertInstanceOf(DataDownloadResponse::class, $response);
		self::assertSame('%PDF-1.7 certificaat', $response->render());
		self::assertSame('application/pdf', $this->headers($response)['Content-Type']);
		self::assertStringContainsString('certificaat-VCA-basis-2026-11-01.pdf', $this->headers($response)['Content-Disposition']);
		self::assertSame(['4711'], $this->reads);
	}//end testTheHolderDownloads()

	/**
	 * hr and compliance officers download any certificate's PDF.
	 *
	 * @return void
	 */
	public function testHrAndComplianceOfficersDownload(): void {
		self::assertInstanceOf(DataDownloadResponse::class, $this->controller(userId: 'hr-1', groups: ['hr'])->pdf(id: 'cred-1'));
		self::assertInstanceOf(DataDownloadResponse::class, $this->controller(userId: 'co-1', groups: ['compliance-officers'])->pdf(id: 'cred-1'));
	}//end testHrAndComplianceOfficersDownload()

	/**
	 * Another learner and a teacher get the 404 a missing certificate gets,
	 * and the file is never read for them.
	 *
	 * @return void
	 */
	public function testAnotherLearnerAndATeacherAreRefused(): void {
		foreach ([['sanne', []], ['jan', ['instructors']]] as [$userId, $groups]) {
			$response = $this->controller(userId: $userId, groups: $groups)->pdf(id: 'cred-1');

			self::assertInstanceOf(JSONResponse::class, $response);
			self::assertSame(404, $response->getStatus());
			self::assertSame(['error' => 'not_found'], $response->getData());
		}

		self::assertSame([], $this->reads);
		self::assertSame(['error' => 'not_found'], $this->controller(userId: 'sanne')->pdf(id: 'cred-unknown')->getData());
	}//end testAnotherLearnerAndATeacherAreRefused()

	/**
	 * Without a signed-in user the request is unauthorized.
	 *
	 * @return void
	 */
	public function testNoUserIsUnauthorized(): void {
		$response = $this->controller(userId: null)->pdf(id: 'cred-1');

		self::assertSame(401, $response->getStatus());
		self::assertSame([], $this->reads);
	}//end testNoUserIsUnauthorized()

	/**
	 * A certificate whose render failed offers no PDF, even with a stored
	 * reference; a reference that names no PDF any more is a 404 too.
	 *
	 * @return void
	 */
	public function testOnlyARenderedPdfIsServed(): void {
		$failed = $this->controller(userId: 'tom')->pdf(id: 'cred-failed');
		self::assertSame(404, $failed->getStatus());
		self::assertSame(['error' => 'pdf_not_rendered'], $failed->getData());
		self::assertSame([], $this->reads);

		$gone = $this->controller(userId: 'tom')->pdf(id: 'cred-gone');
		self::assertSame(404, $gone->getStatus());
		self::assertSame(['error' => 'pdf_missing'], $gone->getData());
	}//end testOnlyARenderedPdfIsServed()
}//end class
