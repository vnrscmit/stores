<?php

namespace App\Http\Middleware;

use App\Support\FiscalYear;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveYear
{
    /**
     * Legacy session contract: every authenticated request carries the active
     * financial year (ayear1/ayear2/yearid_id) resolved from tblyears at login.
     */
    public function handle(Request $request, Closure $next): Response
    {
        FiscalYear::resolve();

        return $next($request);
    }
}
