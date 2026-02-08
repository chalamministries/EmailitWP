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
     * Retrieve subscribers for the current audience.
     */
    public function list(int $page = 1, int $limit = 25, ?bool $subscribed = null): array
    {
        $params = [
            'page' => $page,
            'limit' => $limit,
        ];

        if ($subscribed !== null) {
            $params['subscribed'] = $subscribed;
        }

        return $this->client->request('GET', $this->endpoint(), $params);
    }

    /**
     * Add a new subscriber to the audience.
     *
     * @throws EmailItException
     */
    public function add(string $email, array $attributes = []): array
    {
        $payload = $this->buildPayload($attributes, $email);

        if (!isset($payload['email'])) {
            throw new EmailItException('Subscriber email cannot be empty');
        }

        return $this->client->request('POST', $this->endpoint(), $payload);
    }

    /**
     * Retrieve an individual subscriber.
     */
    public function get(string $subscriberId): array
    {
        return $this->client->request('GET', $this->endpoint($subscriberId));
    }

    /**
     * Update an existing subscriber.
     *
     * @throws EmailItException
     */
    public function update(string $subscriberId, array $attributes): array
    {
        $payload = $this->buildPayload($attributes);

        if (empty($payload)) {
            throw new EmailItException('Subscriber update payload cannot be empty');
        }

        return $this->client->request('POST', $this->endpoint($subscriberId), $payload);
    }

    /**
     * Remove a subscriber from the audience.
     */
    public function delete(string $subscriberId): bool
    {
        $this->client->request('DELETE', $this->endpoint($subscriberId));

        return true;
    }

    /**
     * Convenience helper to mark a subscriber as unsubscribed without deleting records.
     */
    public function unsubscribe(string $subscriberId): array
    {
        return $this->update($subscriberId, ['subscribed' => false]);
    }

    private function endpoint(string $suffix = ''): string
    {
        $base = "/audiences/{$this->audienceId}/subscribers";

        if ($suffix !== '') {
            $base .= '/' . $suffix;
        }

        return $base;
    }

    private function buildPayload(array $attributes, ?string $email = null): array
    {
        $payload = [];

        if ($email !== null) {
            $email = trim($email);
            if ($email !== '') {
                $payload['email'] = $email;
            }
        }

        foreach ($attributes as $key => $value) {
            switch ($key) {
                case 'email':
                    $value = trim((string) $value);
                    if ($value === '') {
                        continue 2;
                    }
                    $payload['email'] = $value;
                    break;
                case 'first_name':
                case 'last_name':
                    $value = trim((string) $value);
                    if ($value === '') {
                        continue 2;
                    }
                    $payload[$key] = $value;
                    break;
                case 'custom_fields':
                    if (is_array($value) && !empty($value)) {
                        $payload[$key] = $value;
                    }
                    break;
                case 'subscribed':
                    $payload[$key] = (bool) $value;
                    break;
                default:
                    $payload[$key] = $value;
            }
        }

        return $payload;
    }
}
