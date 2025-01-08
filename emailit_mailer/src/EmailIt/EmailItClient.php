<?php

namespace EmailIt;

class EmailItClient
{
	private string $apiKey;
	private string $baseUrl;
	private array $headers;
	
	public function __construct(string $apiKey, string $baseUrl = 'https://api.emailit.com/v1')
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
	 * Create a credential manager instance
	 * 
	 * @return CredentialManager
	 */
	public function credentials(): CredentialManager
	{
		return new CredentialManager($this);
	}
	
	/**
	 * Create a sending domain manager instance
	 * 
	 * @return SendingDomainManager
	 */
	public function sendingDomains(): SendingDomainManager
	{
		return new SendingDomainManager($this);
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
		
		$url = $this->baseUrl . $endpoint;
		
		curl_setopt_array($ch, [
			CURLOPT_URL => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => '',
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_HTTPHEADER => $this->formatHeaders(),
		]);
	
		if (!empty($params)) {
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
			throw new EmailItException(
				'API Error: ' . ($decodedResponse['message'] ?? 'Unknown error'),
				$statusCode
			);
		}
	
		return $decodedResponse;
	}
	
	/**
	 * Make HTTP request to API
	 * 
	 * @param array $params
	 * @return array
	 * @throws EmailItException
	 */
	public function sendEmailRequest(array $params): array
	{
		$requiredFields = ['from', 'to', 'reply_to', 'subject'];
		$this->validateRequired($params, $requiredFields);
		
		if (!isset($params['html']) && !isset($params['text'])) {
			throw new EmailItException('Either html or text content must be provided');
		}
	
		$ch = curl_init();
		
		curl_setopt_array($ch, [
			CURLOPT_URL => $this->baseUrl . '/emails',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => '',
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => json_encode($params),
			CURLOPT_HTTPHEADER => $this->formatHeaders(),
		]);
	
		$response = curl_exec($ch);
		$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error = curl_error($ch);
		
		curl_close($ch);
	
		if ($error) {
			throw new EmailItException('API Request Error: ' . $error);
		}
	
		$decodedResponse = json_decode($response, true);
		
		if ($statusCode >= 400) {
			throw new EmailItException(
				'API Error: ' . ($decodedResponse['message'] ?? 'Unknown error'),
				$statusCode
			);
		}
	
		return $decodedResponse;
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
	
	private function formatHeaders(): array
	{
		$formatted = [];
		foreach ($this->headers as $key => $value) {
			$formatted[] = "$key: $value";
		}
		return $formatted;
	}

}