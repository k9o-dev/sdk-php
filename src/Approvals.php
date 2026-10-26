<?php

declare(strict_types=1);

namespace K9o;

/**
 * Pending-change queue — $client->approvals.
 *
 *   list($status, $limit)                GET  /@<slug>/pending-approvals
 *   get($id)                             GET  /@<slug>/pending-approvals/<id>
 *   approve($id, $reason)                POST /@<slug>/pending-approvals/<id>/approve
 *   reject($id, $reason)                 POST /@<slug>/pending-approvals/<id>/reject
 *   bulk($action, $criteria, $reason)    POST /@<slug>/pending-approvals/bulk
 *
 * `list` is not paginated: it returns the newest `$limit` changes (1 to 200, default 50). `approve` can answer 200
 * with `status` still `pending_approval`: the approval is recorded and more approvers are needed. `bulk` skips rows
 * that fail without a report, so compare `affectedIds` with what you intended.
 * A bad argument throws KError K9O_INVALID_FIELD_VALUE before any request is sent.
 */
final class Approvals
{
    public function __construct(private readonly HttpClientConfig $config) {}

    private function path(string $tail = ''): string
    {
        return UrlGrammar::project_path(ApprovalChecks::project_slug($this->config, 'approvals'), 'pending-approvals' . $tail);
    }

    /**
     * Pending changes, newest first. Only `$limit` caps the result.
     *
     * @param string|null $status one of pending_approval, approved, rejected, expired, redeemed
     * @param mixed $limit        1 to 200 (int)
     */
    public function list(?string $status = null, mixed $limit = null): Envelope
    {
        ApprovalChecks::limit($limit);
        $query = [];
        if ($status !== null) {
            $query['status'] = $status;
        }
        if ($limit !== null) {
            $query['limit'] = $limit;
        }
        return HttpTransport::request($this->config, 'GET', $this->path(), null, $query);
    }

    /** One pending change. Members who are not queue admins can read only their own. */
    public function get(mixed $id): Envelope
    {
        return HttpTransport::request($this->config, 'GET', $this->path('/' . ApprovalChecks::id($id, 'approval')));
    }

    /** Approve a pending change. Read `status` on the answer: `pending_approval` means more approvers are needed. */
    public function approve(mixed $id, ?string $reason = null): Envelope
    {
        $seg = ApprovalChecks::id($id, 'approval');
        return HttpTransport::request($this->config, 'POST', $this->path("/$seg/approve"), $reason === null ? new \stdClass() : ['reason' => $reason]);
    }

    /** Reject a pending change. `$reason` is required and must not be empty. */
    public function reject(mixed $id, mixed $reason): Envelope
    {
        $seg = ApprovalChecks::id($id, 'approval');
        return HttpTransport::request($this->config, 'POST', $this->path("/$seg/reject"), ['reason' => ApprovalChecks::reason($reason)]);
    }

    /**
     * Approve or reject every pending change matching `$criteria` (a predicate array). `$reason` is required to
     * reject. Rows that fail are skipped without a report: compare `affectedIds` with what you intended.
     *
     * @param mixed $criteria
     */
    public function bulk(mixed $action, mixed $criteria, mixed $reason = null): Envelope
    {
        if ($action !== 'approve' && $action !== 'reject') {
            throw ApprovalChecks::invalid('action must be "approve" or "reject"', ['action' => $action]);
        }
        $body = ['action' => $action, 'criteria' => ApprovalChecks::predicate($criteria, 'criteria')];
        if ($action === 'reject') {
            $body['reason'] = ApprovalChecks::reason($reason);
        } elseif ($reason !== null) {
            $body['reason'] = $reason;
        }
        return HttpTransport::request($this->config, 'POST', $this->path('/bulk'), $body);
    }
}
