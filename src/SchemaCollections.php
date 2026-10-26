<?php

declare(strict_types=1);

namespace K9o;

/** `$client->schema->collections`: see Schema. */
final class SchemaCollections
{
    public function __construct(private readonly HttpClientConfig $config) {}

    /**
     * The project's collections by name, one page at a time. `$limit` is 1 to 200 (default 50).
     *
     * @param mixed $limit  int
     * @param mixed $cursor string from the previous page
     */
    public function list(mixed $limit = null, mixed $cursor = null): Envelope
    {
        $path = UrlGrammar::project_path(ApprovalChecks::project_slug($this->config, 'schema'), 'schema/collections');
        ApprovalChecks::limit($limit);
        if ($cursor !== null && !is_string($cursor)) {
            throw ApprovalChecks::invalid('cursor must be a string', ['cursor' => $cursor]);
        }
        $query = [];
        if ($limit !== null) {
            $query['limit'] = $limit;
        }
        if ($cursor !== null) {
            $query['cursor'] = $cursor;
        }
        return HttpTransport::request($this->config, 'GET', $path, null, $query);
    }
}
