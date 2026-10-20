<?php

namespace App\Http\Middleware;

use App\Models\TestRun;
use App\Services\TestRuns\RequestScope;
use App\Services\TestRuns\RunRouting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class ResolveTestRun
{
    public function handle(Request $request, Closure $next): Response
    {
        $path = $request->getPathInfo();
        $prefix = str_starts_with($path, RunRouting::PREFIX);
        if ($request->route()?->getName() === 'mock.invoke.run' && ! $prefix) {
            return response()->json(['error' => 'No mock configured for this request', 'reason' => 'test_run_unavailable'], 404);
        }
        if (count($request->headers->all(strtolower(RunRouting::HEADER))) > 1) {
            return response()->json(['error' => 'Conflicting test-run routing metadata'], 400);
        }
        $headerPresent = $request->headers->has(RunRouting::HEADER);
        $header = $request->headers->get(RunRouting::HEADER);
        $publicId = null;
        $originalPath = null;
        if ($prefix) {
            $tail = substr($path, strlen(RunRouting::PREFIX));
            [$publicId, $originalPath] = array_pad(explode('/', $tail, 2), 2, '');
            if ($headerPresent && $header !== $publicId) {
                return response()->json(['error' => 'Conflicting test-run routing metadata'], 400);
            }
        } elseif ($headerPresent) {
            $publicId = $header;
        }
        if ($publicId !== null || $headerPresent || $prefix) {
            $run = is_string($publicId) && Str::isUuid($publicId)
                ? TestRun::query()->where('public_id', $publicId)->where('status', 'active')
                    ->whereHas('credential', fn ($query) => $query->whereNull('revoked_at'))->with('environment')->first()
                : null;
            if ($run === null) {
                return response()->json(['error' => 'No mock configured for this request', 'reason' => 'test_run_unavailable'], 404);
            }
            if ($prefix) {
                // Preserve raw query bytes, method, body, cookies and all non-routing headers.
                // Reinitialize this same request to clear Symfony's cached URL/path values.
                $uri = $request->getRequestUri();
                $query = str_contains($uri, '?') ? '?'.explode('?', $uri, 2)[1] : '';
                $server = $request->server->all();
                $server['REQUEST_URI'] = '/'.$originalPath.$query;
                unset($server['PATH_INFO'], $server['ORIG_PATH_INFO']);
                $request->initialize($request->query->all(), $request->request->all(), $request->attributes->all(),
                    $request->cookies->all(), $request->files->all(), $server, $request->getContent());
            }
            $request->headers->remove(RunRouting::HEADER);
            $request->server->remove('HTTP_X_MOCKDECK_TEST_RUN');
            $request->attributes->set('mockdeck.scope', new RequestScope($run->environment, $run));
        } else {
            $request->attributes->set('mockdeck.scope', RequestScope::ordinary());
        }

        return $next($request);
    }
}
