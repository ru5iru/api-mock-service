<?php

namespace App\Console\Commands;

use App\Services\Mcp\McpCredentialService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class RevokeMcpCredential extends Command
{
    protected $signature = 'mockdeck:mcp-credential:revoke {id : Numeric credential ID from the list command}';

    protected $description = 'Immediately revoke an MCP credential without deleting its audit identity';

    public function handle(McpCredentialService $credentials): int
    {
        $id = (string) $this->argument('id');
        if (! ctype_digit($id) || (int) $id < 1) {
            $this->error('Enter a positive credential ID.');

            return self::FAILURE;
        }
        try {
            $credentials->revoke((int) $id);
        } catch (ModelNotFoundException) {
            $this->error('MCP credential not found.');

            return self::FAILURE;
        }
        $this->info('MCP credential '.$id.' revoked.');

        return self::SUCCESS;
    }
}
