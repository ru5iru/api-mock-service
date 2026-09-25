<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CollectionController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Collection::query()->withCount('endpoints')->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $collection = Collection::query()->create($this->validated($request));

        return response()->json($collection->loadCount('endpoints'), 201);
    }

    public function show(Collection $collection): JsonResponse
    {
        return response()->json($collection->loadCount('endpoints'));
    }

    public function update(Request $request, Collection $collection): JsonResponse
    {
        $collection->update($this->validated($request));

        return response()->json($collection->refresh()->loadCount('endpoints'));
    }

    public function destroy(Collection $collection): JsonResponse
    {
        $collection->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
