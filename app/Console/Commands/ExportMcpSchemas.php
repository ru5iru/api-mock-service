<?php

namespace App\Console\Commands;

use App\Services\Mcp\McpToolRegistry;
use Illuminate\Console\Command;

final class ExportMcpSchemas extends Command
{
    protected $signature = 'mockdeck:mcp-schemas {--check : Fail if the checked-in tool reference differs}';

    protected $description = 'Export or check the public MCP input/output tool schemas';

    public function handle(McpToolRegistry $registry): int
    {
        $path = base_path('docs/MCP_TOOLS.json');
        $json = json_encode(array_values($registry->all()), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        if ($this->option('check')) {
            if (! is_file($path) || file_get_contents($path) !== $json) {
                $this->error('MCP schemas drifted. Run php artisan mockdeck:mcp-schemas.');

                return self::FAILURE;
            }

            return self::SUCCESS;
        }
        file_put_contents($path, $json);
        $this->info('Updated docs/MCP_TOOLS.json from the discovery registry.');

        return self::SUCCESS;
    }
}
