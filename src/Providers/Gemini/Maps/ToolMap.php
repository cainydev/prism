<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini\Maps;

use Illuminate\Support\Arr;
use Prism\Prism\Contracts\Schema;
use Prism\Prism\Tool;

class ToolMap
{
    /**
     * @param  array<Tool>  $tools
     * @return array<array<string, mixed>>
     */
    public static function map(array $tools): array
    {
        if ($tools === []) {
            return [];
        }

        return array_map(fn (Tool $tool): array => [
            'name' => $tool->name(),
            'description' => $tool->description(),
            ...$tool->hasParameters() ? [
                'parameters' => self::normalizeSchema([
                    'type' => 'object',
                    'properties' => self::mapProperties($tool->parameters()),
                    'required' => $tool->requiredParameters(),
                ]),
            ] : [],
        ], $tools);
    }

    /**
     * @param  array<string,Schema>  $properties
     * @return array<string,mixed>
     */
    public static function mapProperties(array $properties): array
    {
        return Arr::mapWithKeys($properties, fn (Schema $schema, string $name): array => [
            $name => self::normalizeSchema((new SchemaMap($schema))->toArray()),
        ]);
    }

    /**
     * Recursively normalize a schema array for Gemini compatibility.
     *
     * Converts type arrays (e.g. ["string", "null"]) to scalar type + nullable,
     * which is required by Gemini's protobuf API.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function normalizeSchema(array $schema): array
    {
        // Convert type arrays to scalar type + nullable
        if (isset($schema['type']) && is_array($schema['type'])) {
            $types = array_filter($schema['type'], fn (string $t): bool => $t !== 'null');
            if (in_array('null', $schema['type'], true)) {
                $schema['nullable'] = true;
            }
            $schema['type'] = reset($types) ?: 'string';
        }

        // Recursively normalize nested properties
        if (isset($schema['properties']) && is_array($schema['properties'])) {
            foreach ($schema['properties'] as $key => $property) {
                if (is_array($property)) {
                    $schema['properties'][$key] = self::normalizeSchema($property);
                }
            }
        }

        // Recursively normalize array items
        if (isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = self::normalizeSchema($schema['items']);
        }

        // Recursively normalize anyOf schemas
        if (isset($schema['anyOf']) && is_array($schema['anyOf'])) {
            $schema['anyOf'] = array_map(
                self::normalizeSchema(...),
                $schema['anyOf']
            );
        }

        return $schema;
    }
}
