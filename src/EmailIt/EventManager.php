<?php

namespace EmailIt;

class EventManager
{
	private EmailItClient $client;
	
	public function __construct(EmailItClient $client)
	{
		$this->client = $client;
	}
	
	/**
	 * List all events with optional filtering and pagination
	 * 
	 * @param int $perPage Number of events per page (default: 25)
	 * @param int $page Page number
	 * @param string|null $typeFilter Filter events by type
	 * @return array
	 */
	public function list(
		int $perPage = 25, 
		int $page = 1, 
		?string $typeFilter = null
	): array {
		$params = [
			'per_page' => $perPage,
			'page' => $page
		];

		if ($typeFilter) {
			$params['filter']['type'] = $typeFilter;
		}

		return $this->client->request('GET', '/events', $params);
	}

	/**
	 * Retrieve an event by ID
	 * 
	 * @param string $id Event ID
	 * @return array
	 */
	public function get(string $id): array
	{
		return $this->client->request('GET', "/events/{$id}");
	}

	/**
	 * Get available event types
	 * 
	 * @return array List of available event types
	 */
	public static function getEventTypes(): array
	{
		return [
			'email.delivery.sent',
			// Add other event types as they become available
		];
	}
}