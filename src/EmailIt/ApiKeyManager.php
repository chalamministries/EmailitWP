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
	 * List API keys with optional filtering and pagination
	 *
	 * @param int $limit Number of keys per page (default: 25)
	 * @param int $page Page number (default: 1)
	 * @param array $filters Optional filters (e.g., ['name' => 'Main Site'])
	 * @return array
	 */
	public function list(int $limit = 25, int $page = 1, array $filters = []): array
	{
		$params = [
			'limit' => max(1, $limit),
			'page' => max(1, $page),
		];

		if (!empty($filters)) {
			foreach ($filters as $key => $value) {
				if ($value === null || $value === '') {
					continue;
				}
				$params['filter'][$key] = $value;
			}
		}

		return $this->client->request('GET', '/api-keys', $params);
	}

	/**
	 * Create a new API key
	 *
	 * @param string $name Name for the API key
	 * @param array $payload Additional payload (e.g., scopes, description, expires_at)
	 * @return array
	 */
	public function create(string $name, array $payload = []): array
	{
		$body = array_merge(['name' => $name], $payload);

		if (isset($body['scopes']) && is_array($body['scopes'])) {
			$body['scopes'] = array_values(array_unique($body['scopes']));
		}

		return $this->client->request('POST', '/api-keys', $body);
	}

	/**
	 * Retrieve an API key by ID
	 *
	 * @param string $id API key ID
	 * @return array
	 */
	public function get(string $id): array
	{
		return $this->client->request('GET', "/api-keys/{$id}");
	}

	/**
	 * Update an API key
	 *
	 * @param string $id API key ID
	 * @param array $payload Fields to update (e.g., name, scopes)
	 * @return array
	 * @throws EmailItException
	 */
	public function update(string $id, array $payload): array
	{
		if (empty($payload)) {
			throw new EmailItException('Update payload for API key cannot be empty.');
		}

		if (isset($payload['scopes']) && is_array($payload['scopes'])) {
			$payload['scopes'] = array_values(array_unique($payload['scopes']));
		}

		return $this->client->request('PATCH', "/api-keys/{$id}", $payload);
	}

	/**
	 * Delete an API key
	 *
	 * @param string $id API key ID
	 * @return bool
	 */
	public function delete(string $id): bool
	{
		$this->client->request('DELETE', "/api-keys/{$id}");
		return true;
	}
}