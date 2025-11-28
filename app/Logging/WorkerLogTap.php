<?php

namespace App\Logging;

use Monolog\Logger as MonologLogger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;

class WorkerLogTap
{
    public function __invoke($logger)
    {
        $workerId = $_SERVER['WORKER_ID'] ?? 1;
        $date = date('Y-m-d');
        $logFile = storage_path("logs/{$date}-worker{$workerId}.log");
        $days = config('logging.channels.worker.days', 30);

        $monolog = $logger->getLogger();
        $monolog->setHandlers([]);

        $handler = new StreamHandler($logFile, MonologLogger::DEBUG);

        // formatter sem contexto nem extra
        $formatter = new LineFormatter("%datetime% %level_name%: %message%\n", "Y-m-d\TH:i:s.uP", true, true);
        $handler->setFormatter($formatter);

        $monolog->pushHandler($handler);

        $logger->workerDays = $days;
    }
}
