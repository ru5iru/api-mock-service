<?php

namespace App\Services\Mcp;

use Illuminate\Http\Request;

/** Explicit preview operations opt out of tool-owned persistence, including auth/audit metadata. */
final class McpReadOnlyPreview
{
    public static function tool(string $name): bool
    {
        return in_array($name, ['preview_template', 'preview_postman_import'], true);
    }

    public static function requested(Request $request): bool
    {
        return $request->isMethod('POST') && $request->input('method') === 'tools/call'
            && is_string($request->input('params.name')) && self::tool($request->input('params.name'));
    }
}
