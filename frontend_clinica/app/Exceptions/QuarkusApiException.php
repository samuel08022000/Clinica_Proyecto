<?php

namespace App\Exceptions;

use App\Http\Middleware\AdministrativeResponses;
use App\Http\Middleware\ReceptionResponses;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class QuarkusApiException extends RuntimeException implements ShouldntReport
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }

    public function render(Request $request): Response
    {
        if ($request->routeIs('secretaria.*')) {
            return ReceptionResponses::renderFailure($request, $this);
        }

        return AdministrativeResponses::renderFailure($request, $this);
    }
}
