<?php

namespace EmailIt;

class CredentialManager
{
	private EmailItClient $client;
	
	public function __construct(EmailItClient $client)
	{
		$this->client = $client;
	}
	
	/**
	 * List all credentials with optional filtering and pagination
	 * 
	 * @param int $perPage Number of credentials per page (default: 25)
	 * @param int $page Page number
	 * @param string|null $nameFilter Filter credentials by name
	 * @param string|null $typeFilter Filter credentials by type (smtp|api)
	 * @return array
	 */
	public function list(
		int $perPage = 25, 
		int $page = 1, 
		?string $nameFilter = null,
		?string $typeFilter = null
	): array {
		$params = [
			'per_page' => $perPage,
			'page' => $page
		];

		if ($nameFilter) {
			$params['filter']['name'] = $nameFilter;
		}

		if ($typeFilter) {
			if (!in_array($typeFilter, ['smtp', 'api'])) {
				throw new EmailItException('Invalid credential type. Must be either "smtp" or "api"');
			}
			$params['filter']['type'] = $typeFilter;
		}

		return $this->client->request('GET', '/credentials', $params);
	}

	/**
	 * Create a new credential
	 * 
	 * @param string $name Name of the credential
	 * @param string $type Type of credential (smtp|api)
	 * @return array
	 */
	public function create(string $name, string $type): array
	{
		if (!in_array($type, ['smtp', 'api'])) {
			throw new EmailItException('Invalid credential type. Must be either "smtp" or "api"');
		}

		return $this->client->request('POST', '/credentials', [
			'name' => $name,
			'type' => $type
		]);
	}

	/**
	 * Retrieve a credential by ID
	 * 
	 * @param string $id Credential ID
	 * @return array
	 */
	public function get(string $id): array
	{
		return $this->client->request('GET', "/credentials/{$id}");
	}

	/**
	 * Update a credential
	 * 
	 * @param string $id Credential ID
	 * @param string $name New name for the credential
	 * @return array
	 */
	public function update(string $id, string $name): array
	{
		return $this->client->request('PUT', "/credentials/{$id}", [
			'name' => $name
		]);
	}

	/**
	 * Delete a credential
	 * 
	 * @param string $id Credential ID
	 * @return bool
	 */
	public function delete(string $id): bool
	{
		$this->client->request('DELETE', "/credentials/{$id}");
		return true;
	}
}