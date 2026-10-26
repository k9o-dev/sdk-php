<?php

declare(strict_types=1);

namespace K9o;

/** Shared argument checks for the approvals, approval policy and schema namespaces. */
final class ApprovalChecks
{
    public static function invalid(string $message, array $context = []): KError
    {
        return new KError('K9O_INVALID_FIELD_VALUE', $message, $context);
    }

    public static function project_slug(HttpClientConfig $config, string $what): string
    {
        if ($config->project_slug === null || $config->project_slug === '') {
            throw new KError('K9O_MISSING_PROJECT_CONTEXT', "$what operations require project_slug bound on the client");
        }
        return $config->project_slug;
    }

    /** A whole number from 1 to 200, or null. */
    public static function limit(mixed $limit): void
    {
        if ($limit === null) {
            return;
        }
        if (!is_int($limit) || $limit < 1 || $limit > 200) {
            throw self::invalid('limit must be a whole number from 1 to 200', ['limit' => $limit]);
        }
    }

    /** A non-empty string id, URL-encoded for a path segment. */
    public static function id(mixed $value, string $what): string
    {
        if (!is_string($value) || $value === '') {
            throw self::invalid("$what id must be a non-empty string");
        }
        return rawurlencode($value);
    }

    public static function reason(mixed $reason): string
    {
        if (!is_string($reason) || trim($reason) === '') {
            throw self::invalid('reason is required and must be a non-empty string');
        }
        return $reason;
    }

    /** A predicate object: an associative array (not a list) or a \stdClass. */
    public static function predicate(mixed $value, string $field): array|\stdClass
    {
        $ok = $value instanceof \stdClass || (is_array($value) && !array_is_list($value));
        if (!$ok) {
            throw self::invalid("$field must be a predicate object");
        }
        return $value;
    }
}
