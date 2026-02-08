<?php

namespace EmailIt;

/**
 * Fluent builder for composing EmailIt messages, supporting To/CC/BCC recipients,
 * attachments, scheduling, tagging, metadata, and lifecycle helpers.
 */
class EmailBuilder
{
    private array $payload = [];
    private EmailItClient $client;

    public function __construct(EmailItClient $client)
    {
        $this->client = $client;
    }

    /**
     * Reset the current message payload.
     */
    public function reset(): self
    {
        $this->payload = [];

        return $this;
    }

    public function from(string $from): self
    {
        $this->payload['from'] = $from;

        return $this;
    }

    /**
     * Set the primary recipients. Accepts a single string email address, a list of addresses,
     * or an array of structured recipient objects per the EmailIt API specification.
     * Subsequent calls overwrite the existing list; use addTo() to append.
     *
     * @param mixed $to
     */
    public function to($to): self
    {
        $this->payload['to'] = $this->normalizeRecipients($to);

        return $this;
    }

    /**
     * Append additional primary recipients.
     *
     * @param mixed $to
     */
    public function addTo($to): self
    {
        $existing = isset($this->payload['to']) ? $this->payload['to'] : [];
        $this->payload['to'] = $this->normalizeRecipients($to, $existing);

        return $this;
    }

    /**
     * Set the CC recipients. Accepts the same formats as to().
     *
     * @param mixed $cc
     */
    public function cc($cc): self
    {
        $this->payload['cc'] = $this->normalizeRecipients($cc);

        return $this;
    }

    /**
     * Append additional CC recipients.
     *
     * @param mixed $cc
     */
    public function addCc($cc): self
    {
        $existing = isset($this->payload['cc']) ? $this->payload['cc'] : [];
        $this->payload['cc'] = $this->normalizeRecipients($cc, $existing);

        return $this;
    }

    /**
     * Set the BCC recipients. Accepts the same formats as to().
     *
     * @param mixed $bcc
     */
    public function bcc($bcc): self
    {
        $this->payload['bcc'] = $this->normalizeRecipients($bcc);

        return $this;
    }

    /**
     * Append additional BCC recipients.
     *
     * @param mixed $bcc
     */
    public function addBcc($bcc): self
    {
        $existing = isset($this->payload['bcc']) ? $this->payload['bcc'] : [];
        $this->payload['bcc'] = $this->normalizeRecipients($bcc, $existing);

        return $this;
    }

    public function replyTo(string $replyTo): self
    {
        $this->payload['reply_to'] = $replyTo;

        return $this;
    }

    public function subject(string $subject): self
    {
        $this->payload['subject'] = $subject;

        return $this;
    }

    public function html(string $html): self
    {
        $this->payload['html'] = $html;

        return $this;
    }

    public function text(string $text): self
    {
        $this->payload['text'] = $text;

        return $this;
    }

    /**
     * Schedule the email for a specific ISO8601 timestamp.
     *
     * @param mixed $dateTime String timestamp or DateTimeInterface instance.
     */
    public function scheduledAt($dateTime): self
    {
        if ($dateTime instanceof \DateTimeInterface) {
            $dateTime = $dateTime->format(DATE_ATOM);
        }

        if (!is_string($dateTime)) {
            throw new EmailItException('scheduledAt expects a string or DateTimeInterface instance.');
        }

        $this->payload['scheduled_at'] = $dateTime;

        return $this;
    }

    public function metadata(array $metadata): self
    {
        $this->payload['metadata'] = $metadata;

        return $this;
    }

    public function addMetadata(string $key, $value): self
    {
        if (!isset($this->payload['metadata']) || !is_array($this->payload['metadata'])) {
            $this->payload['metadata'] = [];
        }

        $this->payload['metadata'][$key] = $value;

        return $this;
    }

    public function tags(array $tags): self
    {
        $existing = isset($this->payload['tags']) && is_array($this->payload['tags']) ? $this->payload['tags'] : [];
        $this->payload['tags'] = array_values(array_unique(array_merge($existing, $tags)));

        return $this;
    }

    public function addTag(string $tag): self
    {
        return $this->tags([$tag]);
    }

    /**
     * Enable or disable load/click tracking.
     */
    public function tracking(bool $loads = true, bool $clicks = true): self
    {
        $this->payload['tracking'] = [
            'loads' => (bool) $loads,
            'clicks' => (bool) $clicks,
        ];

        return $this;
    }

