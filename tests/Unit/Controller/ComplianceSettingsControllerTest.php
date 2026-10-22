<?php

/**
 * Tests for ComplianceSettingsController.
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
 * @link https://conduction.nl
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md#scenario-a-compliance-officer-records-the-signed-privacyconvenant-agreement
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\ComplianceSettingsController;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * The admin page edits only the agreement and notice fields of the Compliance record.
 */
class ComplianceSettingsControllerTest extends TestCase {
	/**
	 * The stored Compliance rows.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $rows = [];

	/**
	 * Every saveObject call.
	 *
	 * @var array<int, array{object: array<string, mixed>, uuid: string|null}>
	 */
	private array $saved = [];

	/**
	 * The controller over an in-memory store, with these request parameters.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return ComplianceSettingsController
	 */
	private function controller(array $params = []): ComplianceSettingsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key): mixed => ($params[$key] ?? null));

		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(fn (): array => OrEntityFactory::makeMany($this->rows, 'compliance'));
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null) {
				$this->saved[] = ['object' => $object, 'uuid' => $uuid];
				$this->rows = [array_merge($object, ['id' => ($uuid ?? 'compliance-1')])];
				return OrEntityFactory::make($object, 'compliance');
			}
		);

		$users = $this->createMock(IUserManager::class);
		$users->method('getDisplayName')->willReturnCallback(static fn (string $uid): ?string => ($uid === 'privacy-officer' ? 'Priya Privacy' : null));

		return new ComplianceSettingsController(request: $request, objectService: $objects, userManager: $users);
	}//end controller()

	/**
	 * Without a record the page reads the defaults.
	 *
	 * @return void
	 */
	public function testWithoutARecordThePageReadsTheDefaults(): void {
		$data = $this->controller()->show()->getData();

		self::assertFalse($data['privacyconvenantSigned']);
		self::assertNull($data['privacyconvenantSignedAt']);
		self::assertNull($data['lastReviewedBy']);
	}//end testWithoutARecordThePageReadsTheDefaults()

	/**
	 * A save sends only the editable fields, keeps the ones left out, and
	 * never passes the page's audit fields on.
	 *
	 * @return void
	 */
	public function testASaveWritesOnlyTheEditableFields(): void {
		$this->rows = [['id' => 'compliance-1', 'privacyconvenantSigned' => true, 'privacybijsluiterVersion' => '1', 'privacybijsluiterUrl' => 'https://school.example/notice', 'privacyconvenantSignedAt' => '2026-03-01T09:00:00+00:00']];

		$response = $this->controller(['privacybijsluiterVersion' => ' 2 ', 'lastReviewedBy' => 'mallory', 'privacyconvenantSignedAt' => '1999-01-01'])->update();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('compliance-1', $this->saved[0]['uuid']);
		$object = $this->saved[0]['object'];
		ksort($object);
		self::assertSame(
			['privacybijsluiterUrl' => 'https://school.example/notice', 'privacybijsluiterVersion' => '2', 'privacyconvenantSigned' => true],
			$object
		);
	}//end testASaveWritesOnlyTheEditableFields()

	/**
	 * A value of the wrong kind is refused and nothing is written.
	 *
	 * @return void
	 */
	public function testAWrongValueIsRefused(): void {
		$response = $this->controller(['privacyconvenantSigned' => 'maybe'])->update();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame([], $this->saved);
	}//end testAWrongValueIsRefused()

	/**
	 * The first save creates the record.
	 *
	 * @return void
	 */
	public function testTheFirstSaveCreatesTheRecord(): void {
		$this->controller(['privacyconvenantSigned' => true])->update();

		self::assertNull($this->saved[0]['uuid']);
		self::assertTrue($this->saved[0]['object']['privacyconvenantSigned']);
	}//end testTheFirstSaveCreatesTheRecord()
	/**
	 * The page names the person who saved last, not their user id.
	 *
	 * @return void
	 */
	public function testThePageReadsTheReviewersName(): void {
		$this->rows = [['id' => 'compliance-1', 'privacyconvenantSigned' => false, 'lastReviewedBy' => 'privacy-officer', 'lastReviewedAt' => '2026-10-01T10:00:00+00:00']];

		self::assertSame('Priya Privacy', $this->controller()->show()->getData()['lastReviewedByName']);
	}//end testThePageReadsTheReviewersName()
}//end class
