<?php

namespace EmailIt;

/**
 * @deprecated Use ApiKeyManager instead.
 */
class CredentialManager extends ApiKeyManager
{
	/**
	 * @deprecated Use ApiKeyManager::create() instead.
	 */
	public function create(string $name, string $scope, array $attributes = []): array
	{
		trigger_error('CredentialManager::create() is deprecated. Use ApiKeyManager::create() instead.', E_USER_DEPRECATED);

		return parent::create($name, $scope, $attributes);
	}

	/**
	 * @deprecated Use ApiKeyManager::update() instead.
	 */
	public function update(string $id, string $name): array
	{
		trigger_error('CredentialManager::update() is deprecated. Use ApiKeyManager::update() instead.', E_USER_DEPRECATED);

		return parent::update($id, ['name' => $name]);
	}
}
