<?php

namespace EmailIt;

class DomainManager
{
	private EmailItClient $client;
	
	public function __construct(EmailItClient $client)
	{
		$this->client = $client;
	}
	
	/**
	 * List all domains with optional filtering and pagination
	 * 
	 * @param int $limit Number of domains per page (default: 25)
	 * @param int $page Page number
	 * @param string|null $nameFilter Filter domains by name
	 * @return array
	 */
	public function list(
		int $limit = 25,
		int $page = 1,
		?string $nameFilter = null
	): array {
		$params = [
			'limit' => max(1, $limit),
			'page' => max(1, $page)
		];

		if ($nameFilter) {
			$params['filter']['name'] = $nameFilter;
		}

		return $this->client->request('GET', '/domains', $params);
	}

	/**
	 * Create a new domain
	 * 
	 * @param string $name Domain name (e.g., "emailit.com")
	 * @return array
	 */
	public function create(string $name): array
	{
		return $this->client->request('POST', '/domains', [
			'name' => $name
		]);
	}

	/**
	 * Retrieve a domain by ID
	 * 
	 * @param string $id Domain ID
	 * @return array
	 */
	public function get(string $id): array
	{
		return $this->client->request('GET', "/domains/{$id}");
	}

	/**
	 * Check DNS records of a domain
	 * 
	 * @param string $id Domain ID
	 * @return array
	 */
	public function checkDns(string $id): array
	{
		return $this->client->request('POST', "/domains/{$id}/check");
	}

	/**
	 * Delete a domain
	 * 
	 * @param string $id Domain ID
	 * @return bool
	 */
	public function delete(string $id): bool
	{
		$this->client->request('DELETE', "/domains/{$id}");
		return true;
	}
}