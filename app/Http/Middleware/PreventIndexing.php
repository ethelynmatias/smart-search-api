<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PreventIndexing
{
    /**
     * Keep the response out of search engines, referrers and shared caches.
     *
     * Applied as route middleware rather than in the controller so the headers
     * also ride on the 404 for a wrong token and on a rendered exception page,
     * which can carry the token in its url and a stack trace in its body.
     * X-Robots-Tag is sent as well as the view's robots meta tag: a crawler
     * that reaches the page from a leaked url obeys the header even where
     * robots.txt was never fetched, and the header is what removes a page that
     * has already been indexed.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet, noimageindex');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
