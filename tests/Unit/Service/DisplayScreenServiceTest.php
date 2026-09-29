<?php

/**
 * DisplayScreenService: the hash is stored, the token is answered once,
 * a revoked or wrong token opens nothing.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
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
 * @spec openspec/specs/personal-timetable/spec.md#requirement-an-administrator-sets-up-a-display-screen
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\DisplayScreenService;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * Issue, resolve and revoke a screen's address.
 */
class DisplayScreenServiceTest extends TestCase {

	/**
	 * The stored screen row.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $row = null;

	/**
	 * The service over one stored screen.
	 *
	 * @return DisplayScreenService
	 */
	private function service(): DisplayScreenService {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			fn ($id) => ($this->row !== null && $id === 'screen-1') ? OrEntityFactory::make($this->row, 'display-screen') : null
		);
		$objects->method('saveObject')->willReturnCallback(
			function ($object) {
				$this->row = $object;
				return OrEntityFactory::make($object, 'display-screen');
			}
		);
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn('s3cretS3cretS3cretS3cretS3cretS3cretS3cret');

		return new DisplayScreenService($objects, $random);
	}//end service()

	/**
	 * Set up one active screen without an address.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->row = ['id' => 'screen-1', 'name' => 'Aula gebouw A', 'status' => 'active', 'tokenHash' => null];
	}//end setUp()

	/**
	 * The token is answered once; only its hash is stored.
	 *
	 * @return void
	 */
	public function testHashStoredTokenReturnedOnce(): void {
		$token = $this->service()->issueToken('screen-1');

		self::assertSame('screen-1.s3cretS3cretS3cretS3cretS3cretS3cretS3cret', $token);
		self::assertSame(hash('sha256', 's3cretS3cretS3cretS3cretS3cretS3cretS3cret'), $this->row['tokenHash']);
		self::assertStringNotContainsString('s3cret', (string)json_encode($this->row));
		self::assertNotEmpty($this->row['tokenCreatedAt']);
	}//end testHashStoredTokenReturnedOnce()

	/**
	 * The right token opens the screen; a wrong secret or an unknown screen does not.
	 *
	 * @return void
	 */
	public function testOnlyTheRightTokenOpensTheScreen(): void {
		$service = $this->service();
		$token = (string)$service->issueToken('screen-1');

		self::assertSame('Aula gebouw A', $service->screenForToken($token)['name']);
		self::assertNull($service->screenForToken('screen-1.wrong'));
		self::assertNull($service->screenForToken('other.'.explode('.', $token)[1]));
		self::assertNull($service->screenForToken('no-dot'));
	}//end testOnlyTheRightTokenOpensTheScreen()

	/**
	 * A revoked address stops working at once.
	 *
	 * @return void
	 */
	public function testRevokedTokenIsRefused(): void {
		$service = $this->service();
		$token = (string)$service->issueToken('screen-1');

		self::assertTrue($service->revoke('screen-1'));
		self::assertSame('revoked', $this->row['status']);
		self::assertNull($this->row['tokenHash']);
		self::assertNull($service->screenForToken($token));
	}//end testRevokedTokenIsRefused()

	/**
	 * A screen the caller cannot see gets no address.
	 *
	 * @return void
	 */
	public function testUnknownScreenGetsNoAddress(): void {
		$this->row = null;

		self::assertNull($this->service()->issueToken('screen-1'));
		self::assertFalse($this->service()->revoke('screen-1'));
	}//end testUnknownScreenGetsNoAddress()
}//end class
