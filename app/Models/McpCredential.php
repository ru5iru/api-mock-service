<?php

namespace App\Models;

use App\Services\Mcp\McpPermission;
use Illuminate\Database\Eloquent\Model;

final class McpCredential extends Model
{
    public $timestamps = false;

    protected $fillable = ['name', 'token_hash', 'can_read', 'can_write', 'can_verify', 'can_cleanup', 'created_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['can_read' => 'boolean', 'can_write' => 'boolean', 'can_verify' => 'boolean', 'can_cleanup' => 'boolean',
            'last_used_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    /** Fresh database state is essential: revocation must invalidate already-loaded models too. */
    public function permits(McpPermission $permission): bool
    {
        return self::query()->whereKey($this->getKey())->whereNull('revoked_at')->where('can_'.$permission->value, true)->exists();
    }
}
