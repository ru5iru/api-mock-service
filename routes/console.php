<?php

use App\Services\Config\ConfigExporter;
use App\Services\Config\ConfigImporter;
use App\Services\Config\ImportMode;
use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;

Artisan::command('mock:about', function (): void {
    $this->info('MockDeck exact-request mock API service');
})->purpose('Display a short description of this application');

Artisan::command(
    'mockdeck:export {--output= : Write JSON to this file instead of stdout} {--endpoint=* : Export only these endpoint UUIDs} {--include-sensitive : Keep request credentials in the export}',
    function (ConfigExporter $exporter): int {
        try {
            $json = $exporter->export(
                $this->option('endpoint') ?: null,
                ! $this->option('include-sensitive'),
            )->toJson();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return Command::FAILURE;
        }

        $output = $this->option('output');
        if (is_string($output) && $output !== '') {
            if (file_put_contents($output, $json) === false) {
                $this->error("Could not write {$output}.");

                return Command::FAILURE;
            }

            $this->info("Exported MockDeck configuration to {$output}.");

            return Command::SUCCESS;
        }

        $this->output->write($json);

        return Command::SUCCESS;
    },
)->purpose('Export a portable MockDeck configuration');

Artisan::command(
    'mockdeck:import {file : MockDeck JSON file} {--dry-run : Preview without writing} {--mode=create-only : create-only, upsert, or clone} {--replace-responses : Delete omitted responses during upsert} {--acknowledge-warnings : Apply despite warnings} {--json : Emit machine-readable output}',
    function (ConfigImporter $importer): int {
        $mode = ImportMode::tryFrom((string) $this->option('mode'));
        if ($mode === null) {
            $this->error('Import mode must be create-only, upsert, or clone.');

            return Command::INVALID;
        }

        $path = (string) $this->argument('file');
        $contents = is_file($path) ? file_get_contents($path) : false;
        if ($contents === false) {
            $this->error("Could not read {$path}.");

            return Command::FAILURE;
        }

        $plan = $importer->preview($contents, $mode, (bool) $this->option('replace-responses'));
        if (! $this->option('json')) {
            $this->info("Preview: {$plan->counts['creates']} create, {$plan->counts['updates']} update, {$plan->counts['conflicts']} conflict.");
            foreach ($plan->errors as $error) {
                $this->error($error);
            }
            foreach ($plan->warnings as $warning) {
                $this->warn($warning);
            }
        }

        if (! $plan->canApply()) {
            if ($this->option('json')) {
                $this->line(json_encode(['plan' => $plan->toArray()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            }

            return Command::FAILURE;
        }

        if ($this->option('dry-run')) {
            if ($this->option('json')) {
                $this->line(json_encode(['plan' => $plan->toArray()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            }

            return Command::SUCCESS;
        }

        try {
            $summary = $importer->apply(
                $plan->token,
                $plan->digest,
                (bool) $this->option('acknowledge-warnings'),
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return Command::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'plan' => $plan->toArray(),
                'summary' => $summary->toArray(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->info("Imported {$summary->endpointsCreated} new and {$summary->endpointsUpdated} updated endpoints.");
        }

        return Command::SUCCESS;
    },
)->purpose('Preview or apply a portable MockDeck configuration');
