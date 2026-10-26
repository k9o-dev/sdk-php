# k9o/sdk

PHP client for the Kilimanjaro platform: data, Gate sign-in, projects, storage, workflows and more.

Kilimanjaro is the AI-native foundation for building critical applications.
Its products are:

- Keep: AI Security and Governance
- Pulse: Data Intelligence
- Gate: Customer Identity and Access Management
- Kibo: Keys and Secrets Management

## Contents

- [Links](#links)
- [Install](#install)
- [Requirements](#requirements)
- [Quick start](#quick-start)
- [Query data](#query-data)
- [Update and delete](#update-and-delete)
- [Sign in an end user](#sign-in-an-end-user)
- [Read responses](#read-responses)
- [Handle errors](#handle-errors)
- [Regional hosts](#regional-hosts)
- [Appendix](#appendix)

## Links

- Website: <https://kilimanjaro.dev>
- Documentation / Developer Hub: <https://docs.kilimanjaro.dev>
- Platform API: <https://api.k9o.io>
- Platform Console: <https://console.k9o.io>

## Install

```bash
composer require k9o/sdk
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

echo "Created task {$task['id']}: {$task['title']}\n";
```

```text
Created task tsk_1: Write the README
```

For an internal or air-gapped host, see [client options](#client-options).

## Query data

Start with `from()`, add filters, then call `execute()`.

```php
$response = $k9o->from('tasks')->select()->eq('done', false)->limit(10)->execute();
$tasks = $response->data;

foreach ($tasks as $task) {
    echo "- {$task['title']}\n";
}
```

```text
- Write the README
- Ship it
```

Common filters:

| Filter | Meaning |
|---|---|
| `eq($column, $value)` | Equal to |
| `gt($column, $value)` | Greater than |
| `in($column, $values)` | Is one of a list |
| `like($column, $pattern)` | Pattern match |

See the [full list of query methods](#data-queries).

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

echo "Signed in as {$user['email']} ({$user['id']})\n";
```

```text
Signed in as ada@example.com (usr_123)
```

See all [Gate methods](#gate).

## Read responses

Every successful call returns an `Envelope`. It has a readonly `data` property and a `meta` property.

```php
$cursor = $response->meta->cursor;
if ($cursor !== null) {
    echo "Next page cursor: {$cursor->next}\n";
}
```

```text
Next page cursor: cur_abc
```

Pass the cursor to `->cursor($next)` on the next query. See the [response fields](#response-fields).

## Handle errors

Every failure throws `K9o\KError`. Read the K9O code with `$e->code`.

```php
use K9o\KError;

try {
    $profile = $k9o->gate->whoami();
} catch (KError $e) {
    echo "Code: {$e->code}\n";
    echo "Message: {$e->getMessage()}\n";
    echo "HTTP status: {$e->status}\n";
}
```

```text
Code: K9O_PROJECT_NOT_FOUND
Message: Project not found
HTTP status: 404
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
| `studio->workflows->trigger($id, $context)` | Start a run with an optional context array |
| `vault->secrets->get($name, $region)` | Read a secret by name; `$region` is required |

Deploy a function from a built WebAssembly module:

```php
$artifact = $k9o->functions->upload_artifact(file_get_contents('hello.wasm'), 'wasm');

$k9o->functions->deploy(
    name: 'hello',
    runtime: 'bun',
    tier: 'gateway',
    artifact_key: $artifact->data['artifactKey'],
    version: '1.0.0',
);
$result = $k9o->functions->invoke('hello', ['name' => 'Ada']);

echo $result->data['greeting'], "\n";
```

```text
Hello, Ada
```

#### Studio, compute and AI

| Namespace | Methods |
|---|---|
| `studio->workflows` | `list`, `create`, `read`, `update`, `delete`, `trigger` |
| `studio->workflows->runs` | `list`, `read`, `cancel` |
| `studio->dashboards` | `list`, `create`, `read`, `update`, `delete`, `pin`, `unpin` |
| `compute->llms` | `list`, `read`, `invoke`, `invoke_stream` |
| `ai` | `embed($input, $model)` |

#### Realtime

| Method | Meaning |
|---|---|
| `realtime->subscribe($topic, $filter)` | A `Generator` of events over SSE |

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
