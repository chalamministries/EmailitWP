<?php

namespace EmailIt;

class AudienceManager
{
    private EmailItClient $client;

    public function __construct(EmailItClient $client)
    {
        $this->client = $client;
    }

    /**
     * List audiences with support for pagination and optional keyword search.
     */
    public function list(int $page = 1, int $limit = 25, ?string $search = null): array
    {
        $params = [
            'page' => $page,
            'limit' => $limit,
        ];

        if ($search !== null) {
            $search = trim($search);
            if ($search !== '') {
                $params['search'] = $search;
            }
        }

        return $this->client->request('GET', '/audiences', $params);
    }

    /**
     * Create a new audience.
     *
     * @throws EmailItException
     */
    public function create(string $name, array $attributes = []): array
    {
        $name = trim($name);

        if ($name === '') {
            throw new EmailItException('Audience name cannot be empty');
        }

        $payload = ['name' => $name];
        $payload = array_merge($payload, $this->filterAudienceAttributes($attributes));

        return $this->client->request('POST', '/audiences', $payload);
    }

    /**
     * Retrieve an audience by identifier.
     */
    public function get(string $audienceId): array
    {
        return $this->client->request('GET', "/audiences/{$audienceId}");
    }

    /**
     * Update an existing audience.
     *
     * @throws EmailItException
     */
    public function update(string $audienceId, array $attributes): array
    {
        $payload = $this->filterAudienceAttributes($attributes);

        if (empty($payload)) {
            throw new EmailItException('Audience update payload cannot be empty');
        }

        return $this->client->request('POST', "/audiences/{$audienceId}", $payload);
    }

    /**
     * Delete an audience by identifier.
     */
    public function delete(string $audienceId): bool
    {
        $this->client->request('DELETE', "/audiences/{$audienceId}");

        return true;
    }

    /**
     * Access subscriber operations for a given audience.
     */
    public function subscribers(string $audienceId): AudienceSubscriberManager
    {
        return new AudienceSubscriberManager($this->client, $audienceId);
    }

    /**
     * @deprecated Use subscribers($audienceId)->add(...) instead.
     */
    public function subscribe(
        string $audienceId,
        string $email,
        string $firstName,
        string $lastName,
        array $customFields = []
    ): array {
        trigger_error('AudienceManager::subscribe() is deprecated. Use AudienceManager::subscribers($audienceId)->add(...) instead.', E_USER_DEPRECATED);

        $attributes = [];

        $firstName = trim($firstName);
        if ($firstName !== '') {
            $attributes['first_name'] = $firstName;
        }

        $lastName = trim($lastName);
        if ($lastName !== '') {
            $attributes['last_name'] = $lastName;
        }

        if (!empty($customFields)) {
            $attributes['custom_fields'] = $customFields;
        }

        return $this->subscribers($audienceId)->add($email, $attributes);
    }

    private function filterAudienceAttributes(array $attributes): array
    {
        $payload = [];

        foreach ($attributes as $key => $value) {
            switch ($key) {
                case 'name':
                case 'description':
                    $value = trim((string) $value);
                    if ($value === '') {
                        continue 2;
                    }
                    $payload[$key] = $value;
                    break;
                case 'tags':
                    if (is_array($value)) {
                        $tags = array_values(array_unique(array_filter($value, static function ($tag) {
                            return is_string($tag) && trim($tag) !== '';
                        })));
                        if (!empty($tags)) {
                            $payload[$key] = $tags;
                        }
                    }
                    break;
                default:
                    $payload[$key] = $value;
            }
        }

        return $payload;
    }
}
