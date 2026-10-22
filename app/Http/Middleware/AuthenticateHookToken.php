<?php

namespace App\Http\Middleware;

use App\Models\HookToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateHookToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = HookToken::findByPlain($request->bearerToken());

        if (! $token) {
            return response()->json(['message' => 'Invalid hook token.'], 401);
        }

        $token->touchUsed();

        return $next($request);
    }
}
