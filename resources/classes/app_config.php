<?php

/**
 * Application details object
 */
class ApplicationDetails {
	/**
	 * @var string
	 */
	public $name = 'OpenSMS';

	/**
	 * @var string
	 */
	public $uuid = '67cb7df9-f738-4555-8e09-3911f06a863e';

	/**
	 * @var string
	 */
	public $category = 'System';

	/**
	 * @var string
	 */
	public $subcategory = 'SMS';

	/**
	 * @var string
	 */
	public $version = '1.0.0';

	/**
	 * @var string
	 */
	public $license = 'MIT';

	/**
	 * @var string
	 */
	public $url = '';

	/**
	 * @var array
	 */
	public $description = [];

	/**
	 * @param string $name
	 * @return self
	 */
	public function set_name(string $name): self {
		$this->name = $name;

		return $this;
	}

	/**
	 * @param string $uuid
	 * @return self
	 */
	public function set_uuid(string $uuid): self {
		$this->uuid = $uuid;

		return $this;
	}

	/**
	 * @param string $category
	 * @return self
	 */
	public function set_category(string $category): self {
		$this->category = $category;

		return $this;
	}

	/**
	 * @param string $subcategory
	 * @return self
	 */
	public function set_subcategory(string $subcategory): self {
		$this->subcategory = $subcategory;

		return $this;
	}

	/**
	 * @param string $version
	 * @return self
	 */
	public function set_version(string $version): self {
		$this->version = $version;

		return $this;
	}

	/**
	 * @param string $license
	 * @return self
	 */
	public function set_license(string $license): self {
		$this->license = $license;

		return $this;
	}

	/**
	 * @param string $url
	 * @return self
	 */
	public function set_url(string $url): self {
		$this->url = $url;

		return $this;
	}

	/**
	 * @param string $description
	 * @return self
	 */
	public function set_description(string $description): self {
		$this->description = [
			'en-us' => $description
		];

		return $this;
	}

	/**
	 * @return array
	 */
	public function to_array(): array {
		return [
			'name' => $this->name,
			'uuid' => $this->uuid,
			'category' => $this->category,
			'subcategory' => $this->subcategory,
			'version' => $this->version,
			'license' => $this->license,
			'url' => $this->url,
			'description' => $this->description
		];
	}
}

/**
 * Database field object
 */
class DatabaseField {
	/**
	 * @var string
	 */
	public $name;

	/**
	 * @var array
	 */
	public $type = [];

	/**
	 * @var array
	 */
	public $key = [];

	/**
	 * @var string
	 */
	public $search_by = '';

	/**
	 * @var array
	 */
	public $description = [];

	/**
	 * @var array
	 */
	public $toggle = [];

	/**
	 * @param string $name
	 * @return self
	 */
	public function set_name(string $name): self {
		$this->name = $name;

		return $this;
	}

	/**
	 * @param string $type
	 * @param string $driver
	 * @return self
	 */
	public function set_type(string $type, string $driver = 'pgsql'): self {
		$this->type[$driver] = $type;

		return $this;
	}

	/**
	 * @param array $types
	 * @return self
	 */
	public function set_types(array $types): self {
		$this->type = $types;

		return $this;
	}

	/**
	 * @param string $key_type
	 * @return self
	 */
	public function set_key_type(string $key_type): self {
		$this->key['type'] = $key_type;

		return $this;
	}

	/**
	 * @param string $table
	 * @param string $field
	 * @return self
	 */
	public function set_reference(string $table, string $field): self {
		$this->key['reference'] = [
			'table' => $table,
			'field' => $field
		];

		return $this;
	}

	/**
	 * @param string $search_by
	 * @return self
	 */
	public function set_search_by(string $search_by): self {
		$this->search_by = $search_by;

		return $this;
	}

	/**
	 * @param string $description
	 * @return self
	 */
	public function set_description(string $description): self {
		$this->description = [
			'en-us' => $description
		];

		return $this;
	}

	/**
	 * @param array $toggle
	 * @return self
	 */
	public function set_toggle(array $toggle): self {
		$this->toggle = $toggle;

		return $this;
	}

	/**
	 * @return array
	 */
	public function to_array(): array {
		$result = [
			'name' => $this->name,
			'type' => $this->type,
			'key' => $this->key
		];

		if (!empty($this->search_by)) {
			$result['search_by'] = $this->search_by;
		}

		if (!empty($this->description)) {
			$result['description'] = $this->description;
		}

		if (!empty($this->toggle)) {
			$result['toggle'] = $this->toggle;
		}

		return $result;
	}
}

