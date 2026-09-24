<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Templates\FakerMethodCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class FakerCatalogController extends Controller
{
    public function __invoke(Request $request, FakerMethodCatalog $catalog): JsonResponse|Response
    {
        $items = $catalog->catalog();
        $etag = '"'.hash('sha256', json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)).'"';

        if ($request->headers->get('If-None-Match') === $etag) {
            return response('', 304)->header('ETag', $etag);
        }

        return response()->json($items)->header('ETag', $etag)->header('Cache-Control', 'private, max-age=3600');
    }
}
