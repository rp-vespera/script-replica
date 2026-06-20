<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP Basic Auth gate for the Script Review pages (which can execute scripts).
 * Credentials come from SCRIPT_REVIEW_USER / SCRIPT_REVIEW_PASSWORD.
 */
class ScriptReviewAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = (string) config('services.scripts_review.user');
        $pass = (string) config('services.scripts_review.password');

        // Not configured: allow on local dev only; never expose elsewhere.
        if ($user === '' || $pass === '') {
            if (app()->environment('local')) {
                return $next($request);
            }

            abort(503, 'Script review is not configured. Set SCRIPT_REVIEW_USER and SCRIPT_REVIEW_PASSWORD.');
        }

        $givenUser = (string) $request->getUser();
        $givenPass = (string) $request->getPassword();

        if (hash_equals($user, $givenUser) && hash_equals($pass, $givenPass)) {
            return $next($request);
        }

        return response('Authentication required.', 401, [
            'WWW-Authenticate' => 'Basic realm="Script Review", charset="UTF-8"',
        ]);
    }
}
