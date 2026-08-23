<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \App\Http\Middleware\CheckRole::class,
            'permission' => \App\Http\Middleware\CheckPermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Prevent an oversized upload (POST body larger than post_max_size)
        // from leaking a raw stack trace to the client. Without this,
        // Illuminate\Http\Exceptions\PostTooLargeException bubbles up as an
        // undressed 413 response (with a full trace when APP_DEBUG=true).
        $exceptions->render(function (PostTooLargeException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'La photo est trop volumineuse. Taille maximale autorisée : 8 Mo.',
                ], 413);
            }
        });
    })->create();
