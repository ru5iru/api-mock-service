<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class TagController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Tag::query()->withCount('endpoints')->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $tag = Tag::query()->create($this->validated($request));

        return response()->json($tag->loadCount('endpoints'), 201);
    }

    public function show(Tag $tag): JsonResponse
    {
        return response()->json($tag->loadCount('endpoints'));
    }

    public function update(Request $request, Tag $tag): JsonResponse
    {
        $tag->update($this->validated($request, $tag));

        return response()->json($tag->refresh()->loadCount('endpoints'));
    }

    public function destroy(Tag $tag): JsonResponse
    {
        $tag->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, string> */
    private function validated(Request $request, ?Tag $tag = null): array
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80']]);
        $data['name'] = trim($data['name']);
        validator(
            ['normalized_name' => Str::lower($data['name'])],
            ['normalized_name' => ['required', Rule::unique('tags')->ignore($tag?->id)]],
            ['normalized_name.unique' => 'Tag names are unique regardless of case.'],
        )->validate();

        return $data;
    }
}
