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
		$this->baseUrl = $baseUrl;
		$this->headers = [
			'Authorization' => 'Bearer ' . $this->apiKey,
			'Content-Type' => 'application/json',
			'Accept' => 'application/json'
		];
	}
	
	/**
	 * Create an email message builder
	 * 
	 * @return EmailBuilder
	 */
	public function email(): EmailBuilder
	{
		return new EmailBuilder($this);
	}
	
	/**
	 * Create an audience manager instance
	 * 
	 * @return AudienceManager
	 */
	public function audiences(): AudienceManager
	{
		return new AudienceManager($this);
	}

	/**
	 * Access subscriber operations for a specific audience.
	 */
	public function audienceSubscribers(string $audienceId): AudienceSubscriberManager
	{
		return new AudienceSubscriberManager($this, $audienceId);
	}
	
	/**
	 * Create an API key manager instance
	 * 
	 * @return ApiKeyManager
	 */
	public function apiKeys(): ApiKeyManager
	{
		return new ApiKeyManager($this);
	}
	
	/**
	 * Create a credential manager instance
	 * 
	 * @deprecated Use apiKeys() instead.
	 * @return CredentialManager
	 */
	public function credentials(): CredentialManager
	{
		trigger_error('EmailItClient::credentials() is deprecated. Use EmailItClient::apiKeys() instead.', E_USER_DEPRECATED);
		return new CredentialManager($this);
	}
	
	/**
	 * Create a domain manager instance
	 * 
	 * @return DomainManager
	 */
	public function domains(): DomainManager
	{
		return new DomainManager($this);
	}
	
	/**
	 * Create a sending domain manager instance
	 * 
	 * @deprecated Use domains() instead.
	 * @return SendingDomainManager
	 */
	public function sendingDomains(): SendingDomainManager
	{
		trigger_error('EmailItClient::sendingDomains() is deprecated. Use EmailItClient::domains() instead.', E_USER_DEPRECATED);
		return new SendingDomainManager($this);
	}
	
	/**
	 * Create a template manager instance
	 *
	 * @return TemplateManager
	 */
	public function templates(): TemplateManager
	{
		return new TemplateManager($this);
	}

	/**
	 * Create a suppression manager instance
	 *
	 * @return SuppressionManager
	 */
	public function suppressions(): SuppressionManager
	{
		return new SuppressionManager($this);
	}

	/**
	 * Create an email verification manager instance
	 *
	 * @return EmailVerificationManager
	 */
	public function emailVerifications(): EmailVerificationManager
	{
		return new EmailVerificationManager($this);
	}

	/**
	 * Create an event manager instance
	 * 
	 * @return EventManager
	 */
	public function events(): EventManager
	{
		return new EventManager($this);
	}
	
	/**
	 * Make HTTP request to API
	 * 
	 * @param string $method
	 * @param string $endpoint
	 * @param array $params
	 * @return array
	 * @throws EmailItException
	 */
	public function request(string $method, string $endpoint, array $params = []): array
	{
		$ch = curl_init();
		
		$normalizedMethod = strtoupper($method);
		$url = $this->baseUrl . $endpoint;
		
		if ($normalizedMethod === 'GET' && !empty($params)) {
			$queryString = http_build_query($params);
			if ($queryString !== '') {
				$url .= (strpos($url, '?') === false ? '?' : '&') . $queryString;
			}
		}
		
		curl_setopt_array($ch, [
			CURLOPT_URL => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => '',
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => $normalizedMethod,
			CURLOPT_HTTPHEADER => $this->formatHeaders(),
		]);
	
		if ($normalizedMethod !== 'GET' && !empty($params)) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
		}
	
		$response = curl_exec($ch);
		$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error = curl_error($ch);
		
		curl_close($ch);
	
		if ($error) {
			throw new EmailItException('API Request Error: ' . $error);
		}
	
		$decodedResponse = json_decode($response, true);

		if ($statusCode >= 400) {
			$errorMessage = 'Unknown error';
			if (is_array($decodedResponse)) {
				$errorMessage = $decodedResponse['message']
					?? $decodedResponse['error']
					?? $decodedResponse['errors'][0]['message']
					?? json_encode($decodedResponse);
			} elseif (is_string($response) && $response !== '') {
				$errorMessage = $response;
			}

			throw new EmailItException(
				"API Error (HTTP $statusCode): $errorMessage",
				$statusCode
			);
		}
	
		return $decodedResponse;
	}

	/**
	 * Make HTTP request to API and return raw response body.
	 *
	 * @param string $method
	 * @param string $endpoint
	 * @param array $params
	 * @param array $headers
	 * @return string
	 * @throws EmailItException
	 */
	public function requestRaw(string $method, string $endpoint, array $params = [], array $headers = []): string
	{
		$ch = curl_init();

		$normalizedMethod = strtoupper($method);
		$url = $this->baseUrl . $endpoint;

		if ($normalizedMethod === 'GET' && !empty($params)) {
			$queryString = http_build_query($params);
			if ($queryString !== '') {
				$url .= (strpos($url, '?') === false ? '?' : '&') . $queryString;
			}
		}

		curl_setopt_array($ch, [
			CURLOPT_URL => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => '',
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => $normalizedMethod,
			CURLOPT_HTTPHEADER => $this->formatHeaders($headers),
		]);

		if ($normalizedMethod !== 'GET' && !empty($params)) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
		}

		$response = curl_exec($ch);
		$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error = curl_error($ch);

		curl_close($ch);

		if ($error) {
			throw new EmailItException('API Request Error: ' . $error);
		}

		if ($statusCode >= 400) {
			$decodedResponse = json_decode($response, true);
			$message = is_array($decodedResponse)
				? ($decodedResponse['message'] ?? 'Unknown error')
				: (is_string($response) ? $response : 'Unknown error');

			throw new EmailItException('API Error: ' . $message, $statusCode);
		}

		return (string) $response;
	}
	
	/**
	 * Send a new email via the EmailIt API.
	 *
	 * @param array $params
	 * @return array
	 * @throws EmailItException
	 */
	public function sendEmail(array $params): array
	{
		$requiredFields = ['from', 'to', 'subject'];
		$this->validateRequired($params, $requiredFields);

		if (!isset($params['html']) && !isset($params['text'])) {
			throw new EmailItException('Either html or text content must be provided');
		}

		if (isset($params['scheduled_at']) && $params['scheduled_at'] instanceof \DateTimeInterface) {
			$params['scheduled_at'] = $params['scheduled_at']->format(DATE_ATOM);
		}

		return $this->request('POST', '/emails', $params);
	}

	/**
	 * Send a new email via the EmailIt API.
	 *
	 * @deprecated Use sendEmail() instead.
	 * @param array $params
	 * @return array
	 * @throws EmailItException
	 */
	public function sendEmailRequest(array $params): array
	{
		trigger_error('EmailItClient::sendEmailRequest() is deprecated. Use EmailItClient::sendEmail() instead.', E_USER_DEPRECATED);

		return $this->sendEmail($params);
	}

	/**
	 * Retrieve an email by ID.
	 *
	 * @param string $emailId
	 * @return array
	 * @throws EmailItException
	 */
	public function getEmail(string $emailId): array
	{
		return $this->request('GET', "/emails/{$emailId}");
	}

	/**
	 * Update a scheduled email by ID.
	 *
	 * @param string $emailId
	 * @param array $params
	 * @return array
	 * @throws EmailItException
	 */
	public function updateEmail(string $emailId, array $params): array
	{
		if (empty($params)) {
			throw new EmailItException('Update payload cannot be empty');
		}

		if (isset($params['scheduled_at']) && $params['scheduled_at'] instanceof \DateTimeInterface) {
			$params['scheduled_at'] = $params['scheduled_at']->format(DATE_ATOM);
		}

		return $this->request('POST', "/emails/{$emailId}", $params);
	}

	/**
	 * Cancel a scheduled email by ID.
	 *
	 * @param string $emailId
	 * @return array
	 * @throws EmailItException
	 */
	public function cancelEmail(string $emailId): array
	{
		return $this->request('POST', "/emails/{$emailId}/cancel");
	}

	/**
	 * Retry a failed email by ID.
	 *
	 * @param string $emailId
	 * @return array
	 * @throws EmailItException
	 */
	public function retryEmail(string $emailId): array
	{
		return $this->request('POST', "/emails/{$emailId}/retry");
	}
	
	/**
	 * Validate required fields
	 * 
	 * @param array $params
	 * @param array $required
	 * @throws EmailItException
	 */
	private function validateRequired(array $params, array $required): void
	{
		foreach ($required as $field) {
			if (!isset($params[$field]) || empty($params[$field])) {
				throw new EmailItException("Missing required field: {$field}");
			}
		}
	}
	
	private function formatHeaders(array $overrides = []): array
	{
		$headers = array_merge($this->headers, $overrides);
		$formatted = [];
		foreach ($headers as $key => $value) {
			$formatted[] = "$key: $value";
		}
		return $formatted;
	}

}