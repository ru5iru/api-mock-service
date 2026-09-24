<?php

namespace App\Services\Templates;

use DateTimeImmutable;
use DateTimeInterface;
use Faker\Factory;
use Faker\Generator;
use InvalidArgumentException;
use Throwable;

final class FakerMethodCatalog
{
    /** @var array<string, Generator> */
    private array $fakers = [];

    /** @var list<array<string, mixed>>|null */
    private ?array $catalog = null;

    /** @return array<string, array{formatter?: string, sampleArgs?: list<mixed>, argsHint?: array<string, mixed>}> */
    public function definitions(): array
    {
        return [
            'person.firstName' => ['formatter' => 'firstName'],
            'person.lastName' => ['formatter' => 'lastName'],
            'person.fullName' => ['formatter' => 'name'],
            'location.address' => ['formatter' => 'address'],
            'location.city' => ['formatter' => 'city'],
            'location.cardinalDirection' => [],
            'location.latitude' => ['formatter' => 'latitude'],
            'location.longitude' => ['formatter' => 'longitude'],
            'location.nearbyGPSCoordinate' => [],
            'location.ordinalDirection' => [],
            'location.secondaryAddress' => ['formatter' => 'secondaryAddress'],
            'location.state' => ['formatter' => 'state'],
            'location.stateAbbr' => ['formatter' => 'stateAbbr'],
            'location.streetAddress' => ['formatter' => 'streetAddress'],
            'location.zipCode' => ['formatter' => 'postcode'],
            'location.country' => ['formatter' => 'country'],
            'location.countryCode' => ['formatter' => 'countryCode'],
            'string.uuid' => ['formatter' => 'uuid'],
            'string.alphanumeric' => [
                'sampleArgs' => [['length' => 10]],
                'argsHint' => ['length' => ['type' => 'integer', 'min' => 1, 'max' => 256, 'default' => 10]],
            ],
            'number.int' => [
                'sampleArgs' => [['min' => 1, 'max' => 100]],
                'argsHint' => [
                    'min' => ['type' => 'integer', 'default' => 0],
                    'max' => ['type' => 'integer', 'default' => 9999],
                ],
            ],
            'number.float' => [
                'sampleArgs' => [['min' => 0, 'max' => 100, 'fractionDigits' => 2]],
                'argsHint' => [
                    'min' => ['type' => 'number', 'default' => 0],
                    'max' => ['type' => 'number', 'default' => 100],
                    'fractionDigits' => ['type' => 'integer', 'min' => 0, 'max' => 10, 'default' => 2],
                ],
            ],
            'datatype.boolean' => ['formatter' => 'boolean'],
            'internet.username' => ['formatter' => 'userName'],
            'internet.ip' => ['formatter' => 'ipv4'],
            'internet.ipv6' => ['formatter' => 'ipv6'],
            'internet.email' => ['formatter' => 'email'],
            'internet.url' => ['formatter' => 'url'],
            'phone.number' => ['formatter' => 'phoneNumber'],
            'company.name' => ['formatter' => 'company'],
            'date.recent' => [
                'sampleArgs' => [['days' => 1]],
                'argsHint' => ['days' => ['type' => 'integer', 'min' => 1, 'max' => 3650, 'default' => 1]],
            ],
            'date.past' => [
                'sampleArgs' => [['years' => 1]],
                'argsHint' => ['years' => ['type' => 'integer', 'min' => 1, 'max' => 100, 'default' => 1]],
            ],
            'date.future' => [
                'sampleArgs' => [['years' => 1]],
                'argsHint' => ['years' => ['type' => 'integer', 'min' => 1, 'max' => 100, 'default' => 1]],
            ],
            'date.between' => [
                'sampleArgs' => [['from' => '2025-01-01', 'to' => '2025-12-31']],
                'argsHint' => [
                    'from' => ['type' => 'string', 'format' => 'date-time', 'required' => true],
                    'to' => ['type' => 'string', 'format' => 'date-time', 'required' => true],
                ],
            ],
            'lorem.word' => ['formatter' => 'word'],
            'lorem.words' => [
                'sampleArgs' => [['count' => 3]],
                'argsHint' => ['count' => ['type' => 'integer', 'min' => 1, 'max' => 100, 'default' => 3]],
            ],
            'lorem.sentence' => [
                'sampleArgs' => [['words' => 6]],
                'argsHint' => ['words' => ['type' => 'integer', 'min' => 1, 'max' => 100, 'default' => 6]],
            ],
            'lorem.paragraphs' => [
                'sampleArgs' => [['count' => 3]],
                'argsHint' => ['count' => ['type' => 'integer', 'min' => 1, 'max' => 20, 'default' => 3]],
            ],
            'commerce.price' => [
                'sampleArgs' => [['min' => 1, 'max' => 1000, 'decimals' => 2]],
                'argsHint' => [
                    'min' => ['type' => 'number', 'default' => 1],
                    'max' => ['type' => 'number', 'default' => 1000],
                    'decimals' => ['type' => 'integer', 'min' => 0, 'max' => 10, 'default' => 2],
                ],
            ],
            'commerce.productName' => [],
            'image.url' => [],
            'image.avatar' => [],
            'helpers.arrayElement' => [
                'sampleArgs' => [['red', 'green', 'blue']],
                'argsHint' => ['values' => ['type' => 'array', 'minItems' => 1, 'required' => true]],
            ],
            'helpers.fromRegExp' => [
                'sampleArgs' => ['[A-Z]{3}-[0-9]{4}'],
                'argsHint' => ['pattern' => ['type' => 'string', 'maxLength' => 200, 'required' => true]],
            ],
        ];
    }