/**
 * Database table object
 */
class DatabaseTable {
	/**
	 * @var string
	 */
	public $name;

	/**
	 * @var string
	 */
	public $parent = '';

	/**
	 * @var array
	 */
	public $fields = [];

	/**
	 * @param string $name
	 * @return self
	 */
	public function set_name(string $name): self {
		$this->name = $name;

		return $this;
	}

	/**
	 * @param string $parent
	 * @return self
	 */
	public function set_parent(string $parent): self {
		$this->parent = $parent;

		return $this;
	}

	/**
	 * @param DatabaseField $field
	 * @return self
	 */
	public function add_field(DatabaseField $field): self {
		$this->fields[] = $field;

		return $this;
	}

	public function get_field(string $field_name): ?DatabaseField {
		foreach ($this->fields as $field) {
			if ($field->name === $field_name) {
				return $field;
			}
		}

		return null;
	}

	public function get_fields(): array {
		return $this->fields;
	}

	public function get_name(): string {
		return $this->name;
	}

	public function get_parent(): string {
		return $this->parent;
	}

	/**
	 * @return array
	 */
	public function to_array(): array {
		return [
			'name' => $this->name,
			'parent' => $this->parent,
			'fields' => array_map(function ($field) {
				return $field->to_array();
			}, $this->fields)
		];
	}
}

/**
 * Permission object
 */
class Permission {
	/**
	 * @var string
	 */
	public $name;

	/**
	 * @var array
	 */
	public $groups = [];

	/**
	 * @param string $name
	 * @return self
	 */
	public function set_name(string $name): self {
		$this->name = $name;

		return $this;
	}

	/**
	 * @param array $groups
	 * @return self
	 */
	public function set_groups(array $groups): self {
		$this->groups = $groups;

		return $this;
	}

	/**
	 * @return array
	 */
	public function to_array(): array {
		return [
			'name' => $this->name,
			'groups' => $this->groups
		];
	}
}

/**
 * Application object that contains all the related objects
 */
class Application {
	/**
	 * @var ApplicationDetails
	 */
	public $details;

	/**
	 * @var array
	 */
	public $database_tables = [];

	/**
	 * @var array
	 */
	public $permissions = [];

	/**
	 * @param ApplicationDetails $details
	 */
	public function __construct(ApplicationDetails $details) {
		$this->details = $details;
	}

	/**
	 * @param DatabaseTable $table
	 * @return self
	 */
	public function add_database_table(DatabaseTable $table): self {
		$this->database_tables[] = $table;

		return $this;
	}

	/**
	 * @param Permission $permission
	 * @return self
	 */
	public function add_permission(Permission $permission): self {
		$this->permissions[] = $permission;

		return $this;
	}

	/**
	 * @return array
	 */
	public function to_array(): array {
		$result = [
			'details' => $this->details->to_array(),
			'db' => array_map(function ($table) {
				return $table->to_array();
			}, $this->database_tables),
			'permissions' => array_map(function ($permission) {
				return $permission->to_array();
			}, $this->permissions),
		];

		// Reconstruct the array structure as expected
		$x = $result['details'];
		$x['db'] = $result['db'];
		$x['permissions'] = $result['permissions'];

		return $x;
	}
}

// Usage example with chaining using the structure from earlier
$app = (new Application(new ApplicationDetails()))
	->add_database_table(
		(new DatabaseTable())
			->set_name('users')
			->set_parent('')
			->add_field(
				(new DatabaseField())
					->set_name('id')
					->set_type('integer', 'pgsql')
					->set_key_type('primary')
			)
			->add_field(
				(new DatabaseField())
					->set_name('username')
					->set_type('string', 'pgsql')
					->set_search_by('1')
					->set_description('User login name')
			)
	)
	->add_database_table(
		(new DatabaseTable())
			->set_name('messages')
			->set_parent('')
			->add_field(
				(new DatabaseField())
					->set_name('id')
					->set_type('integer', 'pgsql')
					->set_key_type('primary')
			)
			->add_field(
				(new DatabaseField())
					->set_name('content')
					->set_type('text', 'pgsql')
					->set_description('Message content')
			)
	)
	->add_permission(
		(new Permission())
			->set_name('admin')
			->set_groups(['admin', 'moderator'])
	)
	->add_permission(
		(new Permission())
			->set_name('user')
			->set_groups(['user'])
	);

// Get the array representation
$result = $app->to_array();
print_r($result);

$x = 0;
include '../../app_config.php';
print_r($apps[$x]);
