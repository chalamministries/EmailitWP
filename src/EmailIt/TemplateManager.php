<?php

namespace EmailIt;

class TemplateManager
{
    private EmailItClient $client;

    public function __construct(EmailItClient $client)
    {
        $this->client = $client;
    }

    /**
     * List templates with pagination, filters, and sorting.
     *
     * Supported filter keys: name, alias, editor. Sorting keys: sort, order.
     */
    public function list(int $perPage = 25, int $page = 1, array $filters = []): array
    {
        $params = [
            'per_page' => $perPage,
            'page' => $page,
        ];

        $filterPayload = [];

        foreach (['name', 'alias', 'editor'] as $key) {
            if (!array_key_exists($key, $filters)) {
                continue;
            }

            $value = trim((string) $filters[$key]);
            if ($value !== '') {
                $filterPayload[$key] = $value;
            }
        }

        if (!empty($filterPayload)) {
            $params['filter'] = $filterPayload;
        }

        foreach (['sort', 'order'] as $key) {
            if (!array_key_exists($key, $filters)) {
                continue;
            }

            $value = trim((string) $filters[$key]);
            if ($value !== '') {
                $params[$key] = $value;
            }
        }

        return $this->client->request('GET', '/templates', $params);
    }

    /**
     * Create a new template.
     */
    public function create(array $attributes): array
    {
        $payload = $this->filterTemplateAttributes($attributes, true);

        return $this->client->request('POST', '/templates', $payload);
    }

    /**
     * Retrieve a template by ID.
     */
    public function get(string $templateId): array
    {
        return $this->client->request('GET', "/templates/{$templateId}");
    }

    /**
     * Update an existing template.
     *
     * @throws EmailItException
     */
    public function update(string $templateId, array $attributes): array
    {
        $payload = $this->filterTemplateAttributes($attributes, false);

        if (empty($payload)) {
            throw new EmailItException('Template update payload cannot be empty');
        }

        return $this->client->request('POST', "/templates/{$templateId}", $payload);
    }

    /**
     * Publish a template version.
     */
    public function publish(string $templateId, array $payload = []): array
    {
        return $this->client->request('POST', "/templates/{$templateId}/publish", $payload);
    }

    /**
     * Delete a template.
     */
    public function delete(string $templateId): bool
    {
        $this->client->request('DELETE', "/templates/{$templateId}");

        return true;
    }

    private function filterTemplateAttributes(array $attributes, bool $requireName): array
    {
        $payload = [];

        foreach ($attributes as $key => $value) {
            switch ($key) {
                case 'name':
                case 'alias':
                case 'from':
                case 'subject':
                case 'editor':
                    $value = trim((string) $value);
                    if ($value === '') {
                        if ($requireName && $key === 'name') {
                            throw new EmailItException('Template name cannot be empty');
                        }
                        continue 2;
                    }
                    $payload[$key] = $value;
                    break;
                case 'reply_to':
                    if (is_string($value)) {
                        $value = trim($value);
                        if ($value === '') {
                            continue 2;
                        }
                    }
                    $payload[$key] = $value;
                    break;
                case 'html':
                case 'text':
                case 'source':
                    if ($value === null) {
                        continue 2;
                    }
                    $payload[$key] = $value;
                    break;
                default:
                    $payload[$key] = $value;
            }
        }

        if ($requireName && !isset($payload['name'])) {
            throw new EmailItException('Template name cannot be empty');
        }

        return $payload;
    }
}