    /** @return array<string, string> */
    public function exactAliases(): array
    {
        return [
            'datatype.uuid' => 'string.uuid',
            'datatype.number' => 'number.int',
            'datatype.float' => 'number.float',
            'internet.userName' => 'internet.username',
            'phone.phoneNumber' => 'phone.number',
            'company.companyName' => 'company.name',
            'person.findName' => 'person.fullName',
            'name.findName' => 'person.fullName',
        ];
    }

    /** @return array<string, string> */
    public function prefixAliases(): array
    {
        return [
            'name.' => 'person.',
            'address.' => 'location.',
        ];
    }

    /** @return list<string> */
    public function blockedMethods(): array
    {
        return [
            'helpers.multiple',
            'helpers.maybe',
            'helpers.mustache',
            'helpers.fake',
        ];
    }

    /** @return array{status: 'current'|'renamed'|'blocked'|'unknown', id: string, suggestion: string|null} */
    public function resolve(string $requested): array
    {
        if ($this->hasForbiddenSegment($requested)) {
            return ['status' => 'blocked', 'id' => $requested, 'suggestion' => null];
        }

        if (in_array($requested, $this->blockedMethods(), true)) {
            return ['status' => 'blocked', 'id' => $requested, 'suggestion' => null];
        }

        if (array_key_exists($requested, $this->definitions())) {
            return ['status' => 'current', 'id' => $requested, 'suggestion' => null];
        }

        $exact = $this->exactAliases()[$requested] ?? null;
        if ($exact !== null) {
            return ['status' => 'renamed', 'id' => $exact, 'suggestion' => $exact];
        }

        foreach ($this->prefixAliases() as $legacyPrefix => $currentPrefix) {
            if (str_starts_with($requested, $legacyPrefix)) {
                $candidate = $currentPrefix.substr($requested, strlen($legacyPrefix));
                if (array_key_exists($candidate, $this->definitions())) {
                    return ['status' => 'renamed', 'id' => $candidate, 'suggestion' => $candidate];
                }

                return ['status' => 'unknown', 'id' => $requested, 'suggestion' => $this->suggest($candidate)];
            }
        }

        return ['status' => 'unknown', 'id' => $requested, 'suggestion' => $this->suggest($requested)];
    }

