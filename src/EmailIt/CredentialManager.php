<?php

namespace EmailIt;

class CredentialManager extends ApiKeyManager
{
	public function __construct(EmailItClient $client)
	{
		trigger_error(
			'CredentialManager is deprecated. Use EmailIt\\ApiKeyManager instead.',
			E_USER_DEPRECATED
		);

		parent::__construct($client);
	}

	public function list(
		int $perPage = 25,
		int $page = 1,
		?string $nameFilter = null,
		?string $typeFilter = null
	): array {
		$filters = [];

		if ($nameFilter) {
			$filters['name'] = $nameFilter;
		}

		if ($typeFilter) {
			$filters['type'] = $typeFilter;
		}

		return parent::list($perPage, $page, $filters);
	}

	public function create(string $name, string $type): array
	{
		if (!in_array($type, ['smtp', 'api'], true)) {
			throw new EmailItException('Invalid credential type. Must be either "smtp" or "api"');
		}

		return parent::create($name, ['type' => $type]);
	}

	public function get(string $id): array
	{
		return parent::get($id);
	}

	public function update(string $id, string $name): array
	{
		return parent::update($id, ['name' => $name]);
	}

	public function delete(string $id): bool
	{
		return parent::delete($id);
	}
}