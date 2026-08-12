<?php

/**
 * Test stub for OCA\OpenRegister\Db\ObjectEntity.
 *
 * THIS STUB IS A MIRROR, NOT A CONVENIENCE. It must stay signature-for-signature
 * faithful to the real OpenRegister class, because the two swap places depending
 * on where the suite runs:
 *
 *   - Standalone (no Nextcloud server): the `OCA\OpenRegister\ => tests/Stubs/`
 *     PSR-4 mapping in tests/bootstrap.php resolves the name to THIS file.
 *   - In CI: the app is checked out into a real Nextcloud server tree next to
 *     openregister@development, `OC_App::loadApps()` runs, and Nextcloud's
 *     autoloader resolves the name to the REAL class instead.
 *
 * A stub that declares a method the real class does not have therefore produces
 * a suite that is green standalone and red in CI. That is exactly what happened:
 * this stub used to declare `getRegister()`, `getSchema()` and `getUuid()` as
 * concrete abstract methods so `createMock()` could configure them. On the real
 * class those are `__call` magic (OCP\AppFramework\Db\Entity), which PHPUnit
 * cannot configure — 40 of the 231 CI errors were
 * `MethodCannotBeConfiguredException: Trying to configure method "getRegister"`.
 *
 * So this stub now extends the same OCP base class and declares the same
 * properties, which gives it the same magic-accessor behaviour as the real
 * class. Tests must build instances (`(new ObjectEntity())->setRegister(...)`)
 * rather than mock the magic getters. `OpenRegisterContractTest` asserts the
 * mirror still holds.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Scholiq\Tests\Stubs\Db
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Mirror of OpenRegister's ObjectEntity for standalone Scholiq unit tests.
 *
 * The `@method` block below is copied verbatim from the real entity. It is what
 * makes the magic accessors visible to static analysis — without it PHPStan
 * reports "Call to an undefined method ObjectEntity::getUuid()" on production
 * code that is perfectly valid at runtime.
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getSlug()
 * @method void setSlug(?string $slug)
 * @method string|null getUri()
 * @method void setUri(?string $uri)
 * @method string|null getVersion()
 * @method void setVersion(?string $version)
 * @method string|null getRegister()
 * @method void setRegister(?string $register)
 * @method string|null getSchema()
 * @method void setSchema(?string $schema)
 * @method array|null getObject()
 * @method void setObject(?array $object)
 * @method array|null getFiles()
 * @method void setFiles(?array $files)
 * @method array|null getRelations()
 * @method void setRelations(?array $relations)
 * @method array|null getLocked()
 * @method void setLocked(?array $locked)
 * @method string|null getOwner()
 * @method void setOwner(?string $owner)
 * @method array|null getAuthorization()
 * @method void setAuthorization(?array $authorization)
 * @method string|null getFolder()
 * @method void setFolder(?string $folder)
 * @method string|null getApplication()
 * @method void setApplication(?string $application)
 * @method string|null getOrganisation()
 * @method void setOrganisation(?string $organisation)
 * @method array|null getValidation()
 * @method void setValidation(?array $validation)
 * @method array|null getDeleted()
 * @method void setDeleted(?array $deleted)
 * @method array|null getGeo()
 * @method void setGeo(?array $geo)
 * @method array|null getRetention()
 * @method void setRetention(?array $retention)
 * @method array|null getTmlo()
 * @method void setTmlo(?array $tmlo)
 * @method array|null getMail()
 * @method void setMail(?array $mail)
 * @method array|null getContacts()
 * @method void setContacts(?array $contacts)
 * @method array|null getNotes()
 * @method void setNotes(?array $notes)
 * @method array|null getTodos()
 * @method void setTodos(?array $todos)
 * @method array|null getCalendar()
 * @method void setCalendar(?array $calendar)
 * @method array|null getTalk()
 * @method void setTalk(?array $talk)
 * @method array|null getDeck()
 * @method void setDeck(?array $deck)
 * @method string|null getSize()
 * @method void setSize(?string $size)
 * @method string|null getSchemaVersion()
 * @method void setSchemaVersion(?string $schemaVersion)
 * @method string|null getName()
 * @method void setName(?string $name)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string|null getSummary()
 * @method void setSummary(?string $summary)
 * @method string|null getImage()
 * @method void setImage(?string $image)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 * @method DateTime|null getExpires()
 * @method void setExpires(?DateTime $expires)
 * @method array|null getGroups()
 * @method void setGroups(?array $groups)
 *
 * @SuppressWarnings(PHPMD)
 */
