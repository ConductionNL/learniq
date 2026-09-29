<?php

/**
 * Learniq credential reissue register test.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Settings
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
 * @spec openspec/changes/credentials-bulk-reissue/specs/certification/spec.md#requirement-a-reissue-keeps-who-and-when-and-records-why
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use OCA\Learniq\Service\CredentialReissueService;
use PHPUnit\Framework\TestCase;

/**
 * Pins the reissue history, the self-loop and its inputs, and the learner
 * notification.
 */
class CredentialReissueRegisterTest extends TestCase {

	/**
	 * The Credential schema as shipped.
	 *
	 * @return array<string, mixed>
	 */
	private function credential(): array {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true);

		return $register['components']['schemas']['Credential'];
	}//end credential()

	/**
	 * The history fields exist, and the count starts at zero.
	 *
	 * @return void
	 */
	public function testTheHistoryIsDeclared(): void {
		$properties = $this->credential()['properties'];

		foreach (['reissuedAt', 'reissueCount', 'reissueReason', 'reissuedBy', 'reissueRunId', 'walletOfferNote'] as $field) {
			self::assertArrayHasKey($field, $properties, $field);
		}

		self::assertSame(0, $properties['reissueCount']['default']);
	}//end testTheHistoryIsDeclared()

	/**
	 * `reissue` is an issued-to-issued self-loop that accepts exactly the
	 * fields the service writes, and none of the identity or dates.
	 *
	 * @return void
	 */
	public function testTheSelfLoopAcceptsOnlyWhatTheServiceWrites(): void {
		$reissue = $this->credential()['x-openregister-lifecycle']['transitions']['reissue'];

		self::assertSame(['issued'], $reissue['from']);
		self::assertSame('issued', $reissue['to']);
		$fields = array_column($reissue['inputs'], 'field');
		self::assertSame(CredentialReissueService::REISSUE_INPUTS, $fields);
		self::assertSame([], array_intersect($fields, ['id', 'learnerId', 'courseId', 'issuedAt', 'expiresAt', 'kind']));
	}//end testTheSelfLoopAcceptsOnlyWhatTheServiceWrites()

	/**
	 * The learner hears of a reissue.
	 *
	 * @return void
	 */
	public function testTheLearnerIsNotified(): void {
		$notification = $this->credential()['x-openregister-notifications']['reissued'];

		self::assertSame(['type' => 'transition', 'action' => 'reissue'], $notification['trigger']);
		self::assertSame([['kind' => 'field', 'field' => 'learnerUserId']], $notification['recipients']);
	}//end testTheLearnerIsNotified()
}//end class
