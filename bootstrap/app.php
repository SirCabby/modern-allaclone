<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\TasksEnabled;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias(['tasks.enabled' => TasksEnabled::class]);

        // This container is reachable directly from the internet via a port
        // forward, so X-Forwarded-* may only be believed when it comes from the
        // LAN or a docker bridge. Trusting '*' here would let any visitor spoof
        // their source address with a header and slip the rate limits.
        //
        // These ranges also cover a future local reverse proxy, where honouring
        // X-Forwarded-Proto is what keeps generated URLs on https.
        $middleware->trustProxies(at: [
            '127.0.0.1',
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