class ObjectEntity extends Entity implements JsonSerializable {
	protected ?string $uuid = null;

	protected ?string $slug = null;

	protected ?string $uri = null;

	protected ?string $version = null;

	protected ?string $register = null;

	protected ?string $schema = null;

	protected ?array $object = null;

	protected ?array $files = null;

	protected ?array $relations = null;

	protected ?array $locked = null;

	protected ?string $owner = null;

	protected ?array $authorization = null;

	protected ?string $folder = null;

	protected ?string $application = null;

	protected ?string $organisation = null;

	protected ?array $validation = null;

	protected ?array $deleted = null;

	protected ?array $geo = null;

	protected ?array $retention = null;

	protected ?array $tmlo = null;

	protected ?array $mail = null;

	protected ?array $contacts = null;

	protected ?array $notes = null;

	protected ?array $todos = null;

	protected ?array $calendar = null;

	protected ?array $talk = null;

	protected ?array $deck = null;

	protected ?string $size = null;

	protected ?string $schemaVersion = null;

	protected ?string $name = null;

	protected ?string $description = null;

	protected ?string $summary = null;

	protected ?string $image = null;

	protected ?DateTime $updated = null;

	protected ?DateTime $created = null;

	protected ?array $groups = null;

	protected ?DateTime $expires = null;

	protected ?string $source = null;

	/**
	 * Register the field types, mirroring the real entity's constructor.
	 */
	public function __construct() {
		$this->addType(fieldName: 'uuid', type: 'string');
		$this->addType(fieldName: 'slug', type: 'string');
		$this->addType(fieldName: 'uri', type: 'string');
		$this->addType(fieldName: 'version', type: 'string');
		$this->addType(fieldName: 'register', type: 'string');
		$this->addType(fieldName: 'schema', type: 'string');
		$this->addType(fieldName: 'object', type: 'json');
		$this->addType(fieldName: 'files', type: 'json');
		$this->addType(fieldName: 'relations', type: 'json');
		$this->addType(fieldName: 'locked', type: 'json');
		$this->addType(fieldName: 'owner', type: 'string');
		$this->addType(fieldName: 'authorization', type: 'json');
		$this->addType(fieldName: 'folder', type: 'string');
		$this->addType(fieldName: 'application', type: 'string');
		$this->addType(fieldName: 'organisation', type: 'string');
		$this->addType(fieldName: 'validation', type: 'json');
		$this->addType(fieldName: 'deleted', type: 'json');
		$this->addType(fieldName: 'geo', type: 'json');
		$this->addType(fieldName: 'retention', type: 'json');
		$this->addType(fieldName: 'tmlo', type: 'json');
		$this->addType(fieldName: 'mail', type: 'json');
		$this->addType(fieldName: 'contacts', type: 'json');
		$this->addType(fieldName: 'notes', type: 'json');
		$this->addType(fieldName: 'todos', type: 'json');
		$this->addType(fieldName: 'calendar', type: 'json');
		$this->addType(fieldName: 'talk', type: 'json');
		$this->addType(fieldName: 'deck', type: 'json');
		$this->addType(fieldName: 'size', type: 'string');
		$this->addType(fieldName: 'schemaVersion', type: 'string');
		$this->addType(fieldName: 'name', type: 'string');
		$this->addType(fieldName: 'description', type: 'string');
		$this->addType(fieldName: 'summary', type: 'string');
		$this->addType(fieldName: 'image', type: 'string');
		$this->addType(fieldName: 'updated', type: 'datetime');
		$this->addType(fieldName: 'created', type: 'datetime');
		$this->addType(fieldName: 'groups', type: 'json');
		$this->addType(fieldName: 'expires', type: 'datetime');

	}//end __construct()

