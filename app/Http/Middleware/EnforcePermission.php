<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates an admin route by staff permission(s). "perm:a,b" passes when the user
 * has any of a or b; a super_admin always passes. Used on the admin panel so
 * managers / agents only reach what their role allows.
 */
class EnforcePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if ($user && $user->hasAnyPermission(...$permissions)) {
            return $next($request);
        }

        abort(403, 'You do not have permission to do that.');
    }
}
