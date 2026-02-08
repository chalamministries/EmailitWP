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
	 * @param int $perPage Number of domains per page (default: 25)
	 * @param int $page Page number
	 * @param string|null $nameFilter Filter domains by name
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

		return $this->client->request('GET', '/domains', $params);
	}

	/**
	 * Create a new domain
	 * 
	 * @param string $name Domain name (e.g., "mail.yourdomain.com")
	 * @param bool|null $trackLoads Whether to track email loads for this domain
	 * @param bool|null $trackClicks Whether to track email clicks for this domain
	 * @return array
	 */
	public function create(string $name, ?bool $trackLoads = null, ?bool $trackClicks = null): array
	{
		$payload = [
			'name' => $name
		];

		if ($trackLoads !== null) {
			$payload['track_loads'] = $trackLoads;
		}

		if ($trackClicks !== null) {
			$payload['track_clicks'] = $trackClicks;
		}

		return $this->client->request('POST', '/domains', $payload);
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
	 * Verify DNS records of a domain
	 * 
	 * @param string $id Domain ID
	 * @return array
	 */
	public function verify(string $id): array
	{
		return $this->client->request('POST', "/domains/{$id}/verify");
	}

	/**
	 * @deprecated Use verify() instead.
	 */
	public function checkDns(string $id): array
	{
		return $this->verify($id);
	}

	/**
	 * Update a domain
	 * 
	 * @param string $id Domain ID
	 * @param array $attributes Supported keys: track_loads (bool), track_clicks (bool), tracking_key (string|null), inbound_key (string|null)
	 * @return array
	 * @throws EmailItException
	 */
	public function update(string $id, array $attributes): array
	{
		$payload = [];

		if (array_key_exists('track_loads', $attributes)) {
			if (!is_bool($attributes['track_loads'])) {
				throw new EmailItException('track_loads must be a boolean value');
			}
			$payload['track_loads'] = $attributes['track_loads'];
		}

		if (array_key_exists('track_clicks', $attributes)) {
			if (!is_bool($attributes['track_clicks'])) {
				throw new EmailItException('track_clicks must be a boolean value');
			}
			$payload['track_clicks'] = $attributes['track_clicks'];
		}

		if (array_key_exists('tracking_key', $attributes)) {
			$trackingKey = $attributes['tracking_key'];
			if ($trackingKey !== null && !is_string($trackingKey)) {
				throw new EmailItException('tracking_key must be a string or null');
			}
			$payload['tracking_key'] = $trackingKey;
		}

		if (array_key_exists('inbound_key', $attributes)) {
			$inboundKey = $attributes['inbound_key'];
			if ($inboundKey !== null && !is_string($inboundKey)) {
				throw new EmailItException('inbound_key must be a string or null');
			}
			$payload['inbound_key'] = $inboundKey;
		}

		if (empty($payload)) {
			throw new EmailItException('At least one attribute must be provided to update a domain.');
		}

		return $this->client->request('PATCH', "/domains/{$id}", $payload);
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