	/**
	 * Mirror of the real getter: collection fields default to [] rather than null.
	 *
	 * @param string $name The property name.
	 *
	 * @return mixed The property value.
	 */
	protected function getter(string $name): mixed {
		$arrayEmptyDefaults = [
			'files',
			'relations',
			'authorization',
			'validation',
			'deleted',
			'groups',
			'geo',
			'retention',
			'tmlo',
		];

		if (in_array($name, $arrayEmptyDefaults) === true && property_exists($this, $name) === true) {
			return $this->$name ?? [];
		}

		return parent::getter(name: $name);
	}//end getter()

	/**
	 * Get the object data with the uuid injected as `id`.
	 *
	 * @return array<string,mixed>
	 */
	public function getObject(): array {
		return array_merge(['id' => $this->uuid], ($this->object ?? []));
	}//end getObject()

	/**
	 * Hydrate the entity from a metadata array, ignoring unknown properties.
	 *
	 * @param array<string,mixed> $object Metadata to hydrate with.
	 *
	 * @return static
	 */
	public function hydrate(array $object): static {
		foreach ($object as $key => $value) {
			$method = 'set' . ucfirst($key);

			try {
				$this->$method($value);
			} catch (\Throwable $exception) {
				// Silently ignore invalid properties, as the real entity does.
			}
		}

		return $this;
	}//end hydrate()

	/**
	 * Hydrate the entity from a serialized (`@self` + payload) array.
	 *
	 * @param array<string,mixed> $object Serialized object.
	 *
	 * @return static
	 */
	public function hydrateObject(array $object): static {
		$metaDataFields = ($object['@self'] ?? []);
		unset($object['@self']);

		$this->hydrate(object: $metaDataFields);
		$this->setObject($object);

		return $this;
	}//end hydrateObject()

	/**
	 * Serialize the entity: payload merged with an `@self` metadata block.
	 *
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		$object = ($this->object ?? []);

		$object['@self'] = $this->getObjectArray(object: $object);

		if (empty($object['@self']['name']) === true) {
			$object['@self']['name'] = $this->uuid;
		}

		if (($this->uuid ?? null) !== null) {
			$object['id'] = $this->uuid;
		}

		return $object;
	}//end jsonSerialize()

	/**
	 * Build the `@self` metadata block.
	 *
	 * @param array<string,mixed> $object Object array parameter.
	 *
	 * @return array<string,mixed>
	 */
	public function getObjectArray(array $object = []): array {
		return [
			'id' => $this->uuid,
			'slug' => $this->slug,
			'name' => ($this->name ?? $this->uuid),
			'description' => $this->description,
			'summary' => $this->summary,
			'image' => $this->image,
			'uri' => $this->uri,
			'version' => $this->version,
			'register' => $this->register,
			'schema' => $this->schema,
			'schemaVersion' => $this->schemaVersion,
			'files' => $this->getFiles(),
			'relations' => $this->getRelations(),
			'locked' => $this->locked,
			'owner' => $this->owner,
			'organisation' => $this->organisation,
			'groups' => $this->getGroups(),
			'authorization' => $this->getAuthorization(),
			'folder' => $this->folder,
			'application' => $this->application,
			'validation' => $this->getValidation(),
			'geo' => $this->getGeo(),
			'retention' => $this->getRetention(),
			'tmlo' => $this->getTmlo(),
			'size' => $this->size,
			'updated' => $this->updated?->format('c'),
			'created' => $this->created?->format('c'),
			'deleted' => $this->getDeleted(),
			'source' => $this->source,
		];

	}//end getObjectArray()

}//end class
