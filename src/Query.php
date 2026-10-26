<?php

declare(strict_types=1);

namespace K9o;

use K9o\Data\FromBuilder;

/**
 * `$k9o->query($sql, $params)` and `$k9o->search($collection, $column, $q)`.
 *
 * Wire (project-plane):
 *   POST /@<slug>/query   body { sql, params? }   -> { data: row[], meta }
 *   GET  /@<slug>/data/<collection>?textSearch=<column>:<q>...   (the select builder's `text_search`)
 *
 * `$params` is a list (positional, bound to `$1, $2, ...`; the SQL is sent as written) or an array with string
 * keys of named values (`:name` placeholders are rewritten to `$n` here and the values become the positional list).
 * Values are bound by the database, never interpolated into the SQL text.
 */
final class Query
{
    private static function invalid(string $message, array $context = []): KError
    {
        return new KError('K9O_INVALID_FIELD_VALUE', $message, $context);
    }

    private static function is_ident_char(string $c): bool
    {
        return $c !== '' && (ctype_alnum($c) || $c === '_');
    }

    private static function is_ident_start(string $c): bool
    {
        return $c !== '' && (ctype_alpha($c) || $c === '_');
    }

    private static function check_value(mixed $value, string $where): mixed
    {
        if ($value === null || is_string($value) || is_bool($value) || is_int($value)) {
            return $value;
        }
        if (is_float($value) && is_finite($value)) {
            return $value;
        }
        throw self::invalid("$where must be a string, number, boolean or null; serialise anything else (for example as JSON text) first");
    }

    /**
     * Rewrite `:name` placeholders to `$n`. Skips quoted text, comments, dollar-quoted strings and `::` casts.
     *
     * @param array<string, mixed> $named
     * @return array{0: string, 1: list<mixed>} the new SQL and the positional values
     */
    public static function translate_named_params(string $sql, array $named): array
    {
        $order = [];
        $out = '';
        $i = 0;
        $n = strlen($sql);
        while ($i < $n) {
            $c = $sql[$i];
            $next = $i + 1 < $n ? $sql[$i + 1] : '';
            if ($c === "'" || (($c === 'E' || $c === 'e') && $next === "'" && !($i > 0 && self::is_ident_char($sql[$i - 1])))) {
                $escapes = $c !== "'";
                $j = $escapes ? $i + 2 : $i + 1;
                while ($j < $n) {
                    if ($escapes && $sql[$j] === '\\') {
                        $j += 2;
                        continue;
                    }
                    if ($sql[$j] === "'") {
                        if ($j + 1 < $n && $sql[$j + 1] === "'") {
                            $j += 2;
                            continue;
                        }
                        break;
                    }
                    $j++;
                }
                $out .= substr($sql, $i, min($j + 1, $n) - $i);
                $i = $j + 1;
            } elseif ($c === '"') {
                $j = $i + 1;
                while ($j < $n) {
                    if ($sql[$j] === '"') {
                        if ($j + 1 < $n && $sql[$j + 1] === '"') {
                            $j += 2;
                            continue;
                        }
                        break;
                    }
                    $j++;
                }
                $out .= substr($sql, $i, min($j + 1, $n) - $i);
                $i = $j + 1;
            } elseif ($c === '-' && $next === '-') {
                $j = strpos($sql, "\n", $i);
                if ($j === false) {
                    $j = $n;
                }
                $out .= substr($sql, $i, $j - $i);
                $i = $j;
            } elseif ($c === '/' && $next === '*') {
                $depth = 1;
                $j = $i + 2;
                while ($j < $n && $depth > 0) {
                    $two = substr($sql, $j, 2);
                    if ($two === '/*') {
                        $depth++;
                        $j += 2;
                    } elseif ($two === '*/') {
                        $depth--;
                        $j += 2;
                    } else {
                        $j++;
                    }
                }
                $out .= substr($sql, $i, $j - $i);
                $i = $j;
            } elseif ($c === '$') {
                if (preg_match('/\G\$([A-Za-z_][A-Za-z0-9_]*)?\$/', $sql, $m, 0, $i) === 1) {
                    $close = strpos($sql, $m[0], $i + strlen($m[0]));
                    $end = $close === false ? $n : $close + strlen($m[0]);
                    $out .= substr($sql, $i, $end - $i);
                    $i = $end;
                } else {
                    $out .= $c;
                    $i++;
                }
            } elseif ($c === ':' && $next === ':') {
                $out .= '::';
                $i += 2;
            } elseif ($c === ':' && self::is_ident_start($next) && !($i > 0 && self::is_ident_char($sql[$i - 1]))) {
                $j = $i + 2;
                while ($j < $n && self::is_ident_char($sql[$j])) {
                    $j++;
                }
                $name = substr($sql, $i + 1, $j - $i - 1);
                $idx = array_search($name, $order, true);
                if ($idx === false) {
                    if (!array_key_exists($name, $named)) {
                        throw self::invalid("no value for :$name", ['name' => $name]);
                    }
                    $order[] = $name;
                    $idx = count($order) - 1;
                }
                $out .= '$' . ($idx + 1);
                $i = $j;
            } else {
                $out .= $c;
                $i++;
            }
        }
        $values = [];
        foreach ($order as $name) {
            $values[] = self::check_value($named[$name], "the value for :$name");
        }
        return [$out, $values];
    }

    /** POST /@<slug>/query — run one read-only SQL statement; `data` is the list of rows. */
    public static function run(HttpClientConfig $config, string $sql, ?array $params): Envelope
    {
        $slug = Studio::require_project_slug($config, 'query');
        $text = $sql;
        $values = [];
        if ($params !== null && $params !== []) {
            if (array_is_list($params)) {
                foreach ($params as $i => $v) {
                    $values[] = self::check_value($v, "params[$i]");
                }
            } else {
                [$text, $values] = self::translate_named_params($sql, $params);
            }
        }
        $body = ['sql' => $text];
        if ($values !== []) {
            $body['params'] = $values;
        }
        return HttpTransport::request($config, 'POST', UrlGrammar::project_path($slug, 'query'), $body);
    }

    /** Full-text search over one text column of a collection, best matches first. */
    public static function search(
        HttpClientConfig $config,
        string $collection,
        string $column,
        string $q,
        ?string $language,
        ?string $type,
        ?int $limit,
        ?string $cursor
    ): Envelope {
        $builder = (new FromBuilder($config, $collection))->select()
            ->text_search($column, $q, $language ?? 'english', $type ?? 'plain');
        if ($limit !== null) {
            $builder->limit($limit);
        }
        if ($cursor !== null) {
            $builder->cursor($cursor);
        }
        return $builder->execute();
    }
}
