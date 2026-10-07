<?php

namespace App\Services\Imports;

use App\Services\Environments\EnvironmentVariableWriter;
use App\Services\Templates\FakerMethodCatalog;
use App\Services\Templates\TemplateCompiler;
use JsonException;

/** Rewrites saved examples only, using the same compiler/catalog as the editor. */
final readonly class PostmanResponseVariables
{
    public function __construct(private FakerMethodCatalog $catalog, private TemplateCompiler $compiler) {}

    public function mappings(): array
    {
        $map = [
            '$guid' => 'string.uuid', '$randomUUID' => 'string.uuid',
            '$timestamp' => 'date.epochS', '$isoTimestamp' => 'date.now',
            '$randomEmail' => 'internet.email', '$randomInt' => 'number.int(0,1000)',
            '$randomFirstName' => 'person.firstName', '$randomLastName' => 'person.lastName',
            '$randomFullName' => 'person.fullName', '$randomCity' => 'location.city', '$randomCountry' => 'location.country',
        ];
        foreach ($map as $token => $expression) {
            $method = explode('(', $expression, 2)[0];
            if ($this->catalog->resolve($method)['status'] !== 'current') {
                unset($map[$token]);
            }
        }

        return $map;
    }

    public function rewrite(string $body, array &$warnings): array
    {
        $count = 0;
        $map = $this->mappings();
        $convert = function (string $text) use (&$count, &$warnings, $map): string {
            // Protect ordinary example text that resembles MockDeck's direct
            // method syntax when another part of this response becomes a template.
            if (str_starts_with($text, '$') && (new PostmanVariableCatalog)->tokens($text) === []) {
                $text = '$'.$text;
            }

            return preg_replace_callback('/\{\{\s*([^{}]+?)\s*\}\}/', function (array $match) use (&$count, &$warnings, $map): string {
                $name = $match[1];
                if (str_starts_with($name, '$')) {
                    if (! isset($map[$name])) {
                        $warnings[] = 'Unmapped Postman dynamic variable '.$name.' left as literal text.';

                        return '\\'.$match[0];
                    }
                    $count++;

                    return '{{'.$map[$name].'}}';
                }
                if (! EnvironmentVariableWriter::validKey($name)) {
                    $warnings[] = 'Variable '.$name.' cannot be used as an Environment key; left literal.';

                    return '\\'.$match[0];
                }
                $count++;

                return '{{env.'.$name.'}}';
            }, $text);
        };
        $walk = function (mixed $value) use (&$walk, $convert): mixed {
            if (is_string($value)) {
                return $convert($value);
            }
            if (is_array($value)) {
                return array_map($walk, $value);
            }
            if ($value instanceof \stdClass) {
                foreach ($value as $key => $item) {
                    $value->{$key} = $walk($item);
                }
            }

            return $value;
        };
        try {
            $decoded = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
            $template = json_encode($walk($decoded), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        } catch (JsonException) {
            // Text templates are represented as a JSON string in the JSON editor,
            // and rendered as text bytes, so HTML/text examples keep their media type.
            $template = json_encode($convert($body), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if ($count === 0) {
            return ['body_mode' => 'static', 'template' => null, 'body' => $body];
        }
        $validation = $this->compiler->compile($template);
        if ($validation->hasErrors()) {
            $warnings[] = 'Response variable conversion could not compile safely; retained static example. '.implode(' ', array_map(fn ($issue) => $issue->message, $validation->issues));

            return ['body_mode' => 'static', 'template' => null, 'body' => $body];
        }
        $warnings[] = 'This response was switched to Template mode: '.$count.' variable(s) rewritten.';

        return ['body_mode' => 'template', 'template' => $template, 'body' => is_string($decoded ?? null) ? json_decode($template, true) : $template];
    }
}