    /**
     * Assign custom tracking options directly.
     */
    public function trackingOptions(array $options): self
    {
        $defaults = ['loads' => false, 'clicks' => false];
        $this->payload['tracking'] = array_merge($defaults, array_intersect_key($options, $defaults));

        return $this;
    }

    public function addAttachment(string $filename, string $content, string $contentType): self
    {
        if (!isset($this->payload['attachments'])) {
            $this->payload['attachments'] = [];
        }

        $this->payload['attachments'][] = [
            'filename' => $filename,
            'content' => $content,
            'content_type' => $contentType,
        ];

        return $this;
    }

    public function addHeader(string $name, string $value): self
    {
        if (!isset($this->payload['headers'])) {
            $this->payload['headers'] = [];
        }

        $this->payload['headers'][$name] = $value;

        return $this;
    }

    /**
     * Dispatch the email via POST /emails.
     */
    public function send(): array
    {
        $payload = $this->preparePayload($this->payload);

        return $this->client->sendEmail($payload);
    }

    /**
     * Retrieve an email resource by ID.
     */
    public function get(string $emailId): array
    {
        return $this->client->getEmail($emailId);
    }

    /**
     * Update a scheduled email. If no payload is supplied, the builder payload is used.
     */
    public function update(string $emailId, array $payload = []): array
    {
        $data = !empty($payload) ? $payload : $this->payload;
        $prepared = $this->preparePayload($data);

        return $this->client->updateEmail($emailId, $prepared);
    }

    /**
     * Cancel a scheduled email.
     */
    public function cancel(string $emailId): array
    {
        return $this->client->cancelEmail($emailId);
    }

    /**
     * Retry a failed email.
     */
    public function retry(string $emailId): array
    {
        return $this->client->retryEmail($emailId);
    }

    /**
     * Export the current message payload (normalized) without sending.
     */
    public function toArray(): array
    {
        return $this->preparePayload($this->payload);
    }

    private function preparePayload(array $payload): array
    {
        foreach (['to', 'cc', 'bcc'] as $field) {
            if (array_key_exists($field, $payload)) {
                $normalizedRecipients = $this->normalizeRecipients($payload[$field]);

                if (!empty($normalizedRecipients)) {
                    $payload[$field] = $normalizedRecipients;
                } else {
                    unset($payload[$field]);
                }
            }
        }

        if (isset($payload['tracking'])) {
            $payload['tracking'] = $this->normalizeTracking($payload['tracking']);
        }

        if (isset($payload['tags']) && is_array($payload['tags'])) {
            $payload['tags'] = array_values(array_unique($payload['tags']));
        }

        if (isset($payload['scheduled_at']) && $payload['scheduled_at'] instanceof \DateTimeInterface) {
            $payload['scheduled_at'] = $payload['scheduled_at']->format(DATE_ATOM);
        }

        return $payload;
    }

    private function normalizeTracking($tracking): array
    {
        if (!is_array($tracking)) {
            $enabled = (bool) $tracking;

            return [
                'loads' => $enabled,
                'clicks' => $enabled,
            ];
        }

        return [
            'loads' => isset($tracking['loads']) ? (bool) $tracking['loads'] : false,
            'clicks' => isset($tracking['clicks']) ? (bool) $tracking['clicks'] : false,
        ];
    }

    private function normalizeRecipients($recipients, $existing = []): array
    {
        $merged = array_merge(
            $this->recipientList($existing),
            $this->recipientList($recipients)
        );

        $normalized = [];

        foreach ($merged as $recipient) {
            if (is_array($recipient)) {
                if (!$this->containsArray($normalized, $recipient)) {
                    $normalized[] = $recipient;
                }
                continue;
            }

            if (!is_string($recipient)) {
                continue;
            }

            $trimmed = trim($recipient);

            if ($trimmed === '') {
                continue;
            }

            if (!in_array($trimmed, $normalized, true)) {
                $normalized[] = $trimmed;
            }
        }

        return $normalized;
    }

    private function recipientList($value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_array($value)) {
            if ($this->isAssoc($value)) {
                return [$value];
            }

            return array_values($value);
        }

        $value = trim((string) $value);

        if ($value === '') {
            return [];
        }

        return [$value];
    }

    private function containsArray(array $haystack, array $needle): bool
    {
        foreach ($haystack as $item) {
            if (is_array($item) && $item == $needle) {
                return true;
            }
        }

        return false;
    }

    private function isAssoc(array $array): bool
    {
        return array_keys($array) !== range(0, count($array) - 1);
    }
}