    /** @return list<array{id: string, module: string, method: string, returns: string, sample: mixed, aliases: list<string>, argsHint?: array<string, mixed>}> */
    public function catalog(): array
    {
        if ($this->catalog !== null) {
            return $this->catalog;
        }

        $faker = Factory::create('en_US');
        $faker->seed(1701);
        $aliases = $this->aliasesByTarget();
        $catalog = [];

        foreach ($this->definitions() as $id => $definition) {
            [$module, $method] = explode('.', $id, 2);
            $sample = $this->invokeWith($faker, $id, $definition['sampleArgs'] ?? []);
            $item = [
                'id' => $id,
                'module' => $module,
                'method' => $method,
                'returns' => $this->returnType($sample),
                'sample' => $this->normalizeValue($sample),
                'aliases' => $aliases[$id] ?? [],
            ];

            if (isset($definition['argsHint'])) {
                $item['argsHint'] = $definition['argsHint'];
            }

            $catalog[] = $item;
        }

        // Catalog samples are stable, but must not make random-mode renders
        // repeat the same sequence after each application start.
        mt_srand(random_int(1, PHP_INT_MAX));

        return $this->catalog = $catalog;
    }

    public function faker(string $locale): Generator
    {
        $normalized = $locale === 'en' ? 'en_US' : str_replace('-', '_', $locale);

        if (! isset($this->fakers[$normalized])) {
            try {
                $this->fakers[$normalized] = Factory::create($normalized);
            } catch (Throwable $exception) {
                throw new InvalidArgumentException("Unsupported Faker locale: {$locale}.", previous: $exception);
            }
        }

        return $this->fakers[$normalized];
    }

    /** @param list<mixed> $args */
    public function invoke(string $method, array $args, string $locale = 'en'): mixed
    {
        $resolved = $this->resolve($method);
        if (! in_array($resolved['status'], ['current', 'renamed'], true)) {
            throw new InvalidArgumentException("Unknown or blocked Faker method: {$method}.");
        }

        return $this->invokeWith($this->faker($locale), $resolved['id'], $args);
    }

