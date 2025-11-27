<?php

namespace App\Http\Middleware;

use Closure;
use Exception;

class HttpRequestTimeoutMiddleware
{
    private int $timeout = 1; // segundos

    public function handle($request, Closure $next)
    {
        if (!function_exists('pcntl_signal') || !function_exists('pcntl_alarm')) {
            return response()->json(['message' => 'pcntl não está habilitado'], 500);
        }

        // Handler do timeout
        pcntl_signal(SIGALRM, function () {
            throw new Exception('TIMEOUT');
        });

        // Inicia o alarme
        pcntl_alarm($this->timeout);

        try {
            $response = $next($request);
        } catch (Exception $e) {

            if ($e->getMessage() === 'TIMEOUT') {
                return response()->json([
                    'message' => 'Tempo limite da requisição excedido'
                ], 504);
            }

            throw $e; // outras exceções continuam normais
        } finally {
            // sempre cancelar o alarme
            pcntl_alarm(0);
        }

        return $response;
    }
}