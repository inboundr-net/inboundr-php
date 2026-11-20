<?php

/**
 * Generates src/Generated/Types.php from openapi/openapi.yaml: one PHPStan
 * array-shape type per component schema, plus a `<Operation>Response` and
 * `<Operation>Query` type for every operation, so nothing is written by hand.
 *
 *   php scripts/generate-types.php          regenerate
 *   php scripts/generate-types.php --check  exit 1 if the committed file is stale
 *   php scripts/generate-types.php --sync   copy the spec from the platform first
 *                                           ($INBOUNDR_SPEC, or ../inbound-app)
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

$root = dirname(__DIR__);
$specPath = "$root/openapi/openapi.yaml";
$outPath = "$root/src/Generated/Types.php";

if (in_array('--sync', $argv, true)) {
    $source = getenv('INBOUNDR_SPEC') ?: dirname($root) . '/inbound-app/openapi/openapi.yaml';
    $contents = file_get_contents($source);
    if ($contents === false) {
        fwrite(STDERR, "Could not read $source\n");
        exit(1);
    }
    file_put_contents($specPath, $contents);
    echo "spec: copied $source\n";
}

/** @var array<string, mixed> $spec */
$spec = Yaml::parseFile($specPath);
$schemas = $spec['components']['schemas'];

/** Schemas that refer to themselves; PHPStan aliases can't, so they widen. */
$recursive = ['ValidationErrorTree'];

$key = static fn (string $name): string => preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) ? $name : var_export($name, true);

$type = static function (array $s) use (&$type, $schemas, $recursive, $key): string {
    if (isset($s['$ref'])) {
        $name = substr($s['$ref'], strrpos($s['$ref'], '/') + 1);
        return in_array($name, $recursive, true) ? 'array<string, mixed>' : $name;
    }
    foreach (['oneOf', 'anyOf'] as $union) {
        if (isset($s[$union])) {
            return implode('|', array_unique(array_map($type, $s[$union])));
        }
    }
    if (isset($s['allOf'])) {
        $merged = ['type' => 'object', 'properties' => [], 'required' => []];
        foreach ($s['allOf'] as $part) {
            while (isset($part['$ref'])) {
                $part = $schemas[substr($part['$ref'], strrpos($part['$ref'], '/') + 1)];
            }
            $merged['properties'] += $part['properties'] ?? [];
            $merged['required'] = [...$merged['required'], ...($part['required'] ?? [])];
        }
        return $type($merged);
    }
    if (isset($s['const'])) {
        return var_export($s['const'], true);
    }
    if (isset($s['enum'])) {
        return implode('|', array_map(static fn ($v) => var_export($v, true), $s['enum']));
    }
    $types = (array) ($s['type'] ?? 'mixed');
    $out = [];
    foreach ($types as $t) {
        $out[] = match ($t) {
            'string' => 'string',
            'integer' => 'int',
            'number' => 'int|float',
            'boolean' => 'bool',
            'null' => 'null',
            'array' => 'list<' . $type($s['items'] ?? []) . '>',
            'object' => (static function () use ($s, $type, $key): string {
                if (!empty($s['properties'])) {
                    $required = $s['required'] ?? [];
                    $fields = [];
                    foreach ($s['properties'] as $name => $prop) {
                        $fields[] = $key($name) . (in_array($name, $required, true) ? '' : '?') . ': ' . $type($prop);
                    }
                    return 'array{' . implode(', ', $fields) . '}';
                }
                if (isset($s['additionalProperties']) && is_array($s['additionalProperties'])) {
                    return 'array<string, ' . $type($s['additionalProperties']) . '>';
                }
                return 'array<string, mixed>';
            })(),
            default => 'mixed',
        };
    }
    return implode('|', array_unique($out));
};

$lines = [];
foreach ($schemas as $name => $schema) {
    if (in_array($name, $recursive, true)) {
        continue;
    }
    $lines[] = " * @phpstan-type $name " . $type($schema);
}

$methods = ['get', 'post', 'put', 'patch', 'delete'];
foreach ($spec['paths'] as $item) {
    foreach ($methods as $method) {
        if (!isset($item[$method])) {
            continue;
        }
        $op = $item[$method];
        $name = ucfirst($op['operationId']);
        foreach (['200', '201', '202'] as $status) {
            $schema = $op['responses'][$status]['content']['application/json']['schema'] ?? null;
            if ($schema !== null) {
                $lines[] = " * @phpstan-type {$name}Response " . $type($schema);
                break;
            }
        }
        $query = [];
        foreach ([...($item['parameters'] ?? []), ...($op['parameters'] ?? [])] as $param) {
            if (isset($param['$ref'])) {
                $param = $spec['components']['parameters'][substr($param['$ref'], strrpos($param['$ref'], '/') + 1)];
            }
            if ($param['in'] === 'query') {
                $query[] = $key($param['name']) . '?: ' . $type($param['schema']);
            }
        }
        if ($query !== []) {
            $lines[] = " * @phpstan-type {$name}Query array{" . implode(', ', $query) . '}';
        }
    }
}

$file = <<<PHP
<?php

// GENERATED by scripts/generate-types.php from openapi/openapi.yaml. Do not edit.

declare(strict_types=1);

namespace Inboundr\\Generated;

/**
 * PHPStan/Psalm array shapes for every API schema, response and query.
 * Import them with `@phpstan-import-type Email from Types`.
 *

PHP . implode("\n", $lines) . <<<PHP

 */
final class Types
{
}

PHP;

if (in_array('--check', $argv, true)) {
    if (!is_file($outPath) || file_get_contents($outPath) !== $file) {
        fwrite(STDERR, "src/Generated/Types.php is stale. Run `composer types`.\n");
        exit(1);
    }
    echo "types: up to date\n";
    exit(0);
}

file_put_contents($outPath, $file);
echo "types: wrote src/Generated/Types.php\n";
