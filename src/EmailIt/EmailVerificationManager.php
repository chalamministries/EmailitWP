<?php

namespace EmailIt;

class EmailVerificationManager
{
    private EmailItClient $client;

    public function __construct(EmailItClient $client)
    {
        $this->client = $client;
    }

    /**
     * Verify a single email address.
     *
     * Supported attributes: email, mode
     *
     * @throws EmailItException
     */
    public function verify(string $email, string $mode = 'full', array $attributes = []): array
    {
        $email = trim($email);
        $mode = trim($mode);

        if ($email === '') {
            throw new EmailItException('Verification email cannot be empty');
        }

        if ($mode === '') {
            $mode = 'full';
        }

        $payload = array_merge($attributes, [
            'email' => $email,
            'mode' => $mode,
        ]);

        return $this->client->request('POST', '/email-verifications', $payload);
    }

    /**
     * Create a new email verification list.
     *
     * @throws EmailItException
     */
    public function createList(string $name, array $emails): array
    {
        $name = trim($name);

        if ($name === '') {
            throw new EmailItException('Verification list name cannot be empty');
        }

        if (empty($emails)) {
            throw new EmailItException('Verification list emails cannot be empty');
        }

        return $this->client->request('POST', '/email-verification-lists', [
            'name' => $name,
            'emails' => array_values($emails),
        ]);
    }

    /**
     * List email verification lists.
     */
    public function listLists(int $perPage = 25, int $page = 1): array
    {
        $params = [
            'page' => $page,
            'limit' => $perPage,
        ];

        return $this->client->request('GET', '/email-verification-lists', $params);
    }

    /**
     * Retrieve a verification list by ID.
     */
    public function getList(string $listId): array
    {
        return $this->client->request('GET', "/email-verification-lists/{$listId}");
    }

    /**
     * Retrieve verification results for a list.
     */
    public function getListResults(string $listId, int $perPage = 50, int $page = 1): array
    {
        $params = [
            'page' => $page,
            'limit' => $perPage,
        ];

        return $this->client->request('GET', "/email-verification-lists/{$listId}/results", $params);
    }

    /**
     * Export verification results as raw XLSX bytes.
     */
    public function exportList(string $listId): string
    {
        return $this->client->requestRaw('GET', "/email-verification-lists/{$listId}/export");
    }
}
