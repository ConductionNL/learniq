<?php

/**
 * Learniq PeerReviewWorkController unit tests.
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
 * @spec openspec/specs/assignments/spec.md#requirement-a-reviewer-reads-the-work-through-a-server-side-projection
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\PeerReviewWorkController;
use OCA\Learniq\Service\PeerReviewWorkProjection;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\File;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Tests for PeerReviewWorkController::show() and ::file().
 */
class PeerReviewWorkControllerTest extends TestCase {

	/**
	 * Build the controller for one caller and one projection verdict.
	 *
	 * @param string|null $uid The caller, null for no session.
	 * @param string $verdict What the projection's resolve() answers.
	 * @param array<string, mixed>|null $file What the projection's file() answers.
	 *
	 * @return PeerReviewWorkController
	 */
	private function makeController(?string $uid, string $verdict, ?array $file = null): PeerReviewWorkController {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$projection = $this->createMock(PeerReviewWorkProjection::class);
		$projection->method('resolve')->willReturn(['verdict' => $verdict, 'hideAuthor' => true]);
		$projection->method('project')->willReturn(['peerReviewId' => 'pr-1', 'authorIds' => null, 'files' => []]);
		$projection->method('file')->willReturn($file);

		return new PeerReviewWorkController(
			request: $this->createMock(IRequest::class),
			userSession: $session,
			projection: $projection,
		);
	}//end makeController()

	/**
	 * The headers the response set itself. Response::getHeaders() merges in
	 * server defaults through \OCP\Server, which the unit environment lacks.
	 *
	 * @param Response $response The response.
	 *
	 * @return array<string, string>
	 */
	private function rawHeaders(Response $response): array {
		$property = new ReflectionProperty(Response::class, 'headers');
		return (array)$property->getValue($response);
	}//end rawHeaders()

	/**
	 * The reviewer gets the projection.
	 *
	 * @return void
	 */
	public function testTheReviewerGetsTheProjection(): void {
		$response = $this->makeController(uid: 'bob', verdict: PeerReviewWorkProjection::ALLOWED)->show(peerReviewId: 'pr-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('pr-1', $response->getData()['peerReviewId']);
		self::assertNull($response->getData()['authorIds']);
	}//end testTheReviewerGetsTheProjection()

	/**
	 * No session, someone else, and an unknown review each get their own status.
	 *
	 * @return void
	 */
	public function testRefusalsCarryTheirStatus(): void {
		self::assertSame(
			Http::STATUS_UNAUTHORIZED,
			$this->makeController(uid: null, verdict: PeerReviewWorkProjection::ALLOWED)->show(peerReviewId: 'pr-1')->getStatus()
		);
		self::assertSame(
			Http::STATUS_FORBIDDEN,
			$this->makeController(uid: 'mallory', verdict: PeerReviewWorkProjection::FORBIDDEN)->show(peerReviewId: 'pr-1')->getStatus()
		);
		self::assertSame(
			Http::STATUS_NOT_FOUND,
			$this->makeController(uid: 'bob', verdict: PeerReviewWorkProjection::NOT_FOUND)->show(peerReviewId: 'pr-9')->getStatus()
		);
	}//end testRefusalsCarryTheirStatus()

	/**
	 * A file of the reviewed work downloads under the projected name.
	 *
	 * @return void
	 */
	public function testAFileDownloadsUnderItsProjectedName(): void {
		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn('%PDF');
		$file->method('getMimeType')->willReturn('application/pdf');

		$response = $this->makeController(
			uid: 'bob',
			verdict: PeerReviewWorkProjection::ALLOWED,
			file: ['file' => $file, 'name' => 'file-1.pdf']
		)->file(peerReviewId: 'pr-1', fileId: '12');

		self::assertInstanceOf(DataDisplayResponse::class, $response);
		self::assertSame('%PDF', $response->getData());
		self::assertSame('application/pdf', $this->rawHeaders($response)['Content-Type'] ?? null);
		self::assertSame(
			'attachment; filename="file-1.pdf"; filename*=UTF-8\'\'file-1.pdf',
			$this->rawHeaders($response)['Content-Disposition'] ?? null
		);
	}//end testAFileDownloadsUnderItsProjectedName()

	/**
	 * A real name with quotes or accents can not break the header.
	 *
	 * @return void
	 */
	public function testAnAwkwardNameIsEncoded(): void {
		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn('x');
		$file->method('getMimeType')->willReturn('text/plain');

		$response = $this->makeController(
			uid: 'root',
			verdict: PeerReviewWorkProjection::ALLOWED,
			file: ['file' => $file, 'name' => 'Één "essay".txt']
		)->file(peerReviewId: 'pr-1', fileId: '12');

		self::assertSame(
			'attachment; filename="__n _essay_.txt"; filename*=UTF-8\'\'' . rawurlencode('Één "essay".txt'),
			$this->rawHeaders($response)['Content-Disposition'] ?? null
		);
	}//end testAnAwkwardNameIsEncoded()

	/**
	 * A file of another submission, or for someone else, is not served.
	 *
	 * @return void
	 */
	public function testForeignFilesAndStrangersAreRefused(): void {
		$foreign = $this->makeController(uid: 'bob', verdict: PeerReviewWorkProjection::ALLOWED, file: null)->file(peerReviewId: 'pr-1', fileId: '999');
		$stranger = $this->makeController(uid: 'mallory', verdict: PeerReviewWorkProjection::FORBIDDEN)->file(peerReviewId: 'pr-1', fileId: '12');
		$anonymous = $this->makeController(uid: null, verdict: PeerReviewWorkProjection::ALLOWED)->file(peerReviewId: 'pr-1', fileId: '12');

		self::assertInstanceOf(JSONResponse::class, $foreign);
		self::assertSame(Http::STATUS_NOT_FOUND, $foreign->getStatus());
		self::assertSame(Http::STATUS_FORBIDDEN, $stranger->getStatus());
		self::assertSame(Http::STATUS_UNAUTHORIZED, $anonymous->getStatus());
	}//end testForeignFilesAndStrangersAreRefused()

	/**
	 * Both routes exist and point at this controller.
	 *
	 * @return void
	 */
	public function testTheRoutesAreRegistered(): void {
		$routes = require __DIR__ . '/../../../appinfo/routes.php';
		$byName = [];
		foreach (($routes['routes'] ?? []) as $route) {
			$byName[$route['name']] = $route;
		}

		self::assertSame('/api/peer-review/{peerReviewId}/work', $byName['peerReviewWork#show']['url'] ?? null);
		self::assertSame('GET', $byName['peerReviewWork#show']['verb'] ?? null);
		self::assertSame('/api/peer-review/{peerReviewId}/work/files/{fileId}', $byName['peerReviewWork#file']['url'] ?? null);
		self::assertSame('GET', $byName['peerReviewWork#file']['verb'] ?? null);
	}//end testTheRoutesAreRegistered()
}//end class
