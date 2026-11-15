<?php

/**
 * Learniq work group messages unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\WorkGroup
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
 * @spec openspec/specs/enrolment/spec.md#requirement-a-learner-joins-a-work-group-with-a-free-place
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\WorkGroup;

use OCA\Learniq\Service\WorkGroup\WorkGroupMessages;
use OCP\IL10N;
use OCP\IUser;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;

/**
 * A refused join or leave is explained in the learner's own language.
 *
 * @covers \OCA\Learniq\Service\WorkGroup\WorkGroupMessages
 */
class WorkGroupMessagesTest extends TestCase {

	/**
	 * Messages whose l10n prefixes the language it was asked for.
	 *
	 * @param IUser|null $expectedUser The account whose language is looked up.
	 * @param string     $language     That language.
	 *
	 * @return WorkGroupMessages
	 */
	private function messages(?IUser $expectedUser, string $language): WorkGroupMessages {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => '[' . $language . '] ' . $text);

		$factory = $this->createMock(IFactory::class);
		$factory->expects(self::once())->method('getUserLanguage')->with($expectedUser)->willReturn($language);
		$factory->expects(self::once())->method('get')->with('learniq', $language)->willReturn($l10n);

		return new WorkGroupMessages(l10nFactory: $factory);
	}//end messages()

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function reasons(): array {
		return [
			'not-found' => ['not-found', 'This work group could not be found.'],
			'not-in-cohort' => ['not-in-cohort', 'You are not in the group these work groups belong to.'],
			'sign-up-closed' => ['sign-up-closed', 'Sign-up for these work groups has closed. Ask your teacher.'],
			'full' => ['full', 'This work group is full.'],
			'not-a-member' => ['not-a-member', 'You are not in this work group.'],
			'no-account' => ['no-account', 'Your school account is not ready for this yet. Ask your school.'],
		];
	}//end reasons()

	/**
	 * @dataProvider reasons
	 */
	public function testEachReasonHasItsOwnTranslatedText(string $reason, string $text): void {
		$user = $this->createMock(IUser::class);

		self::assertSame('[nl] ' . $text, $this->messages($user, 'nl')->message($reason, $user));
	}//end testEachReasonHasItsOwnTranslatedText()

	public function testAnUnknownReasonReadsAsNotFound(): void {
		$user = $this->createMock(IUser::class);

		self::assertSame('[de] This work group could not be found.', $this->messages($user, 'de')->message('exploded', $user));
	}//end testAnUnknownReasonReadsAsNotFound()

	public function testWithoutAnAccountTheDefaultLanguageIsUsed(): void {
		self::assertSame('[en] This work group is full.', $this->messages(null, 'en')->message('full', null));
	}//end testWithoutAnAccountTheDefaultLanguageIsUsed()
}//end class
