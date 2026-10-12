<?php

namespace App\Http\Middleware;

use App\Auth\AuthService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Aligns a few responses with the Python API before a controller runs.
 * Admin routes refuse a non-admin before body validation. A JSON number or
 * string is rejected with 422, which is what FastAPI does for those bodies.
 */
final class MatchPythonContract
{
    public function __construct(private readonly AuthService $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (str_starts_with($request->path(), 'api/admin')) {
            $user = $this->auth->userFromRequest($request);
            if ($user === null) {
                return response()->json(['detail' => 'Could not validate credentials'], 401);
            }
            if ((int) ($user->admin ?? 0) !== 1 && $user->admin !== true) {
                return response()->json(['detail' => 'Insufficient privileges'], 403);
            }
        }

        $badUuid = ! str_contains($request->path(), '/cookbooks/') && $this->hasNonV4Uuid($request);
        if ($this->jsonBodyIsScalar($request) || $badUuid) {
            return response()->json([
                'detail' => [[
                    'loc' => ['body'],
                    'msg' => 'Input should be a valid dictionary or list',
                    'type' => 'model_attributes_type',
                ]],
            ], 422);
        }

        return $next($request);
    }

    private function jsonBodyIsScalar(Request $request): bool
    {
        if (! str_contains((string) $request->header('Content-Type', ''), 'application/json')) {
            return false;
        }

        $raw = trim($request->getContent());
        if ($raw === '') {
            return false;
        }

        $decoded = json_decode($raw);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return true;
        }

        return ! is_array($decoded) && ! is_object($decoded);
    }

    /** FastAPI UUID4 path and query values reject the nil UUID before the handler. */
    private function hasNonV4Uuid(Request $request): bool
    {
        $values = explode('/', $request->path());
        $query = $request->query();
        array_walk_recursive($query, function (mixed $value) use (&$values): void {
            if (is_scalar($value)) {
                $values[] = (string) $value;
            }
        });

        foreach ($values as $value) {
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-([0-9a-f])[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value, $match) === 1
                && strtolower($match[1]) !== '4') {
                return true;
            }
        }

        return false;
    }
}
