<?php

/**
 * ListenerSchemaResolver turns a transition event's ids into slugs.
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
 * @spec openspec/specs/certification/spec.md#requirement-an-issued-certificate-carries-a-signed-europass-form
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Tests\Support\TransitionScope;
use PHPUnit\Framework\TestCase;

/**
 * Slugs pass through, Learniq ids resolve, other registers never match.
 */
class ListenerSchemaResolverEventTest extends TestCase {

	/**
	 * A slug is answered as it is; Learniq's register id resolves to its slug.
	 *
	 * @return void
	 */
	public function testTheRegisterIsAnsweredAsASlug(): void {
		$resolver = TransitionScope::resolver();

		self::assertSame('learniq', $resolver->eventRegisterSlug(register: 'learniq'));
		self::assertSame('learniq', $resolver->eventRegisterSlug(register: TransitionScope::LEARNIQ_REGISTER_ID));
		self::assertSame('opencatalogi', $resolver->eventRegisterSlug(register: TransitionScope::OTHER_REGISTER_ID));
		self::assertSame('999999', $resolver->eventRegisterSlug(register: '999999'));
	}//end testTheRegisterIsAnsweredAsASlug()

	/**
	 * A schema id resolves only inside Learniq's register, whatever the
	 * `listener_slug_contract` gate says (TransitionScope keeps it off).
	 *
	 * @return void
	 */
	public function testASchemaIdResolvesOnlyInLearniqsRegister(): void {
		$resolver = TransitionScope::resolver();
		$enrolment = TransitionScope::schemaId(slug: 'enrolment');

		self::assertSame('enrolment', $resolver->eventSchemaSlug(register: 'learniq', schema: 'enrolment'));
		self::assertSame('enrolment', $resolver->eventSchemaSlug(register: TransitionScope::LEARNIQ_REGISTER_ID, schema: $enrolment));
		self::assertSame($enrolment, $resolver->eventSchemaSlug(register: TransitionScope::OTHER_REGISTER_ID, schema: $enrolment));
		self::assertSame('999999', $resolver->eventSchemaSlug(register: TransitionScope::LEARNIQ_REGISTER_ID, schema: '999999'));
	}//end testASchemaIdResolvesOnlyInLearniqsRegister()
}//end class
