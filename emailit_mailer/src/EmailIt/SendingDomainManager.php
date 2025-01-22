<?php

namespace EmailIt;

class SendingDomainManager
{
	private EmailItClient $client;
	
	public function __construct(EmailItClient $client)
	{
		$this->client = $client;
	}
	
	/**
	 * List all sending domains with optional filtering and pagination
	 * 
	 * @param int $perPage Number of sending domains per page (default: 25)
	 * @param int $page Page number
	 * @param string|null $nameFilter Filter sending domains by name
	 * @return array
	 */
	public function list(
		int $perPage = 25, 
		int $page = 1, 
		?string $nameFilter = null
	): array {
		$params = [
			'per_page' => $perPage,
			'page' => $page
		];

		if ($nameFilter) {
			$params['filter']['name'] = $nameFilter;
		}

		return $this->client->request('GET', '/sending-domains', $params);
	}

	/**
	 * Create a new sending domain
	 * 
	 * @param string $name Domain name (e.g., "emailit.com")
	 * @return array
	 */
	public function create(string $name): array
	{
		return $this->client->request('POST', '/sending-domains', [
			'name' => $name
		]);
	}

	/**
	 * Retrieve a sending domain by ID
	 * 
	 * @param string $id Sending domain ID
	 * @return array
	 */
	public function get(string $id): array
	{
		return $this->client->request('GET', "/sending-domains/{$id}");
	}

	/**
	 * Check DNS records of a sending domain
	 * 
	 * @param string $id Sending domain ID
	 * @return array
	 */
	public function checkDns(string $id): array
	{
		return $this->client->request('POST', "/sending-domains/{$id}/check");
	}

	/**
	 * Delete a sending domain
	 * 
	 * @param string $id Sending domain ID
	 * @return bool
	 */
	public function delete(string $id): bool
	{
		$this->client->request('DELETE', "/sending-domains/{$id}");
		return true;
	}
}