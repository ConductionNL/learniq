<?php

/**
 * Unit tests for LessonOnboardingController.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#requirement-nothing-is-extracted-until-the-teacher-confirms-one-file-on-the-review-page
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Learniq\Controller\LessonOnboardingController;
use OCA\Learniq\Service\LessonOnboarding\LessonOnboardingImporter;
use OCA\Learniq\Service\LessonOnboarding\OnboardingFolderSetting;
use OCA\Learniq\Service\LessonOnboarding\OnboardingImportException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Session guard, folder validation and import refusals.
 */
class LessonOnboardingControllerTest extends TestCase {

	/** @var IUserSession&MockObject */
	private IUserSession $userSession;

	/** @var OnboardingFolderSetting&MockObject */
	private OnboardingFolderSetting $folderSetting;

	/** @var LessonOnboardingImporter&MockObject */
	private LessonOnboardingImporter $importer;

	private LessonOnboardingController $controller;

	/**
	 * Build the controller with doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->folderSetting = $this->createMock(OnboardingFolderSetting::class);
		$this->importer = $this->createMock(LessonOnboardingImporter::class);
		$this->controller = new LessonOnboardingController(
			request: $this->createMock(IRequest::class),
			userSession: $this->userSession,
			folderSetting: $this->folderSetting,
			importer: $this->importer,
			logger: $this->createMock(LoggerInterface::class),
		);

	}//end setUp()

	/**
	 * Sign in `jdevries`.
	 *
	 * @return void
	 */
	private function signIn(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('jdevries');
		$this->userSession->method('getUser')->willReturn($user);

	}//end signIn()

	/**
	 * Without a session every endpoint answers 401 and touches nothing.
	 *
	 * @return void
	 */
	public function testEveryEndpointNeedsASession(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->folderSetting->expects($this->never())->method($this->anything());
		$this->importer->expects($this->never())->method('import');

		$this->assertSame(401, $this->controller->folder()->getStatus());
		$this->assertSame(401, $this->controller->setFolder(path: '/x')->getStatus());
		$this->assertSame(401, $this->controller->import(id: 'row', courseId: 'course')->getStatus());

	}//end testEveryEndpointNeedsASession()

	/**
	 * The folder is set for the signed-in user only; a file is refused with 400.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#scenario-a-file-is-not-a-folder
	 */
	public function testSetFolderStoresTheIdOfAFolderInTheUsersFiles(): void {
		$this->signIn();
		$this->folderSetting->method('choose')->willReturnCallback(
			static function (string $userId, string $path): array {
				if ($path === '/Breuken.docx') {
					throw new InvalidArgumentException('Choose a folder, not a file.');
				}

				return ['folderId' => 4711, 'path' => $path, 'for' => $userId];
			}
		);

		$ok = $this->controller->setFolder(path: '/Lessen inbox');
		$this->assertSame(200, $ok->getStatus());
		$this->assertSame(['folderId' => 4711, 'path' => '/Lessen inbox', 'for' => 'jdevries'], $ok->getData());

		$refused = $this->controller->setFolder(path: '/Breuken.docx');
		$this->assertSame(400, $refused->getStatus());
		$this->assertSame('Choose a folder, not a file.', $refused->getData()['error']);

	}//end testSetFolderStoresTheIdOfAFolderInTheUsersFiles()

	/**
	 * The folder read answers the signed-in user's setting.
	 *
	 * @return void
	 */
	public function testFolderDescribesTheUsersSetting(): void {
		$this->signIn();
		$this->folderSetting->expects($this->once())->method('describe')->with('jdevries')->willReturn(['folderId' => null, 'path' => null]);

		$this->assertSame(['folderId' => null, 'path' => null], $this->controller->folder()->getData());

	}//end testFolderDescribesTheUsersSetting()

	/**
	 * Import refusals keep their status and reason; no course is a 400.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#scenario-another-teacher-s-row-cannot-be-imported
	 */
	public function testImportRefusalsKeepTheirStatus(): void {
		$this->signIn();
		$this->importer->method('import')->willReturnCallback(
			static function (string $userId, string $rowId, string $courseId): array {
				if ($rowId === 'foreign') {
					throw new OnboardingImportException('This file is not in your list.', 404, 'not-found');
				}

				if ($rowId === 'done') {
					throw new OnboardingImportException('Already imported.', 409, 'not-detected');
				}

				if ($rowId === 'boom') {
					throw new RuntimeException('database gone');
				}

				return ['lessonId' => 'l1', 'for' => $userId, 'course' => $courseId];
			}
		);

		$this->assertSame(400, $this->controller->import(id: 'row', courseId: ' ')->getStatus());
		$this->assertSame(404, $this->controller->import(id: 'foreign', courseId: 'c1')->getStatus());
		$conflict = $this->controller->import(id: 'done', courseId: 'c1');
		$this->assertSame([409, 'not-detected'], [$conflict->getStatus(), $conflict->getData()['reason']]);
		$failed = $this->controller->import(id: 'boom', courseId: 'c1');
		$this->assertSame(500, $failed->getStatus());
		$this->assertStringNotContainsString('database', $failed->getData()['error']);

		$ok = $this->controller->import(id: 'row', courseId: ' c1 ');
		$this->assertSame(['lessonId' => 'l1', 'for' => 'jdevries', 'course' => 'c1'], $ok->getData());

	}//end testImportRefusalsKeepTheirStatus()
}//end class
