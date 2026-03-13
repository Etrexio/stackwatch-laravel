<?php

namespace StackWatch\Laravel\Exceptions;

use Illuminate\Contracts\Debug\ExceptionHandler;
use StackWatch\Laravel\StackWatch;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class StackWatchExceptionHandler implements ExceptionHandler
{
    protected ExceptionHandler $handler;
    protected StackWatch $stackWatch;

    public function __construct(ExceptionHandler $handler, StackWatch $stackWatch)
    {
        $this->handler = $handler;
        $this->stackWatch = $stackWatch;
    }

    public function report(Throwable $e): void
    {
        $this->stackWatch->captureException($e);
        $this->handler->report($e);
    }

    public function shouldReport(Throwable $e): bool
    {
        return $this->handler->shouldReport($e);
    }

    public function render($request, Throwable $e): Response
    {
        return $this->handler->render($request, $e);
    }

    public function renderForConsole($output, Throwable $e): void
    {
        $this->handler->renderForConsole($output, $e);
    }
}
