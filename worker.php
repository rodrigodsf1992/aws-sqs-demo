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

// =======================
// Tratamento de sinais
// =======================
declare(ticks = 1);
$shouldExit = false;

pcntl_signal(SIGTERM, function() use (&$shouldExit, $log) {
    $shouldExit = true;
    $log->info("[W{$_SERVER['WORKER_ID']}] SIGTERM recebido, encerrando worker...");
});

// =======================
// Cria process group do worker
// =======================
$pid = getmypid();
if (!posix_setpgid($pid, $pid)) {
    $log->warning("[W{$_SERVER['WORKER_ID']}] Falha ao criar process group");
}

// =============================================
// LOOP PRINCIPAL
// =============================================
while (true) {
    if ($shouldExit) {
        $log->info("[W{$_SERVER['WORKER_ID']}] Encerrando worker e todos os processos do grupo...");
        posix_kill(-getmypid(), SIGKILL);
        exit(0);
    }

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

    // Fork filho
    $childPid = pcntl_fork();
    if ($childPid === -1) {
        $log->error("[W{$_SERVER['WORKER_ID']}] Falha ao criar FILHO");
        continue;
    }

    if ($childPid === 0) {
        // FILHO
        $childProcessPid = getmypid();
        $log->info("[W{$_SERVER['WORKER_ID']}] FILHO $childProcessPid iniciado");

        $start = microtime(true);

        // Fork neto
        $netoPid = pcntl_fork();
        if ($netoPid === -1) {
            $log->error("[W{$_SERVER['WORKER_ID']}] Falha ao criar NETO");
            exit(1);
        }

        if ($netoPid === 0) {
            // NETO
            try {
                app(\App\Http\Services\OrderService::class)->process($messages);
            } catch (\Throwable $e) {
                $log->error("[W{$_SERVER['WORKER_ID']}] Erro no processamento: ".$e->getMessage());
            }
            exit(0);
        }

        // FILHO monitora neto
        while (true) {
            $res = pcntl_waitpid($netoPid, $status, WNOHANG);

            if ($res > 0) break;

            if ((microtime(true) - $start) > $config['timeout']) {
                $log->warning("[W{$_SERVER['WORKER_ID']}] Timeout! Matando NETO $netoPid");
                posix_kill($netoPid, SIGKILL);
                pcntl_waitpid($netoPid, $status);
                break;
            }

            usleep(20000);
        }

        $log->info("[W{$_SERVER['WORKER_ID']}] FILHO $childProcessPid finalizado");
        exit(0);
    }

    usleep(50000);
}
