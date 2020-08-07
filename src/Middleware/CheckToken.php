<?php

namespace Cybex\Protector\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Class TokenMiddleware
 * @package Cybex\Protector\Middleware
 */
class CheckToken
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $token = $request->post('token');
        $users = config('auth.providers.users.model')::whereToken($token)->get()->count();

        if (!$users) {
            return response()->json('Unauthorized', 401);
        }

        return $next($request);
    }
}
