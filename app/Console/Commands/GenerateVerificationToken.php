<?php

namespace App\Console\Commands;

use App\Models\ApiToken;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class GenerateVerificationToken extends Command
{
    protected $signature = 'mockdeck:api-token {--rotate : Replace the existing token, immediately revoking it}';

    protected $description = 'Generate the instance verification API bearer token and show it once';

    public function handle(): int
    {
        $plain = 'mdv_'.bin2hex(random_bytes(32));
        $created = DB::transaction(function () use ($plain): bool {
            $attributes = ['name' => 'verification', 'token_hash' => hash('sha256', $plain), 'created_at' => now()];
            // The unique name also protects simultaneous first-time CLI invocations.
            if (DB::table('api_tokens')->insertOrIgnore($attributes) === 1) {
                return true;
            }
            if (! $this->option('rotate')) {
                return false;
            }
            ApiToken::query()->where('name', 'verification')->update($attributes + ['last_used_at' => null]);

            return true;
        });
        if (! $created) {
            $this->error('A token already exists. Use --rotate to replace it; the current token will stop working.');

            return self::FAILURE;
        }
        $this->info('Store this token securely. It is shown only now; the database stores only its SHA-256 hash.');
        $this->line($plain);

        return self::SUCCESS;
    }
}
