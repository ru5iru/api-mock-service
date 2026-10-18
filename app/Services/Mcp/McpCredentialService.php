<?php

namespace App\Services\Mcp;

use App\Models\McpCredential;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class McpCredentialService
{
    /** @param list<string> $permissions */
    public function generate(string $name, array $permissions): IssuedMcpCredential
    {
        $values = Validator::make(['name' => $name, 'permissions' => $permissions], [
            'name' => ['required', 'string', 'max:255'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['required', 'string', 'distinct', Rule::enum(McpPermission::class)],
        ])->validate();
        $plain = 'mdm_'.bin2hex(random_bytes(32));
        $attributes = ['name' => $values['name'], 'token_hash' => hash('sha256', $plain), 'created_at' => now()];
        foreach (McpPermission::cases() as $permission) {
            $attributes['can_'.$permission->value] = in_array($permission->value, $values['permissions'], true);
        }

        return new IssuedMcpCredential(McpCredential::query()->create($attributes), $plain);
    }

    public function authenticate(#[\SensitiveParameter] ?string $bearer): McpCredential
    {
        $credential = is_string($bearer) && preg_match('/^mdm_[a-f0-9]{64}$/D', $bearer) === 1
            ? McpCredential::query()->where('token_hash', hash('sha256', $bearer))->whereNull('revoked_at')->first()
            : null;
        if ($credential === null) {
            throw new AuthenticationException('A valid MCP bearer credential is required.');
        }
        // Do not refresh a token's timestamp if it was revoked between lookup and update.
        if (McpCredential::query()->whereKey($credential->id)->whereNull('revoked_at')->update(['last_used_at' => now()]) !== 1) {
            throw new AuthenticationException('A valid MCP bearer credential is required.');
        }

        return $credential;
    }

    public function authorize(McpCredential $credential, McpPermission $permission): void
    {
        $current = McpCredential::query()->find($credential->getKey());
        if ($current === null || $current->revoked_at !== null) {
            throw new AuthenticationException('A valid MCP bearer credential is required.');
        }
        if (! $current->getAttribute('can_'.$permission->value)) {
            throw new AuthorizationException('The MCP credential requires '.$permission->value.' permission.');
        }
    }

    public function revoke(int $id): void
    {
        $credential = McpCredential::query()->findOrFail($id);
        McpCredential::query()->whereKey($credential->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }
}
