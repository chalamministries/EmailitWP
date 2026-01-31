<?php

namespace EmailIt;

class EmailBuilder
{
	private array $emailArr = [];
	private EmailItClient $client;

	public function __construct(EmailItClient $client)
	{
		$this->client = $client;
	}

	public function from(string $from): self
	{
		$this->emailArr['from'] = $from;
		return $this;
	}

	/**
	 * @param string|array $to
	 */
	public function to($to): self
	{
		$this->setAddresses('to', $to);
		return $this;
	}

	public function replyTo(string $replyTo): self
	{
		$this->emailArr['reply_to'] = $replyTo;
		return $this;
	}

	/**
	 * @param string|array $cc
	 */
	public function cc($cc): self
	{
		$this->setAddresses('cc', $cc);
		return $this;
	}

	/**
	 * @param string|array $cc
	 */
	public function addCc($cc): self
	{
		$this->setAddresses('cc', $cc, true);
		return $this;
	}

	/**
	 * @param string|array $bcc
	 */
	public function bcc($bcc): self
	{
		$this->setAddresses('bcc', $bcc);
		return $this;
	}

	/**
	 * @param string|array $bcc
	 */
	public function addBcc($bcc): self
	{
		$this->setAddresses('bcc', $bcc, true);
		return $this;
	}

	public function subject(string $subject): self
	{
		$this->emailArr['subject'] = $subject;
		return $this;
	}

	public function html(string $html): self
	{
		$this->emailArr['html'] = $html;
		return $this;
	}

	public function text(string $text): self
	{
		$this->emailArr['text'] = $text;
		return $this;
	}

	public function addAttachment(string $filename, string $content, string $contentType): self
	{
		if (!isset($this->emailArr['attachments'])) {
			$this->emailArr['attachments'] = [];
		}

		$this->emailArr['attachments'][] = [
			'filename' => $filename,
			'content' => $content,
			'content_type' => $contentType,
		];

		return $this;
	}

	public function addHeader(string $name, string $value): self
	{
		if (!isset($this->emailArr['headers'])) {
			$this->emailArr['headers'] = [];
		}

		$this->emailArr['headers'][$name] = $value;
		return $this;
	}

	public function send(): array
	{
		return $this->client->sendEmail($this->emailArr);
	}

	public function get(string $emailId): array
	{
		return $this->client->getEmail($emailId);
	}

	public function update(string $emailId, array $payload = []): array
	{
		$updatePayload = $payload ?: $this->emailArr;

		if (empty($updatePayload)) {
			throw new EmailItException('No email payload available to update.');
		}

		return $this->client->updateEmail($emailId, $updatePayload);
	}

	public function cancel(string $emailId): array
	{
		return $this->client->cancelEmail($emailId);
	}

	public function retry(string $emailId): array
	{
		return $this->client->retryEmail($emailId);
	}

	public function getPayload(): array
	{
		return $this->emailArr;
	}

	public function resetPayload(): self
	{
		$this->emailArr = [];
		return $this;
	}

	/**
	 * @param string $field
	 * @param mixed $addresses
	 * @param bool $merge
	 */
	private function setAddresses(string $field, $addresses, bool $merge = false): void
	{
		$normalized = $this->normalizeAddressList($addresses);

		if ($merge && isset($this->emailArr[$field])) {
			$existing = $this->normalizeAddressList($this->emailArr[$field]);
			$normalized = $this->mergeAddressLists($existing, $normalized);
		}

		if (empty($normalized)) {
			unset($this->emailArr[$field]);
			return;
		}

		$this->emailArr[$field] = $this->formatAddressField($normalized);
	}

	/**
	 * @param array $addresses
	 * @return mixed
	 */
	private function formatAddressField(array $addresses)
	{
		return count($addresses) === 1 ? $addresses[0] : $addresses;
	}

	private function mergeAddressLists(array $existing, array $incoming): array
	{
		if (empty($existing)) {
			return $incoming;
		}

		$merged = $existing;
		$seen = [];

		foreach ($existing as $entry) {
			$identifier = $this->addressKey($entry);
			if ($identifier !== '') {
				$seen[$identifier] = true;
			}
		}

		foreach ($incoming as $entry) {
			$identifier = $this->addressKey($entry);
			if ($identifier === '' || isset($seen[$identifier])) {
				continue;
			}

			$seen[$identifier] = true;
			$merged[] = $entry;
		}

		return $merged;
	}

	private function normalizeAddressList($addresses): array
	{
		if ($addresses === null) {
			return [];
		}

		if (!is_array($addresses)) {
			if (is_string($addresses)) {
				$addresses = $this->splitAddressString($addresses);
			} else {
				return [];
			}
		}

		$normalized = [];
		$seen = [];

		foreach ($addresses as $address) {
			if (is_string($address)) {
				$formatted = trim($address);
				if ($formatted === '') {
					continue;
				}

				$identifier = strtolower($this->extractEmail($formatted));
				if ($identifier === '' || isset($seen[$identifier])) {
					continue;
				}

				$seen[$identifier] = true;
				$normalized[] = $formatted;
			} elseif (is_array($address)) {
				if (empty($address['email'])) {
					continue;
				}

				$email = trim((string) $address['email']);
				if ($email === '') {
					continue;
				}

				$identifier = strtolower($email);
				if (isset($seen[$identifier])) {
					continue;
				}

				$seen[$identifier] = true;

				$entry = ['email' => $email];

				if (isset($address['name']) && is_string($address['name'])) {
					$name = trim($address['name']);
					if ($name !== '') {
						$entry['name'] = $name;
					}
				}

				foreach ($address as $key => $value) {
					if (in_array($key, ['email', 'name'], true)) {
						continue;
					}
					$entry[$key] = $value;
				}

				$normalized[] = $entry;
			}
		}

		return $normalized;
	}

	private function splitAddressString(string $addresses): array
	{
		if (strpos($addresses, ',') === false) {
			return [$addresses];
		}

		$parts = array_map('trim', explode(',', $addresses));
		return array_values(array_filter($parts, static fn($part) => $part !== ''));
	}

	private function extractEmail(string $address): string
	{
		if (preg_match('/<([^>]+)>/', $address, $matches)) {
			return trim($matches[1]);
		}

		return trim($address);
	}

	/**
	 * @param mixed $entry
	 */
	private function addressKey($entry): string
	{
		if (is_array($entry)) {
			return strtolower(trim((string) ($entry['email'] ?? '')));
		}

		if (is_string($entry)) {
			return strtolower($this->extractEmail($entry));
		}

		return '';
	}
}
