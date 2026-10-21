<?php

namespace App\Console\Commands;

use App\Services\Mcp\McpCredentialService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

final class GenerateMcpCredential extends Command
{
    protected $signature = 'mockdeck:mcp-credential:generate {name : Operator label} {--permission=* : Explicit permission; repeat for read, write, verify, cleanup}';

    protected $description = 'Generate an MCP credential and show its raw bearer token once';

    public function handle(McpCredentialService $credentials): int
    {
        try {
            $issued = $credentials->generate((string) $this->argument('name'), $this->option('permission'));
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                $this->error($messages[0]);
            }

            return self::FAILURE;
        }
        $this->info('Credential '.$issued->credential->id.' created. Store this token securely; it is shown only now and cannot be retrieved.');
        $this->line($issued->reveal());

        return self::SUCCESS;
    }
}
