<?php

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// =============================================
// Worker index via argumento
// =============================================
$workerIndex = intval($argv[1] ?? 1);
if ($workerIndex < 1) {
    echo "Worker inválido.\n";
    exit(1);
}

// WORKER ID GLOBAL DO PROCESSO
$_SERVER['WORKER_ID'] = $workerIndex;

$log = \Log::channel('worker');

// LOG inicial
$log->info("====================================");
$log->info("WORKER {$workerIndex} INICIADO (PID ".getmypid().")");
$log->info("====================================");

// CONFIG LOCAL DO WORKER
$config = [
    'timeout' => 2,
    'sleep'   => 5,
];

// =============================================
// LOOP PRINCIPAL
// =============================================
while (true) {

    try {
        $messages = app(\App\Http\Services\OrderService::class)->getPendingOrders();
    } catch (\Throwable $e) {
        $log->error("[W{$_SERVER['WORKER_ID']}] Erro getPendingOrders(): ".$e->getMessage());
        sleep($config['sleep']);
        continue;
    }

    if (empty($messages)) {
        $log->info("[W{$_SERVER['WORKER_ID']}] Nenhuma mensagem encontrada...");
        sleep($config['sleep']);
        continue;
    }

    // fork filho
    $pid = pcntl_fork();

    if ($pid === -1) {
        $log->error("[W{$_SERVER['WORKER_ID']}] Falha ao criar processo FILHO");
        continue;
    }

    // FILHO
    if ($pid === 0) {
        $childPid = getmypid();
        $log->info("[W{$_SERVER['WORKER_ID']}] FILHO $childPid iniciado");

        $start = microtime(true);

        // fork neto
        $sub = pcntl_fork();

        if ($sub === -1) {
            $log->error("[W{$_SERVER['WORKER_ID']}] Falha ao criar NETO");
            exit(1);
        }

        // NETO executa processamento
        if ($sub === 0) {
            try {
                app(\App\Http\Services\OrderService::class)->process($messages);
            } catch (\Throwable $e) {
                $log->error("[W{$_SERVER['WORKER_ID']}] Erro no processamento: ".$e->getMessage());
            }
            exit(0);
        }

        // FILHO monitora o NETO
        while (true) {
            $res = pcntl_waitpid($sub, $status, WNOHANG);

            if ($res > 0) break;

            if ((microtime(true) - $start) > $config['timeout']) {
                $log->warning("[W{$_SERVER['WORKER_ID']}] Timeout! Matando NETO $sub");
                posix_kill($sub, SIGKILL);
                pcntl_waitpid($sub, $status);
                break;
            }

            usleep(20000);
        }

        $log->info("[W{$_SERVER['WORKER_ID']}] FILHO $childPid finalizado");
        exit(0);
    }

    usleep(50000);
}
