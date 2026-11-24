<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Middleware\ValidatePostSize as BaseValidatePostSize;
use Illuminate\Http\Request;

/**
 * Laravel's body-size check (a 413 page when a POST is over post_max_size), except for a lead sent
 * from a website's form (POST /forms/{key}): files a visitor attaches can add up past the limit,
 * and that visitor gets the form back with a plain message in their site's language instead of an
 * error page. The lead request says it itself (StoreLeadRequest), once the website is known.
 */
final class ValidatePostSize extends BaseValidatePostSize
{
    /**
     * @param  Request  $request
     */
    #[\Override]
    public function handle($request, Closure $next)
    {
        if ($request->isMethod('POST') && $request->is('forms/*')) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }
}
