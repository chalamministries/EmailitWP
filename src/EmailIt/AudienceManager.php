<?php

namespace EmailIt;

class AudienceManager
{
	private EmailItClient $client;
	
	public function __construct(EmailItClient $client)
	{
		$this->client = $client;
	}
	
	/**
	 * List all audiences with optional filtering and pagination
	 * 
	 * @param int $perPage Number of audiences per page (default: 25)
	 * @param int $page Page number
	 * @param string|null $nameFilter Filter audiences by name
	 * @return array
	 */
	public function list(int $perPage = 25, int $page = 1, ?string $nameFilter = null): array
	{
		$params = [
			'per_page' => $perPage,
			'page' => $page
		];

		if ($nameFilter) {
			$params['filter']['name'] = $nameFilter;
		}

		return $this->client->request('GET', '/audiences', $params);
	}

	/**
	 * Create a new audience
	 * 
	 * @param string $name Name of the audience
	 * @return array
	 */
	public function create(string $name): array
	{
		return $this->client->request('POST', '/audiences', [
			'name' => $name
		]);
	}

	/**
	 * Retrieve an audience by ID
	 * 
	 * @param string $id Audience ID
	 * @return array
	 */
	public function get(string $id): array
	{
		return $this->client->request('GET', "/audiences/{$id}");
	}

	/**
	 * Update an audience
	 * 
	 * @param string $id Audience ID
	 * @param string $name New name for the audience
	 * @return array
	 */
	public function update(string $id, string $name): array
	{
		return $this->client->request('PUT', "/audiences/{$id}", [
			'name' => $name
		]);
	}

	/**
	 * Delete an audience
	 * 
	 * @param string $id Audience ID
	 * @return bool
	 */
	public function delete(string $id): bool
	{
		$this->client->request('DELETE', "/audiences/{$id}");
		return true;
	}

	/**
	 * Subscribe an email address to an audience
	 * 
	 * @param string $token Audience subscription token
	 * @param string $email Subscriber's email address
	 * @param string $firstName Subscriber's first name
	 * @param string $lastName Subscriber's last name
	 * @param array $customFields Optional custom fields
	 * @return array
	 */
	public function subscribe(
		string $token,
		string $email,
		string $firstName,
		string $lastName,
		array $customFields = []
	): array {
		$params = [
			'email' => $email,
			'first_name' => $firstName,
			'last_name' => $lastName
		];

		if (!empty($customFields)) {
			$params['custom_fields'] = $customFields;
		}

		return $this->client->request('POST', "/audiences/subscribe/{$token}", $params);
	}
}