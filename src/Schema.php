<?php

declare(strict_types=1);

namespace K9o;

/**
 * Schema — $client->schema.
 *
 *   collections->list($limit, $cursor)    GET /@<slug>/schema/collections
 *
 * The list is cursor-paginated and ordered by collection name: pass `$response->meta->cursor->next` of one page as
 * `$cursor` of the next, until it is null.
 */
final class Schema
{
    public readonly SchemaCollections $collections;

    public function __construct(HttpClientConfig $config)
    {
        $this->collections = new SchemaCollections($config);
    }
}
