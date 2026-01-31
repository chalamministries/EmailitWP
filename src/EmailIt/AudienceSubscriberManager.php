<?php

namespace EmailIt;

class AudienceSubscriberManager
{
	private EmailItClient $client;
	private string $audienceId;

	public function __construct(EmailItClient $client, string $audienceId)
	{
		$this->client = $client;
		$this->audienceId = $audienceId;
	}

	/**
	 * List subscribers for the configured audience.
	 *
	 * @param int $limit Number of subscribers per page (default: 25)
	 * @param int $page Page number (default: 1)
	 * @param array $filters Optional filters (e.g., ['email' => 'user@example.com'])
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

		return $this->client->request('GET', $this->endpoint(), $params);
	}

	/**
	 * Add a new subscriber to the audience.
	 *
	 * @param array $subscriber Subscriber payload (must include 'email').
	 * @return array
	 * @throws EmailItException
	 */
	public function add(array $subscriber): array
	{
		if (empty($subscriber['email']) || !is_string($subscriber['email'])) {
			throw new EmailItException('Subscriber email is required.');
		}

		$subscriber['email'] = trim($subscriber['email']);

		if ($subscriber['email'] === '') {
			throw new EmailItException('Subscriber email is required.');
		}

		if (isset($subscriber['custom_fields']) && !is_array($subscriber['custom_fields'])) {
			throw new EmailItException('Subscriber custom_fields must be an array.');
		}

		return $this->client->request('POST', $this->endpoint(), $subscriber);
	}

	/**
	 * Retrieve a subscriber by ID.
	 *
	 * @param string $subscriberId Subscriber identifier
	 * @return array
	 */
	public function get(string $subscriberId): array
	{
		return $this->client->request('GET', $this->endpoint("/{$subscriberId}"));
	}

	/**
	 * Update an existing subscriber.
	 *
	 * @param string $subscriberId Subscriber identifier
	 * @param array $payload Fields to update
	 * @return array
	 * @throws EmailItException
	 */
	public function update(string $subscriberId, array $payload): array
	{
		if (empty($payload)) {
			throw new EmailItException('Subscriber update payload cannot be empty.');
		}

		if (isset($payload['email'])) {
			$payload['email'] = trim((string) $payload['email']);
			if ($payload['email'] === '') {
				unset($payload['email']);
			}
		}

		if (isset($payload['custom_fields']) && !is_array($payload['custom_fields'])) {
			throw new EmailItException('Subscriber custom_fields must be an array.');
		}

		return $this->client->request('PATCH', $this->endpoint("/{$subscriberId}"), $payload);
	}

	/**
	 * Delete a subscriber from the audience.
	 *
	 * @param string $subscriberId Subscriber identifier
	 * @return bool
	 */
	public function delete(string $subscriberId): bool
	{
		$this->client->request('DELETE', $this->endpoint("/{$subscriberId}"));
		return true;
	}

	/**
	 * Unsubscribe a subscriber without deleting their profile.
	 *
	 * @param string $subscriberId Subscriber identifier
	 * @param array $payload Optional payload (e.g., ['reason' => 'User requested'])
	 * @return array
	 */
	public function unsubscribe(string $subscriberId, array $payload = []): array
	{
		return $this->client->request('POST', $this->endpoint("/{$subscriberId}/unsubscribe"), $payload);
	}

	private function endpoint(string $suffix = ''): string
	{
		$base = "/audiences/{$this->audienceId}/subscribers";
		return $suffix ? $base . $suffix : $base;
	}
}
