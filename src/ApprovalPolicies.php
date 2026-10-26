<?php

declare(strict_types=1);

namespace K9o;

/**
 * Approval policies — $client->approval_policies.
 *
 *   list()                                              GET    /@<slug>/approval-policies
 *   create($collection, $operation, $action, $predicate)  POST   /@<slug>/approval-policies
 *   delete($id)                                         DELETE /@<slug>/approval-policies/<id>
 *
 * A bad argument throws KError K9O_INVALID_FIELD_VALUE before any request is sent.
 */
final class ApprovalPolicies
{
    private const COLLECTION_RE = '/^[a-zA-Z_][a-zA-Z0-9_]{0,62}$/D';

    public function __construct(private readonly HttpClientConfig $config) {}

    private function path(string $tail = ''): string
    {
        return UrlGrammar::project_path(ApprovalChecks::project_slug($this->config, 'approval_policies'), 'approval-policies' . $tail);
    }

    /** All policies, oldest first. The first matching policy for a write decides. */
    public function list(): Envelope
    {
        return HttpTransport::request($this->config, 'GET', $this->path());
    }

    /**
     * Create a policy. Identical policies are not de-duplicated.
     *
     * @param string $operation insert, update or delete
     * @param string $action    auto_approve or queue
     * @param array<string, mixed> $predicate a non-empty predicate array such as ['gt' => ['change.row.amount', 1000]]
     */
    public function create(mixed $collection, mixed $operation, mixed $action, mixed $predicate): Envelope
    {
        if (!is_string($collection) || preg_match(self::COLLECTION_RE, $collection) !== 1) {
            throw ApprovalChecks::invalid('collection must match ^[a-zA-Z_][a-zA-Z0-9_]{0,62}$', ['collection' => $collection]);
        }
        if (!in_array($operation, ['insert', 'update', 'delete'], true)) {
            throw ApprovalChecks::invalid('operation must be "insert", "update" or "delete"', ['operation' => $operation]);
        }
        if (!in_array($action, ['auto_approve', 'queue'], true)) {
            throw ApprovalChecks::invalid('action must be "auto_approve" or "queue"', ['action' => $action]);
        }
        $body = [
            'collection' => $collection,
            'operation' => $operation,
            'action' => $action,
            'predicate' => ApprovalChecks::predicate($predicate, 'predicate'),
        ];
        return HttpTransport::request($this->config, 'POST', $this->path(), $body);
    }

    /** Delete a policy; the answer is `['id' => ...]`. */
    public function delete(mixed $id): Envelope
    {
        return HttpTransport::request($this->config, 'DELETE', $this->path('/' . ApprovalChecks::id($id, 'policy')));
    }
}
