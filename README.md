# k9o/sdk — Kilimanjaro platform SDK (PHP)

Idiomatic PHP SDK. `snake_case` methods; throw `K9o\KError`; PHP 8.5.12+; named arguments; readonly properties.

## Status

v0.1.0 — first-pass idiomatic ship (P3 wave 6; reshape from prior `kilimanjaro-dev/k9o-gate@0.3.0` OpenAPI codegen).

## Install (when published to Packagist)

```bash
composer require k9o/sdk
```

## Quick start

```php
<?php
require 'vendor/autoload.php';

use K9o\K9oClient;
use K9o\KError;

$k9o = K9oClient::create([
    'base_url'     => 'https://acme.api.k9o.io',
    'api_key'      => getenv('K9O_API_KEY'),
    'org_slug'     => 'acme',
    'project_slug' => 'member-portal',
]);

try {
    $response = $k9o->from('posts')->select()->limit(10)->execute();
    foreach ($response->data as $row) {
        echo json_encode($row), PHP_EOL;
    }
    echo "next cursor: {$response->meta->cursor?->next}\n";

    $session = $k9o->gate->sign_in(email: 'u@x.com', password: '...');
    echo "signed in: {$session->data['user']['id']}\n";
} catch (KError $e) {
    fwrite(STDERR, "[{$e->code}] {$e->getMessage()}: {$e->recommendation}\n");
}
```

## Idiomatic shape (per ch13)

| Aspect | This SDK |
|---|---|
| Composer package | `k9o/sdk` |
| Namespace | `K9o\` |
| Method casing | `snake_case` (PHP idiom) |
| Error delivery | `throw K9o\KError extends \Exception` |
| Factory pattern | `K9oClient::create(['base_url' => ..., 'api_key' => ...])` (associative array opts) |
| Data DSL entry | `$client->from('collection')` |
| Filter chain | `->eq($col, $val)` / `->gt(...)` / `->in($col, [...])` / `->is($col, $val)` / `->filter($col, $op, $val)` |
| Terminal verb | `->execute()` (returns `Envelope`) |
| Meta accessor | `$response->meta->cursor?->next` (null-safe chain via `?->`) |
| Named arguments | `$client->gate->sign_in(email: '...', password: '...')` (PHP 8.0+) |
| Wire format | Always camelCase JSON; explicit field-name translation in `Envelope::fromWire` |
| Runtime | PHP 8.5.12+ |
| HTTP library | `ext-curl` (stdlib; no external HTTP client) |
| Dependencies | ext-json + ext-curl only |

## Architectural notes

- **`ext-curl` over Guzzle**: smaller install footprint; stdlib only.
- **`$e->code` via `__get` magic accessor**: PHP 8.5 forbids shadowing `\Exception::$code` (parent is untyped int). The SDK stores the K9O code internally as `$kerror_code` and exposes it as `$e->code` via magic accessor to preserve ch13 spec sample compatibility.
- **Readonly properties**: `Envelope::$data` / `Envelope::$meta` / `MetaCursor::$next` / etc. are PHP 8.1+ readonly — immutable after construction.
- **Named arguments**: `$k9o->gate->sign_in(email: '...', password: '...')` spec sample.
- **PSR-4 autoloading**: `K9o\` namespace maps to `src/`.

## License

UNLICENSED — internal to Kilimanjaro.
