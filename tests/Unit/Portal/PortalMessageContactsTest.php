<?php

/**
 * Who a guardian or a pupil may write to on the portal, read over the real
 * example sets: Fatima writes to Meester Daan about Vera and to Juf Esra about
 * Sami; Noor writes to the teachers of H4b, Sanne Kramer (her mentor) first,
 * and her completed H3b enrolment names nobody (portal-message-contacts).
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Portal
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
 * @spec openspec/changes/portal-message-contacts/specs/portal-contribution/spec.md#requirement-a-guardian-and-a-pupil-may-write-to-the-teachers-of-the-pupils-current-groups
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Portal;

use OCA\Learniq\Portal\PortalContributionProvider;
use OCA\Learniq\Portal\PortalMessageContacts;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The teachers a resident may write to, over the seeded schools.
 */
class PortalMessageContactsTest extends TestCase {

	/**
	 * A contacts reader over one example set: OpenRegister answers from the
	 * set's seed objects, Nextcloud answers the display names the set's
	 * portal declaration gives its staff accounts.
	 *
	 * @param string $set The example set.
	 *
	 * @return PortalMessageContacts
	 */
	private function over(string $set): PortalMessageContacts {
		$root    = __DIR__ . '/../../../lib/Settings/';
		$objects = json_decode((string)file_get_contents($root . 'profiles/' . $set . '.json'), true)['x-openregister']['seedData']['objects'];
		$names   = array_column(json_decode((string)file_get_contents($root . 'portals/' . $set . '.json'), true)['accounts'], 'displayName', 'userId');

		$store = $this->createMock(ObjectService::class);
		$store->method('findAll')->willReturnCallback(
			static function (array $config) use ($objects): array {
				$filters = $config['filters'];
				$rows    = $objects[$filters['schema']] ?? [];
				unset($filters['register'], $filters['schema']);
				return array_values(
					array_filter(
						$rows,
						static function (array $row) use ($filters): bool {
							foreach ($filters as $field => $value) {
								$held = ($row[$field] ?? null);
								if (is_array($held) === true ? in_array($value, $held, true) === false : $held !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}
		);
		$store->method('find')->willReturnCallback(
			function (...$args) use ($objects): ?ObjectEntity {
				// find(id, _extend, files, register, schema, ...), positional through the mock.
				[$id, $schema] = [($args['id'] ?? $args[0]), (string)($args['schema'] ?? $args[4] ?? '')];
				foreach ($objects[$schema] ?? [] as $row) {
					if (($row['uuid'] ?? null) === $id) {
						$entity = $this->createMock(ObjectEntity::class);
						$entity->method('jsonSerialize')->willReturn($row);
						return $entity;
					}
				}

				return null;
			}
		);

		$users = $this->createMock(IUserManager::class);
		$users->method('getDisplayName')->willReturnCallback(static fn (string $uid): ?string => $names[$uid] ?? $uid);

		return new PortalMessageContacts($store, $users, $this->createMock(LoggerInterface::class));
	}//end over()

	/**
	 * A learner profile's or enrolment's uuid in a set, found by a field.
	 *
	 * @param string $set    The set.
	 * @param string $schema The schema.
	 * @param array  $match  Field values that must hold.
	 *
	 * @return array<string, mixed>
	 */
	private static function row(string $set, string $schema, array $match): array {
		$objects = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/profiles/' . $set . '.json'), true)['x-openregister']['seedData']['objects'];
		foreach ($objects[$schema] as $row) {
			if (array_intersect_assoc($match, $row) === $match) {
				return $row;
			}
		}

		self::fail('no ' . $schema . ' in ' . $set);
	}//end row()

	/**
	 * Fatima's two children each name the teacher of their own group.
	 *
	 * @return void
	 */
	public function testAGuardianWritesToTheTeacherOfEachChildsGroup(): void {
		$contacts = $this->over(set: 'po');
		$vera     = self::row(set: 'po', schema: 'learner-profile', match: ['ncUserId' => 'po-leerling-147']);
		$sami     = self::row(set: 'po', schema: 'learner-profile', match: ['ncUserId' => 'po-leerling-199']);

		self::assertSame(['po-leerkracht-09'], array_column($contacts->childContacts(profileId: $vera['uuid']), 'staffRef'));
		self::assertSame(['po-leerkracht-07'], array_column($contacts->childContacts(profileId: $sami['uuid']), 'staffRef'));
		self::assertNotSame('po-leerkracht-09', $contacts->childContacts(profileId: $vera['uuid'])[0]['name'], 'a person reads a name, never a user id');
		self::assertSame([], $contacts->childContacts(profileId: 'no-such-child'));
	}//end testAGuardianWritesToTheTeacherOfEachChildsGroup()

	/**
	 * Noor's active H4b enrolment names its teachers, her mentor first and as
	 * a mentor; her completed H3b enrolment names nobody.
	 *
	 * @return void
	 */
	public function testAPupilWritesToTheTeachersOfHerActiveGroupOnly(): void {
		$contacts = $this->over(set: 'vo');
		$noor     = self::row(set: 'vo', schema: 'learner-profile', match: ['ncUserId' => 'vo-leerling-121']);
		$active   = self::row(set: 'vo', schema: 'enrolment', match: ['learnerRef' => $noor['uuid'], 'lifecycle' => 'active']);
		$done     = self::row(set: 'vo', schema: 'enrolment', match: ['learnerRef' => $noor['uuid'], 'lifecycle' => 'completed']);

		$teachers = $contacts->ownContacts(enrolmentId: $active['uuid']);
		self::assertSame(['staffRef' => 'vo-docent-01', 'name' => 'Sanne Kramer', 'role' => 'Mentor'], $teachers[0]);
		self::assertSame(array_unique(array_column($teachers, 'staffRef')), array_column($teachers, 'staffRef'), 'each teacher once');
		foreach ($teachers as $teacher) {
			self::assertNotSame($teacher['staffRef'], $teacher['name'], 'a teacher without a display name is left out');
		}

		self::assertSame([], $contacts->ownContacts(enrolmentId: $done['uuid']));
	}//end testAPupilWritesToTheTeachersOfHerActiveGroupOnly()

	/**
	 * The manifests declare the two providers on the collections portaliq
	 * reads the records from, and the provider answers through them; without
	 * the service it answers nobody.
	 *
	 * @return void
	 */
	public function testTheManifestsNameTheProvidersAndTheProviderAnswers(): void {
		$provider = new PortalContributionProvider();
		$parent   = array_column($provider->getContribution(['audience' => 'parent'])['collections'], null, 'id');
		$student  = array_column($provider->getContribution(['audience' => 'student'])['collections'], null, 'id');

		self::assertSame('childMessageContacts', $parent['parentChildren']['contacts']['provider']);
		self::assertContains('givenName', $parent['parentChildren']['fields'], 'the record label field is projected, or portaliq drops it');
		self::assertSame(['givenName'], $parent['parentChildren']['contacts']['recordLabelFields']);
		self::assertSame('ownMessageContacts', $student['studentEnrolments']['contacts']['provider']);
		self::assertTrue(method_exists($provider, 'childMessageContacts'));
		self::assertSame([], $provider->ownMessageContacts('x'));

		$vera = self::row(set: 'po', schema: 'learner-profile', match: ['ncUserId' => 'po-leerling-147']);
		$with = new PortalContributionProvider(messageContacts: $this->over(set: 'po'));
		self::assertSame('po-leerkracht-09', $with->childMessageContacts($vera['uuid'])[0]['staffRef']);
	}//end testTheManifestsNameTheProvidersAndTheProviderAnswers()
}//end class
