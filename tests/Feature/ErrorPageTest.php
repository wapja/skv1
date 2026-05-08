<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

it('renders the 403 error page in Dutch', function () {
    $view = view('errors.403', ['exception' => new AuthorizationException('forbidden')])->render();

    expect($view)->toContain('Geen toegang');
});

it('renders the 404 error page', function () {
    $view = view('errors.404', ['exception' => new NotFoundHttpException])->render();

    expect($view)->toContain('Pagina niet gevonden');
});

it('renders the 419 error page', function () {
    $view = view('errors.419', ['exception' => new TokenMismatchException])->render();

    expect($view)->toContain('Sessie verlopen');
});

it('renders the 429 error page', function () {
    $view = view('errors.429', ['exception' => new ThrottleRequestsException('rate-limited')])->render();

    expect($view)->toContain('Te veel verzoeken');
});

it('renders the 500 error page', function () {
    $view = view('errors.500', ['exception' => new RuntimeException('boom')])->render();

    expect($view)->toContain('Er ging iets mis');
});

it('renders the 503 error page', function () {
    $view = view('errors.503', ['exception' => new ServiceUnavailableHttpException])->render();

    expect($view)->toContain('We zijn even bezig');
});
