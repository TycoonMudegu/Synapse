<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Core\HttpException;

final class Schema
{
    public static function object(array $properties): array
    {
        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($properties),
            'additionalProperties' => false,
        ];
    }

    public static function string(int $max = 10000): array
    {
        return ['type' => 'string', 'maxLength' => $max];
    }

    public static function strings(): array
    {
        return ['type' => 'array', 'items' => self::string(2000), 'maxItems' => 100];
    }

    public static function forTask(string $task): array
    {
        return match ($task) {
            'test' => self::object(['status' => ['type' => 'string', 'enum' => ['connected']]]),
            'extract_job', 'poster' => self::object([
                'title' => self::string(250),
                'employer' => self::string(250),
                'description' => self::string(50000),
                'requirements' => self::string(20000),
                'responsibilities' => self::string(20000),
                'qualifications' => self::string(10000),
                'location' => self::string(200),
                'employment_type' => [
                    'type' => 'string',
                    'enum' => [
                        'not_specified',
                        'full_time',
                        'part_time',
                        'contract',
                        'internship',
                        'temporary',
                        'volunteer',
                    ],
                ],
                'workplace' => [
                    'type' => 'string',
                    'enum' => ['not_specified', 'remote', 'hybrid', 'office'],
                ],
                'salary_min' => ['type' => ['number', 'null'], 'minimum' => 0],
                'salary_max' => ['type' => ['number', 'null'], 'minimum' => 0],
                'currency' => self::string(10),
                'deadline' => self::string(10),
                'application_method' => [
                    'type' => 'string',
                    'enum' => ['manual', 'email', 'external'],
                ],
                'application_email' => self::string(254),
                'email_subject' => self::string(250),
                'instructions' => self::string(15000),
                'uncertainties' => self::strings(),
            ]),
            'screening' => self::object([
                'score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                'eligibility' => [
                    'type' => 'string',
                    'enum' => ['eligible', 'ineligible', 'uncertain'],
                ],
                'matched_requirements' => self::strings(),
                'missing_requirements' => self::strings(),
                'strengths' => self::strings(),
                'concerns' => self::strings(),
                'mandatory_checks' => [
                    'type' => 'array',
                    'maxItems' => 100,
                    'items' => self::object([
                        'requirement' => self::string(2000),
                        'status' => [
                            'type' => 'string',
                            'enum' => ['verified', 'missing', 'uncertain'],
                        ],
                        'evidence' => self::string(3000),
                    ]),
                ],
                'recommended_action' => self::string(2000),
                'explanation' => self::string(5000),
            ]),
            'tailoring' => self::object([
                'summary' => self::string(5000),
                'employment_order' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer', 'minimum' => 0],
                    'maxItems' => 50,
                ],
                'skill_order' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer', 'minimum' => 0],
                    'maxItems' => 100,
                ],
                'changes' => self::strings(),
            ]),
            'letter' => self::object([
                'content' => self::string(12000),
                'uncertainties' => self::strings(),
            ]),
            'profile' => self::object([
                'full_name' => self::string(150),
                'email' => self::string(254),
                'phone' => self::string(60),
                'location' => self::string(200),
                'summary' => self::string(10000),
                'skills' => self::strings(),
                'employment' => [
                    'type' => 'array',
                    'maxItems' => 50,
                    'items' => self::object([
                        'position' => self::string(200),
                        'employer' => self::string(200),
                        'start_date' => self::string(20),
                        'end_date' => self::string(20),
                        'achievements' => self::string(5000),
                    ]),
                ],
                'education' => [
                    'type' => 'array',
                    'maxItems' => 50,
                    'items' => self::object([
                        'qualification' => self::string(250),
                        'institution' => self::string(250),
                        'completion_year' => self::string(20),
                    ]),
                ],
                'uncertainties' => self::strings(),
            ]),
            'questions' => self::object([
                'answers' => self::strings(),
                'uncertainties' => self::strings(),
            ]),
            'summary' => self::object([
                'summary' => self::string(10000),
                'uncertainties' => self::strings(),
            ]),
            default => throw new \LogicException('Unknown AI task.'),
        };
    }

    public static function validate(mixed $value, array $schema, string $path = 'response'): void
    {
        $types = (array) ($schema['type'] ?? []);
        $valid = false;
        foreach ($types as $type) {
            $valid =
                $valid ||
                match ($type) {
                    'object' => is_array($value) && (!array_is_list($value) || $value === []),
                    'array' => is_array($value) && array_is_list($value),
                    'string' => is_string($value),
                    'integer' => is_int($value),
                    'number' => is_int($value) || is_float($value),
                    'null' => $value === null,
                    'boolean' => is_bool($value),
                    default => false,
                };
        }
        if (!$valid || (isset($schema['enum']) && !in_array($value, $schema['enum'], true))) {
            throw new HttpException(
                'The AI returned an invalid ' . $path . '. Nothing has been applied.',
            );
        }
        if (
            is_numeric($value) &&
            !is_string($value) &&
            ((isset($schema['minimum']) && $value < $schema['minimum']) ||
                (isset($schema['maximum']) && $value > $schema['maximum']))
        ) {
            throw new HttpException('The AI returned an out-of-range ' . $path . '.');
        }
        if (is_string($value) && mb_strlen($value) > ($schema['maxLength'] ?? 100000)) {
            throw new HttpException('The AI returned an overlong ' . $path . '.');
        }
        if (in_array('object', $types, true) && is_array($value)) {
            foreach ($schema['required'] ?? [] as $field) {
                if (!array_key_exists($field, $value)) {
                    throw new HttpException('The AI omitted ' . $path . '.' . $field . '.');
                }
            }
            foreach ($value as $field => $child) {
                if (!isset($schema['properties'][$field])) {
                    throw new HttpException(
                        'The AI returned an unexpected field in ' . $path . '.',
                    );
                }
                self::validate($child, $schema['properties'][$field], $path . '.' . $field);
            }
        }
        if (in_array('array', $types, true) && is_array($value)) {
            if (count($value) > ($schema['maxItems'] ?? 100)) {
                throw new HttpException('The AI returned too many items in ' . $path . '.');
            }
            foreach ($value as $index => $child) {
                self::validate($child, $schema['items'], $path . '[' . $index . ']');
            }
        }
    }
}
