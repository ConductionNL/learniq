<?php

/**
 * Learniq CredentialEuropassController unit tests.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/credentials-europass-edci-export/specs/certification/spec.md#requirement-a-learner-downloads-their-certificate-for-europass
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\CredentialEuropassController;
use OCA\Learniq\Service\EuropassIssuer;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Who may download, and the one-time backfill.
 */
class CredentialEuropassControllerTest extends TestCase {

	/**
	 * Stored credentials by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $credentials = [];

	/**
	 * Saves received.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saves = [];

	/**
	 * The controller for one caller.
	 *
	 * @param string             $userId The caller.
	 * @param array<int, string> $groups The caller's groups.
	 *
	 * @return CredentialEuropassController
	 */
	private function controller(string $userId, array $groups = []): CredentialEuropassController {
		$this->credentials = [
			'cred-1' => ['id' => 'cred-1', 'learnerId' => 'p.ganpat', 'courseId' => 'c-1', 'issuedAt' => '2026-06-30T12:00:00+00:00', 'lifecycle' => 'issued', 'edciPayload' => ['id' => 'urn:uuid:cred-1', 'proof' => ['jws' => 'x..y']]],
			'cred-old' => ['id' => 'cred-old', 'learnerId' => 'p.ganpat', 'kind' => 'certificate', 'lifecycle' => 'issued', 'edciPayload' => null],
			'cred-revoked' => ['id' => 'cred-revoked', 'learnerId' => 'p.ganpat', 'lifecycle' => 'revoked', 'edciPayload' => null],
		];
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null): ObjectEntity {
				if ($schema === 'course' && $id === 'c-1') {
					return OrEntityFactory::make(['id' => 'c-1', 'code' => 'MDB 2026'], 'course');
				}

				if ($schema === 'credential' && isset($this->credentials[(string)$id]) === true) {
					return OrEntityFactory::make($this->credentials[(string)$id], 'credential');
				}

				throw new DoesNotExistException('gone');
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null): ObjectEntity {
				$this->saves[] = ['uuid' => $uuid, 'object' => $object];
				return OrEntityFactory::make($object, (string)$schema);
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);
		$groupManager->method('isInGroup')->willReturnCallback(static fn (string $uid, string $group): bool => in_array($group, $groups, true));
		$europass = $this->createMock(EuropassIssuer::class);
		$europass->method('payloadFor')->willReturn(['id' => 'urn:uuid:cred-old', 'proof' => ['jws' => 'a..b']]);

		return new CredentialEuropassController(
			request: $this->createMock(IRequest::class),
			userSession: $session,
			groupManager: $groupManager,
			objects: $objects,
			europass: $europass
		);
	}//end controller()

	/**
	 * The learner downloads their own form as a JSON-LD file named after the
	 * course code and date; HR may too.
	 *
	 * @return void
	 */
	public function testTheLearnerAndStaffDownload(): void {
		$response = $this->controller(userId: 'p.ganpat')->download(id: 'cred-1');

		self::assertInstanceOf(DataDownloadResponse::class, $response);
		$headers = (new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers'))->getValue($response);
		self::assertSame('application/ld+json', $headers['Content-Type']);
		self::assertStringContainsString('europass-MDB-2026-2026-06-30.jsonld', $headers['Content-Disposition']);
		self::assertSame('urn:uuid:cred-1', json_decode($response->render(), true)['id']);
		self::assertInstanceOf(DataDownloadResponse::class, $this->controller(userId: 'hr-1', groups: ['hr'])->download(id: 'cred-1'));
	}//end testTheLearnerAndStaffDownload()

	/**
	 * Another learner, and a credential without a form, get 404.
	 *
	 * @return void
	 */
	public function testAnotherLearnerGetsNotFound(): void {
		self::assertSame(404, $this->controller(userId: 's.jansen')->download(id: 'cred-1')->getStatus());
		self::assertSame(404, $this->controller(userId: 'p.ganpat')->download(id: 'cred-old')->getStatus());
	}//end testAnotherLearnerGetsNotFound()

	/**
	 * HR creates the form once; a learner cannot; a revoked credential and a
	 * second run are refused.
	 *
	 * @return void
	 */
	public function testStaffBackfillOnceAndNeverForARevokedCredential(): void {
		self::assertSame(404, $this->controller(userId: 'p.ganpat')->create(id: 'cred-old')->getStatus());
		self::assertSame([], $this->saves);

		$hr = $this->controller(userId: 'hr-1', groups: ['hr']);
		self::assertSame(200, $hr->create(id: 'cred-old')->getStatus());
		self::assertSame('cred-old', $this->saves[0]['uuid']);
		self::assertSame('urn:uuid:cred-old', $this->saves[0]['object']['edciPayload']['id']);

		self::assertSame(422, $hr->create(id: 'cred-revoked')->getStatus());
		self::assertSame(409, $hr->create(id: 'cred-1')->getStatus());
	}//end testStaffBackfillOnceAndNeverForARevokedCredential()
}//end class
