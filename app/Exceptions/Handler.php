<?php

namespace App\Exceptions;

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Response;
use Laravel\Passport\Exceptions\MissingScopeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class Handler extends ExceptionHandler
{
    protected $dontFlash = [
        'current_password', 'password', 'password_confirmation', 'new_password', 'confirmation',
        'token', 'code', 'client_secret', 'refresh_token', 'auth_token', 'code_verifier', 'captcha',
    ];

    protected $dontReport = [PrettyPageException::class];

    public function register(): void
    {
        $this->renderable(function (MissingScopeException $exception, $request) {
            return response()->json(['message' => 'Insufficient scope.'], 403);
        });
    }

    protected function shouldReturnJson($request, Throwable $e)
    {
        return $request->is('api', 'api/*', 'oauth/token') || parent::shouldReturnJson($request, $e);
    }

    protected function finalizeRenderedResponse($request, $response, Throwable $e)
    {
        $response = parent::finalizeRenderedResponse($request, $response, $e);
        $response->headers->set('Cache-Control', 'no-store');

        return (new SecurityHeaders)->apply($request, $response);
    }

    protected function prepareResponse($request, Throwable $e)
    {
        $status = $this->isHttpException($e) ? $e->getStatusCode() : 500;
        if ($status >= 500) {
            // Do not depend on the database, locale loader, or assets when rendering a failure.
            $headers = $e instanceof HttpExceptionInterface ? $e->getHeaders() : [];

            return new Response(HttpResponse::$statusTexts[$status] ?? 'Server Error', $status, $headers + ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        return parent::prepareResponse($request, $e);
    }

    protected function convertExceptionToArray(Throwable $e)
    {
        if (!$this->isHttpException($e) || $e->getStatusCode() >= 500) {
            return ['message' => 'Server Error'];
        }

        // Expose only the standardized status phrase, never arbitrary exception detail.
        assert($e instanceof HttpExceptionInterface);

        return ['message' => \Symfony\Component\HttpFoundation\Response::$statusTexts[$e->getStatusCode()] ?? 'Request failed'];
    }
}
