<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class FlyHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        // if the app is running on Fly add extra headers
        $response->header('fly-region', config('services.fly.fly_region'));

        return $response;
    }
}
