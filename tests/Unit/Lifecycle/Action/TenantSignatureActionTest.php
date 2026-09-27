<?php

/**
 * Learniq TenantSignatureAction unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Lifecycle\Action
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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

namespace OCA\Learniq\Tests\Unit\Lifecycle\Action;

use OCA\Learniq\Lifecycle\Action\TenantSignatureAction;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\OpenRegister\Service\TenantKeyService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The signing half of Attestation.sign, BsaWarning.issue and BsaDecision.decide
 * (learniq#983): the guards only check, this action writes `signature` and
 * `signingKeyId` onto the object OpenRegister saves.
 */
class TenantSignatureActionTest extends TestCase {

	/**
	 * The object as OpenRegister's LifecycleActionListener hands it to the action.
	 *
	 * @return array<string,mixed>
	 */
	private function objectData(): array {
		return [
			'id' => 'attestation-1',
			'learnerId' => 'learner-7',
			'lessonId' => 'lesson-3',
			'score' => 88,
			'nested' => ['b' => 2, 'a' => 1],
			'tenant_id' => 'tenant-a',
			'lifecycle' => 'signed',
		];
	}//end objectData()

	/**
	 * Build the action over a tenant key service answering the given key.
	 *
	 * @param string $key The tenant key to answer.
	 *
	 * @return TenantSignatureAction
	 */
	private function makeAction(string $key): TenantSignatureAction {
		$tenantKeyService = $this->createMock(TenantKeyService::class);
		$tenantKeyService->method('getCurrentTenantKey')->with('tenant-a')->willReturn($key);

		return new TenantSignatureAction($tenantKeyService);
	}//end makeAction()

	/**
	 * OpenRegister's action registry refuses a handler without the interface.
	 *
	 * @return void
	 */
	public function testImplementsTheOpenRegisterActionInterface(): void {
		$this->assertInstanceOf(LifecycleActionInterface::class, $this->makeAction('k'));
	}//end testImplementsTheOpenRegisterActionInterface()

	/**
	 * The returned object carries the HMAC over the canonical payload and the key fingerprint.
	 *
	 * @return void
	 */
	public function testSignatureAndKeyIdEndUpOnTheSavedObject(): void {
		$result = $this->makeAction('super-secret-key')->execute($this->objectData(), [], [], TenantSignatureAction::class);

		$canonical = [
			'id' => 'attestation-1',
			'learnerId' => 'learner-7',
			'lessonId' => 'lesson-3',
			'nested' => ['a' => 1, 'b' => 2],
			'score' => 88,
			'tenant_id' => 'tenant-a',
		];
		ksort($canonical);
		$expected = hash_hmac('sha256', (string)json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'super-secret-key');

		$this->assertSame($expected, $result['signature']);
		$this->assertSame(substr(hash('sha256', 'super-secret-key'), 0, 16), $result['signingKeyId']);
		$this->assertSame('signed', $result['lifecycle']);
		$this->assertSame('learner-7', $result['learnerId']);
	}//end testSignatureAndKeyIdEndUpOnTheSavedObject()

	/**
	 * Stale signature fields and the lifecycle value do not change the digest.
	 *
	 * @return void
	 */
	public function testSignatureIsStableUnderItsOwnFields(): void {
		$action = $this->makeAction('k');
		$first = $action->execute($this->objectData(), [], [], TenantSignatureAction::class)['signature'];

		$withSelf = $this->objectData();
		$withSelf['signature'] = 'stale';
		$withSelf['signingKeyId'] = 'stale-key';
		$withSelf['lifecycle'] = 'drafted';

		$this->assertSame($first, $action->execute($withSelf, [], [], TenantSignatureAction::class)['signature']);
	}//end testSignatureIsStableUnderItsOwnFields()

	/**
	 * Without a tenant key the action throws instead of saving an unsigned object.
	 *
	 * @return void
	 */
	public function testMissingTenantKeyThrows(): void {
		$this->expectException(RuntimeException::class);

		$this->makeAction('')->execute($this->objectData(), [], [], TenantSignatureAction::class);
	}//end testMissingTenantKeyThrows()
}//end class