    /** @param list<mixed> $args */
    public function validateArguments(string $method, array $args): ?string
    {
        if (strlen(json_encode($args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '') > (int) config('mock.templates.max_args_bytes', 4096)) {
            return 'Faker arguments exceed the configured 4 KB limit.';
        }

        $options = isset($args[0]) && is_array($args[0]) && ! array_is_list($args[0]) ? $args[0] : [];

        if (in_array($method, ['number.int', 'number.float', 'commerce.price'], true)) {
            $minimum = $options['min'] ?? ($args[0] ?? 0);
            $maximum = $options['max'] ?? ($args[1] ?? 9999);
            if (! is_numeric($minimum) || ! is_numeric($maximum) || $minimum > $maximum) {
                return 'Numeric Faker bounds must be numbers with min less than or equal to max.';
            }
        }

        if ($method === 'helpers.arrayElement' && (! isset($args[0]) || ! is_array($args[0]) || $args[0] === [])) {
            return 'helpers.arrayElement requires a non-empty JSON array.';
        }

        if ($method === 'helpers.fromRegExp') {
            $pattern = $args[0] ?? null;
            if (! is_string($pattern) || $pattern === '') {
                return 'helpers.fromRegExp requires a pattern string.';
            }
            if (strlen($pattern) > 200) {
                return 'helpers.fromRegExp patterns are limited to 200 characters.';
            }
            if (preg_match_all('/\{\s*(\d+)(?:\s*,\s*(\d*))?\s*\}/', $pattern, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $upper = ($match[2] ?? '') === '' ? (int) $match[1] : (int) $match[2];
                    if ($upper > 100) {
                        return 'helpers.fromRegExp quantifiers are limited to 100.';
                    }
                }
            }
        }

        if ($method === 'date.between') {
            if (! isset($options['from'], $options['to']) || strtotime((string) $options['from']) === false || strtotime((string) $options['to']) === false) {
                return 'date.between requires valid from and to date strings.';
            }
        }

        return null;
    }

    /** @param list<mixed> $args */
    private function invokeWith(Generator $faker, string $id, array $args): mixed
    {
        $argumentError = $this->validateArguments($id, $args);
        if ($argumentError !== null) {
            throw new InvalidArgumentException($argumentError);
        }

        $options = isset($args[0]) && is_array($args[0]) && ! array_is_list($args[0]) ? $args[0] : [];

        return match ($id) {
            'number.int' => $faker->numberBetween(
                (int) ($options['min'] ?? ($args[0] ?? 0)),
                (int) ($options['max'] ?? ($args[1] ?? 9999)),
            ),
            'number.float' => $faker->randomFloat(
                (int) ($options['fractionDigits'] ?? 2),
                (float) ($options['min'] ?? 0),
                (float) ($options['max'] ?? 100),
            ),
            'string.alphanumeric' => $faker->regexify('[A-Za-z0-9]{'.min(256, max(1, (int) ($options['length'] ?? ($args[0] ?? 10)))).'}'),
            'location.cardinalDirection' => $faker->randomElement(['north', 'east', 'south', 'west']),
            'location.ordinalDirection' => $faker->randomElement(['northwest', 'northeast', 'southwest', 'southeast']),
            'location.nearbyGPSCoordinate' => [
                $faker->latitude((float) ($options['minLatitude'] ?? -90), (float) ($options['maxLatitude'] ?? 90)),
                $faker->longitude((float) ($options['minLongitude'] ?? -180), (float) ($options['maxLongitude'] ?? 180)),
            ],
            'date.recent' => $faker->dateTimeBetween('-'.max(1, (int) ($options['days'] ?? 1)).' days', 'now'),
            'date.past' => $faker->dateTimeBetween('-'.max(1, (int) ($options['years'] ?? 1)).' years', 'now'),
            'date.future' => $faker->dateTimeBetween('now', '+'.max(1, (int) ($options['years'] ?? 1)).' years'),
            'date.between' => $faker->dateTimeBetween((string) $options['from'], (string) $options['to']),
            'lorem.words' => $faker->words(max(1, min(100, (int) ($options['count'] ?? 3))), true),
            'lorem.sentence' => $faker->sentence(max(1, min(100, (int) ($options['words'] ?? 6)))),
            'lorem.paragraphs' => implode("\n\n", $faker->paragraphs(max(1, min(20, (int) ($options['count'] ?? 3))))),
            'commerce.price' => number_format(
                $faker->randomFloat(
                    (int) ($options['decimals'] ?? 2),
                    (float) ($options['min'] ?? 1),
                    (float) ($options['max'] ?? 1000),
                ),
                (int) ($options['decimals'] ?? 2),
                '.',
                '',
            ),
            'commerce.productName' => $faker->randomElement(['Wireless Headphones', 'Travel Backpack', 'Desk Lamp', 'Coffee Grinder']),
            'image.url' => 'https://picsum.photos/seed/'.$faker->uuid().'/640/480',
            'image.avatar' => 'https://i.pravatar.cc/256?u='.rawurlencode($faker->uuid()),
            'helpers.arrayElement' => $faker->randomElement($args[0]),
            'helpers.fromRegExp' => $faker->regexify((string) $args[0]),
            default => $faker->format($this->definitions()[$id]['formatter'], $args),
        };
    }

    /** @return array<string, list<string>> */
    private function aliasesByTarget(): array
    {
        $aliases = [];
        foreach ($this->exactAliases() as $legacy => $target) {
            $aliases[$target][] = $legacy;
        }
        foreach ($this->prefixAliases() as $legacyPrefix => $targetPrefix) {
            foreach (array_keys($this->definitions()) as $target) {
                if (str_starts_with($target, $targetPrefix)) {
                    $aliases[$target][] = $legacyPrefix.substr($target, strlen($targetPrefix));
                }
            }
        }

        return $aliases;
    }

    private function hasForbiddenSegment(string $method): bool
    {
        foreach (explode('.', strtolower($method)) as $segment) {
            if (in_array($segment, ['__proto__', 'constructor', 'prototype'], true)) {
                return true;
            }
        }

        return false;
    }

    private function suggest(string $requested): ?string
    {
        $best = null;
        $distance = PHP_INT_MAX;
        foreach (array_keys($this->definitions()) as $candidate) {
            $candidateDistance = levenshtein(strtolower($requested), strtolower($candidate));
            if ($candidateDistance < $distance) {
                $distance = $candidateDistance;
                $best = $candidate;
            }
        }

        return $distance <= max(3, (int) floor(strlen($requested) / 3)) ? $best : null;
    }

    private function returnType(mixed $value): string
    {
        return match (true) {
            $value instanceof DateTimeInterface => 'date',
            is_int($value), is_float($value) => 'number',
            is_bool($value) => 'boolean',
            is_array($value) => 'array',
            $value === null => 'null',
            default => 'string',
        };
    }

    private function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)->format(DateTimeInterface::ATOM);
        }

        return $value;
    }
}
