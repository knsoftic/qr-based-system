<?php

use App\Http\Middleware\AuthenticateMerchant;
use App\Http\Middleware\AuthenticateSmsDevice;
use App\Http\Middleware\AuthenticateVerifier;
use App\Http\Middleware\EnsureAdminIsActive;
use App\Http\Middleware\EnsureMerchantPortal;
use App\Http\Middleware\SecurityHeaders;
use App\Payments\Exceptions\GatewayException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'merchant.auth' => AuthenticateMerchant::class,
            'merchant.portal' => EnsureMerchantPortal::class,
            'sms.device' => AuthenticateSmsDevice::class,
            'verifier.auth' => AuthenticateVerifier::class,
            'admin.active' => EnsureAdminIsActive::class,
        ]);

        $middleware->append(SecurityHeaders::class);

        // The portal signature checker compares a pasted request body byte for byte.
        $middleware->trimStrings(except: ['sig_body']);

        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('merchant', 'merchant/*') ? route('merchant.login') : route('admin.login'));
        $middleware->redirectUsersTo(fn (Request $request) => $request->is('merchant', 'merchant/*') ? route('merchant.dashboard') : route('admin.dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());

        // Expected business errors (bad signature, no account, ...) are returned to the client, not logged.
        $exceptions->dontReport(GatewayException::class);

        $exceptions->render(function (GatewayException $e) {
            return response()->json(['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]], $e->status);
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['error' => [
                    'code' => 'validation_error',
                    'message' => $e->getMessage(),
                    'fields' => $e->errors(),
                ]], 422);
            }
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['error' => ['code' => 'rate_limited', 'message' => 'Too many requests.']], 429, $e->getHeaders());
            }
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($request->is('api/*')) {
                $code = $e->getStatusCode() === 404 ? 'not_found' : 'http_error';

                return response()->json(['error' => ['code' => $code, 'message' => $e->getMessage() ?: 'Request failed.']], $e->getStatusCode(), $e->getHeaders());
            }
        });
    })->create();
