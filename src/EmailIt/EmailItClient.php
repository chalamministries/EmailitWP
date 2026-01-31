<?php

namespace EmailIt;

class EmailItClient
{
	private string $apiKey;
	private string $baseUrl;
	private array $headers;

	public function __construct(string $apiKey, string $baseUrl = 'https://api.emailit.com/v2')
	{
		$this->apiKey = $apiKey;
		$this->baseUrl = rtrim($baseUrl, '/');
		$this->headers = [
			'Authorization' => 'Bearer ' . $this->apiKey,
			'Content-Type' => 'application/json',
			'Accept' => 'application/json',
		];
	}

	/**
	 * Create an email message builder.
	 */
	public function email(): EmailBuilder
	{
		return new EmailBuilder($this);
	}

	/**
	 * Access the audience manager.
	 */
	public function audiences(): AudienceManager
	{
		return new AudienceManager($this);
	}

	/**
	 * Access the subscriber manager for a specific audience.
	 */
	public function audienceSubscribers(string $audienceId): AudienceSubscriberManager
	{
		return new AudienceSubscriberManager($this, $audienceId);
	}

	/**
	 * Access the API key manager.
	 */
	public function apiKeys(): ApiKeyManager
	{
		return new ApiKeyManager($this);
	}

	/**
	 * @deprecated Use apiKeys() instead.
	 */
	public function credentials(): CredentialManager
	{
		trigger_error(
			'EmailItClient::credentials() is deprecated. Use EmailItClient::apiKeys() instead.',
			E_USER_DEPRECATED
		);

		return new CredentialManager($this);
	}

	/**
	 * Access the domain manager.
	 */
	public function domains(): DomainManager
	{
		return new DomainManager($this);
	}

	/**
	 * @deprecated Use domains() instead.
	 */
	public function sendingDomains(): DomainManager
	{
		trigger_error(
			'EmailItClient::sendingDomains() is deprecated. Use EmailItClient::domains() instead.',
			E_USER_DEPRECATED
		);

		return new DomainManager($this);
	}

	/**
	 * Access the event manager.
	 */
	public function events(): EventManager
	{
		return new EventManager($this);
	}

	/**
	 * Perform an HTTP request against the EmailIt API.
	 *
	 * @param string $method
	 * @param string $endpoint
	 * @param array $params
	 * @return array
	 * @throws EmailItException
	 */
	public function request(string $method, string $endpoint, array $params = []): array
	{
		$method = strtoupper($method);
		$url = $this->buildUrl($endpoint, $method === 'GET' ? $params : []);

		$options = [
			CURLOPT_URL => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => '',
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_HTTPHEADER => $this->formatHeaders(),
		];

		if ($method !== 'GET' && !empty($params)) {
			$options[CURLOPT_POSTFIELDS] = json_encode($params);
		}

		$ch = curl_init();
		curl_setopt_array($ch, $options);

		$response = curl_exec($ch);
		$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error = curl_error($ch);

		curl_close($ch);

		if ($error) {
			throw new EmailItException('API Request Error: ' . $error);
		}

		$decodedResponse = null;
		if ($response !== false && $response !== '') {
			$decodedResponse = json_decode($response, true);
		}

		if ($statusCode >= 400) {
			$message = null;

			if (is_array($decodedResponse)) {
				$message = $decodedResponse['message'] ?? null;
			}

			throw new EmailItException(
				'API Error: ' . ($message ?? 'Unknown error'),
				$statusCode
			);
		}

		return is_array($decodedResponse) ? $decodedResponse : [];
	}

	/**
	 * Send an email via the /v2/emails endpoint.
	 */
	public function sendEmail(array $payload): array
	{
		$this->validateEmailPayload($payload);

		return $this->request('POST', '/emails', $payload);
	}

	/**
	 * Retrieve an email by identifier.
	 */
	public function getEmail(string $emailId): array
	{
		return $this->request('GET', "/emails/{$emailId}");
	}

	/**
	 * Update an existing email (e.g., schedule changes).
	 */
	public function updateEmail(string $emailId, array $payload): array
	{
		if (empty($payload)) {
			throw new EmailItException('Update payload for email cannot be empty.');
		}

		return $this->request('PATCH', "/emails/{$emailId}", $payload);
	}

	/**
	 * Cancel a scheduled email.
	 */
	public function cancelEmail(string $emailId): array
	{
		return $this->request('POST', "/emails/{$emailId}/cancel");
	}

	/**
	 * Retry a failed email send.
	 */
	public function retryEmail(string $emailId): array
	{
		return $this->request('POST', "/emails/{$emailId}/retry");
	}

	/**
	 * @deprecated Use sendEmail() instead.
	 */
	public function sendEmailRequest(array $params): array
	{
		trigger_error(
			'EmailItClient::sendEmailRequest() is deprecated. Use EmailItClient::sendEmail() instead.',
			E_USER_DEPRECATED
		);

		return $this->sendEmail($params);
	}

	private function buildUrl(string $endpoint, array $query = []): string
	{
		$endpoint = '/' . ltrim($endpoint, '/');
		$url = $this->baseUrl . $endpoint;

		if (!empty($query)) {
			$queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
			if ($queryString !== '') {
				$url .= (strpos($url, '?') === false ? '?' : '&') . $queryString;
			}
		}

		return $url;
	}

	private function validateEmailPayload(array $payload): void
	{
		$requiredFields = ['from', 'to', 'subject'];

		foreach ($requiredFields as $field) {
			if (!isset($payload[$field]) || $payload[$field] === '' || $payload[$field] === []) {
				throw new EmailItException("Missing required field: {$field}");
			}
		}

		if (!isset($payload['html']) && !isset($payload['text'])) {
			throw new EmailItException('Either html or text content must be provided');
		}
	}

	private function formatHeaders(): array
	{
		$formatted = [];

		foreach ($this->headers as $key => $value) {
			$formatted[] = "{$key}: {$value}";
		}

		return $formatted;
	}
}
