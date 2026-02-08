<?php

namespace EmailIt;

class ApiKeyManager
{
	private EmailItClient $client;

	public function __construct(EmailItClient $client)
	{
		$this->client = $client;
	}

	/**
	 * List all API keys with optional filtering and pagination
	 *
	 * @param int $perPage Number of API keys per page (default: 25)
	 * @param int $page Page number
	 * @param string|null $nameFilter Filter API keys by name
	 * @param string|null $scopeFilter Filter API keys by scope
	 * @return array
	 */
	public function list(
		int $perPage = 25,
		int $page = 1,
		?string $nameFilter = null,
		?string $scopeFilter = null
	): array {
		$params = [
			'per_page' => $perPage,
			'page' => $page
		];

		if ($nameFilter !== null) {
			$params['filter']['name'] = $nameFilter;
		}

		if ($scopeFilter !== null) {
			$params['filter']['scope'] = $scopeFilter;
		}

		return $this->client->request('GET', '/api-keys', $params);
	}

	/**
	 * Create a new API key
	 *
	 * @param string $name Name of the API key
	 * @param string $scope Scope for the API key
	 * @param array $attributes Additional attributes to include in the request payload
	 * @return array
	 */
	public function create(string $name, string $scope, array $attributes = []): array
	{
		if (trim($name) === '') {
			throw new EmailItException('API key name cannot be empty');
		}

		if (trim($scope) === '') {
			throw new EmailItException('API key scope cannot be empty');
		}

		$payload = array_merge($attributes, [
			'name' => $name,
			'scope' => $scope
		]);

		return $this->client->request('POST', '/api-keys', $payload);
	}

	/**
	 * Retrieve an API key by ID
	 *
	 * @param string $id API key identifier
	 * @return array
	 */
	public function get(string $id): array
	{
		return $this->client->request('GET', "/api-keys/{$id}");
	}

	/**
	 * Update an existing API key
	 *
	 * @param string $id API key identifier
	 * @param array $attributes Attributes to update
	 * @return array
	 */
	public function update(string $id, array $attributes): array
	{
		if (empty($attributes)) {
			throw new EmailItException('At least one attribute must be provided to update an API key');
		}

		return $this->client->request('POST', "/api-keys/{$id}", $attributes);
	}

	/**
	 * Delete an API key
	 *
	 * @param string $id API key identifier
	 * @return bool
	 */
	public function delete(string $id): bool
	{
		$this->client->request('DELETE', "/api-keys/{$id}");

		return true;
	}
}
