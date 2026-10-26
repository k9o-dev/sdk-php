# k9o/sdk

Client for Kilimanjaro. Read and write your data, store files, manage policies and migrations,
deploy and invoke agents or functions, and publish to and subscribe to streams and realtime events.

## Contents

- [Links](#links)
- [Install](#install)
- [Requirements](#requirements)
- [Quick start](#quick-start)
- [Query data](#query-data)
- [Query with SQL](#query-with-sql)
- [Search](#search)
- [Update and delete](#update-and-delete)
- [Sign in an end user](#sign-in-an-end-user)
- [Streams](#streams)
- [Approvals](#approvals)
- [Approval policies](#approval-policies)
- [Schema collections](#schema-collections)
- [Read responses](#read-responses)
- [Handle errors](#handle-errors)
- [Regional hosts](#regional-hosts)
- [Appendix](#appendix)
  - [About Kilimanjaro](#about-kilimanjaro)

## Links

- Website: <https://kilimanjaro.dev>
- Documentation / Developer Hub: <https://docs.kilimanjaro.dev>
- Platform API: <https://api.k9o.io>
- Platform Console: <https://console.k9o.io>

## Install

```bash
composer require k9o/sdk:1.4.0
```

## Requirements

- PHP 8.5.12 or later
- The `json` and `curl` extensions
- No other dependencies

## Quick start

The examples use a `tasks` collection with `title` and `done` fields.
It lives in a project called `tasks-app` that belongs to the organization `acme`.

Create a client from your organization slug, project slug and API key.
The default platform host is used.

```php
<?php
require 'vendor/autoload.php';

use K9o\K9oClient;

$api_key = getenv('K9O_API_KEY');

$k9o = K9oClient::create([
    'org_slug'     => 'acme',
    'project_slug' => 'tasks-app',
    'api_key'      => $api_key,
]);
```

Add a task:

```php
$new_task = ['title' => 'Write the README', 'done' => false];
$created = $k9o->from('tasks')->insert($new_task);
$task = $created->data;

echo "Output: Created task {$task['id']}: {$task['title']}\n";
```

```text
Output: Created task tsk_1: Write the README
```

For an internal or air-gapped host, see [client options](#client-options).

## Query data

Start with `from()`, add filters, then call `execute()`.

```php
$response = $k9o->from('tasks')->select()->eq('done', false)->limit(10)->execute();
$tasks = $response->data;

foreach ($tasks as $task) {
    echo "Output: - {$task['title']}\n";
}
```

```text
Output: - Write the README
Output: - Ship it
```

Common filters:

| Filter | Meaning |
|---|---|
| `eq($column, $value)` | Equal to |
| `gt($column, $value)` | Greater than |
| `in($column, $values)` | Is one of a list |
| `like($column, $pattern)` | Pattern match |

See the [full list of query methods](#data-queries).

## Query with SQL

`$k9o->query($sql, $params)` runs one read-only SQL statement and returns the rows in `data`.
It is read-only (a write is refused with `K9O_RAW_SQL_READ_ONLY`; change data through `from()`), takes one statement per call,
applies row-level security, accepts at most 100000 characters of SQL and 1000 parameters, and needs the project editor role.
A reference to `dataset.<name>` reads an analytics dataset.

Pass a list to bind `$1`, `$2`, and so on, or an array with string keys to bind `:name` placeholders. Values are bound by the database and can be a string, number, boolean or `null`; serialise anything else (for example as JSON text) first.

```php
$rows = $k9o->query("SELECT data->>'title' AS title FROM posts WHERE data->>'status' = :status", ['status' => 'published']);
echo "Output: {$rows->data[0]['title']}\n";

$same = $k9o->query("SELECT count(*) AS n FROM posts WHERE data->>'status' = \$1", ['published']);
echo "Output: {$same->data[0]['n']}\n";
```

```text
Output: Hello
Output: 12
```

A `:name` with no key in the array throws `KError` with code `K9O_INVALID_FIELD_VALUE` before any request is sent.

## Search

`$k9o->search($collection, column: ..., q: ...)` runs a full-text search over one text column, best matches first.
`language` defaults to `english`; `type` is `plain` (default), `phrase` or `websearch`. `limit` and `cursor` page the result.

```php
$found = $k9o->search('articles', column: 'body', q: 'climate resilience', limit: 5);
echo "Output: " . count($found->data) . " {$found->data[0]['title']}\n";
```

```text
Output: 5 Adapting to heat
```

The same search is available on a query as `text_search($column, $query, $language = 'english', $type = 'plain')`.
`vector_search($column, $embedding, $metric = 'cosine', $limit = null)` finds the closest rows to an embedding, closest first;
`$metric` is `cosine`, `euclidean` or `dot`, and `$limit` is 1 to 1000.

```php
$vector = $k9o->ai->embed('heat waves')->data['embeddings'][0];
$near = $k9o->from('articles')->select()->vector_search('embedding', $vector, limit: 3)->execute();
echo "Output: " . count($near->data) . "\n";
```

```text
Output: 3
```

## Update and delete

Updates and deletes target one row, so they need `->eq('id', ...)`.

```php
$task_id = 'tsk_1';
$k9o->from('tasks')->update(['done' => true])->eq('id', $task_id)->execute();
$k9o->from('tasks')->delete()->eq('id', $task_id)->execute();
```

## Sign in an end user

Gate calls need `project_slug` on the client.

```php
$session = $k9o->gate->sign_in(email: 'ada@example.com', password: 'a-strong-password');
$user = $session->data['user'];

echo "Output: Signed in as {$user['email']} ({$user['id']})\n";
```

```text
Output: Signed in as ada@example.com (usr_123)
```

See all [Gate methods](#gate).

## Streams

Publish events to a stream and tail them live. Publishing needs the project admin role; subscribing and replaying need admin or auditor.
`subscribe` reconnects from the last sequence when the server closes the connection, so no event is repeated or skipped.

```php
$stream = $k9o->streams->create('Orders feed', 1073741824);
$slug = $stream->data['slug'];
echo "Output: " . $slug . "\n";
$k9o->streams->publish($slug, ['order' => 1001], 'eu.new');

foreach ($k9o->streams->subscribe($slug) as $event) {
    echo "Output: " . $event['sequence'] . " " . $event['data']['order'] . "\n";
    break;
}
```

```text
Output: orders-feed
Output: 1 1001
```

`replay` reads a bounded range of past events.
Failures raise `KError`: `K9O_STREAM_UNAVAILABLE` while streams are not available, and `K9O_STREAM_INVALID` for a bad subject.

## Approvals

Writes that need approval wait in a queue for a second person. `$k9o->approvals` reads that queue and decides its changes.
Project admins and auditors see every change; other members see only the ones they proposed.

```php
$queue = $k9o->approvals->list('pending_approval', 20);
$change = $queue->data[0];

echo "Output: " . $change['id'] . " " . $change['operation'] . " " . $change['collection'] . "\n";

$decided = $k9o->approvals->approve($change['id'], 'checked the amount');

echo "Output: " . $decided->data['status'] . "\n";
```

```text
Output: apr_01j9x3 update orders
Output: redeemed
```

- A change is `pending_approval`, `approved`, `rejected`, `expired` or `redeemed` (applied). An `expired` change can still read `pending_approval` until someone decides it.
- `approve` can answer 200 with `status` still `pending_approval`. That means your approval is recorded and more approvers are needed; the change is not applied yet.
- `reject($id, $reason)` needs a non-empty `reason`; the SDK checks it before sending.
- `list` is not paginated: it returns the newest `limit` changes (1 to 200, default 50). `get($id)` reads one.
- Deciding needs the queue-admin role (project admin or auditor).

Decide many at once with `bulk`. `criteria` is a predicate over each pending change (see [Approval policies](#approval-policies)):

```php
$run = $k9o->approvals->bulk(
    'reject',
    ['eq' => ['change.table', 'orders']],
    'stale',
);

echo "Output: " . $run->data['affectedCount'] . " " . implode(',', $run->data['affectedIds']) . "\n";
```

```text
Output: 2 apr_01j9x3,apr_01j9x4
```

- A row that fails (a conflict, an apply error) is skipped silently and left out of `affectedIds`. Compare `affectedIds` with the set you intended.
- `reject` needs a `reason`; `criteria` must be a predicate array. `attestationId` identifies the batch, even when nothing matched.
- It considers the newest 10000 pending changes. Bulk approve is not guaranteed to apply every matched row.

## Approval policies

A policy decides what happens to writes on one collection that match a predicate: `queue` them for approval, or `auto_approve` them.
For a write the first matching policy, oldest first, decides. Policies are evaluated for writes sent with the `requireApproval=true` query parameter.

```php
$policy = $k9o->approval_policies->create(
    'orders',
    'update',
    'queue',
    ['gt' => ['change.row.amount', 1000]],
);

echo "Output: " . $policy->data['id'] . " " . $policy->data['action'] . "\n";

$removed = $k9o->approval_policies->delete($policy->data['id']);

echo "Output: " . $removed->data['id'] . "\n";
```

```text
Output: apo_01j9x5 queue
Output: apo_01j9x5
```

- `operation` is `insert`, `update` or `delete`; `action` is `auto_approve` or `queue`.
- `collection` matches `^[a-zA-Z_][a-zA-Z0-9_]{0,62}$` and `predicate` is a non-empty array with string keys. The SDK checks both before sending.
- A predicate has one operator key: `and`, `or`, `not`, `eq`, `neq`, `gt`, `gte`, `lt`, `lte`, `in`, `not_in`, `in_cidr`, `not_in_cidr` or `contains`.
  `and` and `or` take a list of predicates, `not` takes one, and a comparison takes `[left, right]`.
- Operands start with `maker.` or `change.` (for example `change.row.amount`, `change.table`, `change.operation`, `maker.id`).
- `list()` returns all policies. Identical policies are not de-duplicated. Creating and deleting need project admin or author.

## Schema collections

`$k9o->schema->collections->list` lists the project's collections by name, with their size and last migration.

```php
$page = $k9o->schema->collections->list(2);

foreach ($page->data as $collection) {
    echo "Output: " . $collection['name'] . " " . $collection['rowCount'] . " rows, " . $collection['columnCount'] . " columns\n";
}
echo "Output: next " . $page->meta->cursor->next . "\n";
```

```text
Output: orders 120 rows, 8 columns
Output: tasks 14 rows, 4 columns
Output: next dGFza3M
```

- Pass `meta.cursor.next` as `cursor` for the next page; it is `null` on the last page. `limit` is 1 to 200 (default 50).
- A row is `name`, `rowCount`, `rowCountIsExact`, `columnCount`, `indexCount`, `lastMigrationSlug` and `lastMigrationAppliedAt`.
- `rowCount` is a live count that skips soft-deleted rows and ignores row-level security. Without an `org_id` claim on the token, `meta.warnings` says so.
- Project admins, auditors, editors, members and viewers can read it.

## Read responses

Every successful call returns an `Envelope`. It has a readonly `data` property and a `meta` property.

```php
$cursor = $response->meta->cursor;
if ($cursor !== null) {
    echo "Output: Next page cursor: {$cursor->next}\n";
}
```

```text
Output: Next page cursor: cur_abc
```

Pass the cursor to `->cursor($next)` on the next query. See the [response fields](#response-fields).

## Handle errors

Every failure throws `K9o\KError`. Read the K9O code with `$e->code`.

```php
use K9o\KError;

try {
    $profile = $k9o->gate->whoami();
} catch (KError $e) {
    echo "Output: Code: {$e->code}\n";
    echo "Output: Message: {$e->getMessage()}\n";
    echo "Output: HTTP status: {$e->status}\n";
}
```

```text
Output: Code: K9O_PROJECT_NOT_FOUND
Output: Message: Project not found
Output: HTTP status: 404
```

Idempotent requests are retried up to 3 times on network failures and on HTTP 429, 502, 503 and 504. See the [error cases](#error-cases).

## Regional hosts

Organization hosts of the form `<org>.api.<apex>` are region-free, on any apex.
The SDK sends one unauthenticated `HEAD /` request to learn the region.

It then sends all calls to the regional host and caches the result per client. No credential is sent on that first request.

## Appendix

### Client options

Pass these to `K9oClient::create()`. All are optional.

| Option | Meaning |
|---|---|
| `org_slug` | Organization slug. Also sent as a header on every request |
| `project_slug` | Project slug. Required for data, Gate, storage and other project calls |
| `api_key` | Sent as a Bearer token |
| `base_url` | Host to call. Defaults to the public host `https://api.k9o.io` |
| `default_headers` | Extra headers sent on every request |

How the host is chosen:

| You pass | Host used |
|---|---|
| `org_slug` only | `https://<org_slug>.api.k9o.io` (the default platform host) |
| `base_url` only | The URL you gave |
| `base_url` of a platform host (`api.<apex>`) and `org_slug` | `https://<org_slug>.<host>` |
| `base_url` of any other host | The URL you gave; `org_slug` does not change it |

For an internal or air-gapped platform host, pass its `base_url` and the org slug:

```php
$k9o = K9oClient::create([
    'base_url'     => 'https://api.corp.internal',
    'org_slug'     => 'acme',
    'project_slug' => 'tasks-app',
    'api_key'      => $api_key,
]);
```

This client talks to `https://acme.api.corp.internal`.

The URL must be a scheme and host only, with no path, query or fragment.

### Data queries

| Method | Meaning |
|---|---|
| `from($collection)` | Start a query on a collection |
| `select()` | Read rows |
| `insert($record)` | Add a row |
| `update($patch)` | Change one row; needs `eq('id', ...)` |
| `delete()` | Remove one row; needs `eq('id', ...)` |

| Filter | Meaning |
|---|---|
| `eq`, `neq` | Equal, not equal |
| `gt`, `gte`, `lt`, `lte` | Comparisons |
| `like`, `ilike` | Pattern match, case-sensitive or not |
| `is` | Null and boolean checks |
| `in`, `not_in` | Membership in a list |
| `filter($column, $op, $value)` | Any operator by name |

| Paging | Meaning |
|---|---|
| `limit($n)` | Maximum rows |
| `cursor($opaque)` | Continue from a cursor |
| `count($mode)` | Ask for a row count |

| Search | Meaning |
|---|---|
| `text_search($column, $query, $language, $type)` | Full-text search over one column |
| `vector_search($column, $embedding, $metric, $limit)` | Nearest rows to an embedding, closest first |

### Methods by namespace

Each row is a method on `$k9o->` followed by the namespace.

#### Gate

| Method | Meaning |
|---|---|
| `gate->sign_up($email, $password, $display_name)` | Create an end user |
| `gate->sign_in($email, $password)` | Sign in |
| `gate->sign_out($refresh_token)` | Sign out; the refresh token is required |
| `gate->whoami()` | Current user |
| `gate->refresh_token($refresh_token)` | Rotate tokens |

#### Projects and organizations

| Method | Meaning |
|---|---|
| `projects->list()`, `get($slug)` | Read projects |
| `projects->create($display_name, $opts)` | Create a project; `$opts` takes `slug`, `region`, `custody_mode` and more |
| `projects->update($slug, $fields)` | Change `display_name`, `slug`, `description`, `region` and more |
| `orgs->list()`, `get($org_slug)`, `current()` | Read organizations |
| `orgs->restore($org_id)` | Restore an organization |
| `org->get()` | Current organization |

#### Organization settings

| Namespace | Methods |
|---|---|
| `org->members` | `list`, `invite`, `remove` |
| `org->groups` | `list`, `get` |
| `org->smtp` | `set`, `test`, `unset` |
| `org->audit_log` | `query` |
| `org->default_llm` | `get`, `set` |
| `org->gate->roles` | `list`, `grant`, `get`, `revoke` |
| `org->gate->idp_providers` | `list`, `create`, `get`, `configure`, `delete` |

#### Current user

| Namespace | Methods |
|---|---|
| `me->profile` | `get`, `update` |
| `me->sessions` | `list`, `revoke`, `revoke_all` |
| `me->api_tokens` | `list`, `create`, `delete` |

#### Storage

| Method | Meaning |
|---|---|
| `storage->list_buckets()`, `get_bucket($name)` | Read buckets |
| `storage->create_bucket($name, $opts)` | Create a bucket |
| `storage->update_bucket($name, $patch)` | Change a bucket |
| `storage->delete_bucket($name, $empty)` | Delete a bucket |
| `storage->from($bucket)->upload($path, $data, $opts)` | Upload an object |
| `storage->from($bucket)->create_signed_url($path, $expires_in, $download)` | Signed link; `$expires_in` is 1 to 604800 seconds |
| `storage->from($bucket)->list($prefix)` | List objects |
| `storage->from($bucket)->remove($path)` | Remove an object |

#### Vault, functions, migrations and policies

| Namespace | Methods |
|---|---|
| `vault->secrets` | `list`, `get`, `create`, `update`, `rotate`, `move`, `delete`, `reveal_challenge`, `reveal` |
| `functions` | `upload_artifact`, `deploy`, `list`, `get`, `delete`, `invoke` |
| `migrations` | `apply`, `list`, `get`, `queue`, `list_pending`, `apply_pending`, `reject_pending`, `delete_pending` |
| `policies` | `current`, `list`, `create`, `validate`, `get`, `update`, `delete`, `publish` |
| `audit` | `query` |

#### Method details

| Call | Arguments |
|---|---|
| `functions->upload_artifact($bytes, $kind)` | `$kind` is `wasm` or `tar`; returns `artifactKey` |
| `functions->deploy(...)` | `$name`, `$runtime`, `$tier` (`gateway` or `sandbox`), `$artifact_key`, `$version`, `$description` |
| `functions->invoke($name, $payload, $options)` | Sends `{payload}`; options `background`, `delegation`, `on_behalf_of`, `confined_to` |
| `policies->create($policy, $rules, $editor_mode)` | `$policy` is the policy document; `update` and `validate` take the same |
| `policies->list($status)` | The only filter is `status` |
| `audit->query($filters)` | Keys `event_type`, `actor_id`, `after`, `before`, `limit`, `cursor`; unknown keys throw |
| `me->profile->update($fields)` | Keys `display_name`, `locale`, `timezone`; only given keys are sent |
| `me->api_tokens->create($label, $scope_manifest, $ttl_seconds)` | Create an API token |
| `org->smtp->set(...)` | `$host`, `$port`, `$username`, `$password_ref`, `$from_address`, `$from_name` |
| `org->smtp->test($to, $subject)` | Send a test email |
| `compute->llms->invoke($model_id, $prompt, $params)` | `$params` keys `max_tokens`, `temperature`, `usage_type`; result has `content` and `usage` |
| `ai->generate($prompt, $model, $max_tokens, $temperature, $usage_type)` | Generate text; `$model` is optional, outside-provider models answer 501 |
| `studio->workflows->trigger($id, $context)` | Start a run with an optional context array |
| `vault->secrets->get($name, $region)` | Read a secret by name; `$region` is required |

Deploy a function from an OCI image archive (`tar`, up to 256 MiB) to the `sandbox` tier. The `gateway` tier is for functions owned by platform operators and is not available to most projects. Sandbox functions run in a [Firelock](https://github.com/firelock-sandbox/firelock) microVM sandbox.

```php
$artifact = $k9o->functions->upload_artifact(file_get_contents('hello.tar'), 'tar');

$k9o->functions->deploy(
    name: 'hello',
    runtime: 'bun',
    tier: 'sandbox',
    artifact_key: $artifact->data['artifactKey'],
    version: '1.0.0',
);
$result = $k9o->functions->invoke('hello', ['name' => 'Ada']);

echo "Output: " . $result->data['greeting'] . "\n";
```

```text
Output: Hello, Ada
```

#### Studio, compute and AI

| Namespace | Methods |
|---|---|
| `studio->workflows` | `list`, `create`, `read`, `update`, `delete`, `trigger` |
| `studio->workflows->runs` | `list`, `read`, `cancel` |
| `studio->dashboards` | `list`, `create`, `read`, `update`, `delete`, `pin`, `unpin` |
| `compute->llms` | `list`, `read`, `invoke`, `invoke_stream` |
| `ai` | `embed($input, $model)`, `generate($prompt, $model, $max_tokens, $temperature, $usage_type)` |

Generate text. `model` is optional (the org's default is used); models from outside providers answer 501 until built.

```php
$result = $k9o->ai->generate('Say hi in three words', max_tokens: 20);

echo "Output: " . $result->data['content'] . "\n";
```

```text
Output: Hi there, friend!
```

#### Realtime

| Method | Meaning |
|---|---|
| `realtime->subscribe($topic, $filter)` | A `Generator` of events over SSE |

#### Streams

| Method | Meaning |
|---|---|
| `streams->create($display_name, $max_bytes, $retention_seconds)` | Create a stream; the slug comes from the name |
| `streams->list()` | List the project's streams |
| `streams->get($slug)` | Read one stream |
| `streams->delete($slug)` | Retire a stream |
| `streams->publish($slug, $data, $subject)` | Append an event; `$subject` is a dot-separated routing suffix |
| `streams->replay($slug, $from_sequence, $until_sequence, $limit)` | Read past events: `events`, `nextCursor`, `hasMore` |
| `streams->subscribe($slug, $from_sequence)` | A `Generator` of live events over SSE; reconnects from the last sequence |

#### Approvals

| Method | Meaning |
|---|---|
| `approvals->list($status, $limit)` | Newest pending changes first; not paginated, `$limit` is 1 to 200 |
| `approvals->get($id)` | Read one change |
| `approvals->approve($id, $reason)` | Approve; `status` can stay `pending_approval` while more approvers are needed |
| `approvals->reject($id, $reason)` | Reject; `$reason` is required |
| `approvals->bulk($action, $criteria, $reason)` | Approve or reject every change matching a predicate; `$reason` is required to reject; failed rows are skipped |
| `approval_policies->list()` | All policies, oldest first |
| `approval_policies->create($collection, $operation, $action, $predicate)` | Create a policy; `$action` is `auto_approve` or `queue` |
| `approval_policies->delete($id)` | Delete a policy |
| `schema->collections->list($limit, $cursor)` | List the project's collections by name; page with `meta->cursor->next` |

### Response fields

| Field | Meaning |
|---|---|
| `$response->data` | The decoded payload |
| `$response->meta->cursor->next` | Cursor for the next page |
| `$response->meta->cursor->total_count` | Total rows, when requested with `count()` |
| `$response->meta->context->request_id` | Request id, useful for support |
| `$response->meta->timing->total_ms` | Server time in milliseconds |
| `$response->meta->warnings` | List of warnings; may be empty |

Each `meta` object is `null` when the server did not send it.

### Error cases

Properties of `K9o\KError`:

| Property | Meaning |
|---|---|
| `$e->code` | K9O error code |
| `$e->getMessage()` | Human-readable message |
| `$e->status` | HTTP status, or `null` before a request |
| `$e->request_id` | Request id, when present |
| `$e->recommendation` | Suggested fix, when present |
| `$e->context` | Extra details as an array |

Codes the SDK raises itself:

| Code | When |
|---|---|
| `K9O_INVALID_BASE_URL` | `base_url` is malformed or has a path, query or fragment |
| `K9O_INVALID_FIELD_VALUE` | A slug, collection name, option or argument is invalid |
| `K9O_MISSING_PROJECT_CONTEXT` | A project call was made without `project_slug` |
| `K9O_UPSTREAM_UNAVAILABLE` | The network request failed |
| `K9O_INTERNAL_ERROR` | The response could not be read |

Other codes come from the platform and are passed through unchanged.

## License

Apache-2.0 © Formelo Inc. See `LICENSE` and `NOTICE`.

### About Kilimanjaro

Kilimanjaro is a modular developer platform for building secure enterprise applications.
You define AI agents, functions and data pipelines on the platform.
The SDKs let you deploy and invoke them, read and write your data, publish to and subscribe to streams,
sign users in, and encrypt with keys you hold.

It provides four building blocks:

- **Keep**: AI security and governance. Guardrails and policy for the agents you run.
- **Pulse**: data intelligence and real-time stream processing. Streams, analytics and live feeds.
- **Gate**: customer identity and access management. Sign-in and sessions, and automatic data scoping so a signed-in user reaches only their own data.
- **Kibo**: cryptographic keys and secrets management. Client-side encryption with keys you hold, and a secrets vault.
