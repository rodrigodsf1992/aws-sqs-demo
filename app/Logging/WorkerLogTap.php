<?php

namespace App\Logging;

use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger as MonologLogger;

class WorkerLogTap
{
    /**
     * Configure the given logger instance.
     *
     * @param  \Illuminate\Log\Logger  $logger
     * @return void
     */
    public function __invoke($logger)
    {
        // Pega o worker do $_SERVER
        $workerId = $_SERVER['WORKER_ID'] ?? 1;

        // Dias de retenção da config
        $days = config('logging.channels.worker.days', 30);

        // Nome do arquivo YYYY-MM-DD-workerX.log
        $date = date('Y-m-d');
        $fileName = storage_path("logs/{$date}-worker{$workerId}.log");

        // Remove handlers padrão do logger
        $monolog = $logger->getMonolog();
        foreach ($monolog->getHandlers() as $handler) {
            $monolog->popHandler();
        }

        // Adiciona o RotatingFileHandler
        $monolog->pushHandler(new RotatingFileHandler(
            $fileName,
            $days,
            MonologLogger::DEBUG
        ));
    }
}
