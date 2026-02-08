<?php

namespace EmailIt;

class SuppressionManager
{
    private EmailItClient $client;

    public function __construct(EmailItClient $client)
    {
        $this->client = $client;
    }

    /**
     * List suppression entries.
     *
     * @param int $perPage Number of suppressions per page (default: 25)
     * @param int $page Page number
     */
    public function list(int $perPage = 25, int $page = 1): array
    {
        $params = [
            'page' => $page,
            'limit' => $perPage,
        ];

        return $this->client->request('GET', '/suppressions', $params);
    }

    /**
     * Create a new suppression entry.
     *
     * Supported attributes: email, type, reason, keep_until
     *
     * @throws EmailItException
     */
    public function create(string $email, string $type, array $attributes = []): array
    {
        $email = trim($email);
        $type = trim($type);

        if ($email === '') {
            throw new EmailItException('Suppression email cannot be empty');
        }

        if ($type === '') {
            throw new EmailItException('Suppression type cannot be empty');
        }

        $payload = array_merge($attributes, [
            'email' => $email,
            'type' => $type,
        ]);

        $payload = $this->normalizeAttributes($payload);

        return $this->client->request('POST', '/suppressions', $payload);
    }

    /**
     * Retrieve a suppression entry by ID.
     */
    public function get(string $suppressionId): array
    {
        return $this->client->request('GET', "/suppressions/{$suppressionId}");
    }

    /**
     * Update a suppression entry.
     *
     * Supported attributes: reason, keep_until
     *
     * @throws EmailItException
     */
    public function update(string $suppressionId, array $attributes): array
    {
        $payload = $this->normalizeAttributes($attributes);

        $payload = array_intersect_key($payload, array_flip(['reason', 'keep_until']));

        if (empty($payload)) {
            throw new EmailItException('Suppression update payload cannot be empty');
        }

        return $this->client->request('POST', "/suppressions/{$suppressionId}", $payload);
    }

    /**
     * Delete a suppression entry.
     */
    public function delete(string $suppressionId): bool
    {
        $this->client->request('DELETE', "/suppressions/{$suppressionId}");

        return true;
    }

    private function normalizeAttributes(array $attributes): array
    {
        $payload = [];

        foreach ($attributes as $key => $value) {
            switch ($key) {
                case 'email':
                case 'type':
                case 'reason':
                    $value = trim((string) $value);
                    if ($value === '') {
                        continue 2;
                    }
                    $payload[$key] = $value;
                    break;
                case 'keep_until':
                    if ($value instanceof \DateTimeInterface) {
                        $payload[$key] = $value->format(DATE_ATOM);
                        break;
                    }

                    if ($value === null || $value === '') {
                        $payload[$key] = null;
                        break;
                    }

                    $payload[$key] = $value;
                    break;
                default:
                    $payload[$key] = $value;
            }
        }

        return $payload;
    }
}
