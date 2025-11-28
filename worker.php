<?php

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Redis;

$maxWorkers = 5;
$memoryLimitMB = 512;
$workers = []; // [workerId => pid]

declare(ticks = 1);

// =======================
// Flag de saída do launcher
// =======================
$shouldExit = false;

// Captura SIGTERM
pcntl_signal(SIGTERM, function() use (&$shouldExit) {
    $shouldExit = true;
});
// Captura SIGINT (Ctrl+C)
pcntl_signal(SIGINT, function() use (&$shouldExit) {
    $shouldExit = true;
});

// Função para log com horário no terminal
function tlog(string $message) {
    echo "[".date('Y-m-d H:i:s')."] ".$message."\n";
}

// =======================
// Função para iniciar um worker
// =======================
function startWorker(int $workerIndex): int {
    $pid = pcntl_fork();
    if ($pid === -1) {
        tlog("[Launcher] Falha ao iniciar worker {$workerIndex}");
        \Log::channel('worker')->error("Falha ao iniciar worker {$workerIndex}");
        return 0;
    }

    if ($pid === 0) {
        $_SERVER['WORKER_ID'] = $workerIndex;
        $workerPid = getmypid();
        \Log::channel('worker')->info("WORKER {$workerIndex} INICIADO (PID {$workerPid})");
        tlog("[Worker {$workerIndex}] Iniciado (PID {$workerPid})");

        posix_setpgid($workerPid, $workerPid);

        $shouldExitWorker = false;

        // Sinais para o worker específico
        pcntl_signal(SIGTERM, function() use (&$shouldExitWorker, $workerIndex, $workerPid) {
            $shouldExitWorker = true;
            tlog("[Worker {$workerIndex}] SIGTERM recebido, encerrando filhos/netos do PGID {$workerPid}");
        });
        pcntl_signal(SIGINT, function() use (&$shouldExitWorker, $workerIndex, $workerPid) {
            $shouldExitWorker = true;
            tlog("[Worker {$workerIndex}] SIGINT recebido (Ctrl+C), encerrando filhos/netos do PGID {$workerPid}");
        });

        $config = ['timeout' => 4, 'sleep' => 5];

        while (true) {
            pcntl_signal_dispatch();

            if ($shouldExitWorker) {
                tlog("[Worker {$workerIndex}] Enviando SIGTERM para todos os filhos/netos do PGID {$workerPid}...");
                posix_kill(-getmypid(), SIGTERM);

                // Aguarda filhos/netos terminarem
                while (pcntl_wait($status) > 0) {
                    // espera ativa
                }

                tlog("[Worker {$workerIndex}] Todos os filhos/netos finalizados. Encerrando worker.");
                exit(0);
            }

            try {
                $messages = app(\App\Http\Services\OrderService::class)->getPendingOrders();
            } catch (\Throwable $e) {
                \Log::channel('worker')->error("[W{$workerIndex}] getPendingOrders(): ".$e->getMessage());
                sleep($config['sleep']);
                continue;
            }

            if (empty($messages)) {
                tlog("[Worker {$workerIndex}] Nenhuma mensagem para processar. Dormindo por {$config['sleep']}s");
                sleep($config['sleep']);
                continue;
            }

            // Fork do filho
            $childPid = pcntl_fork();
            if ($childPid === -1) {
                \Log::channel('worker')->error("[W{$workerIndex}] Falha ao criar FILHO");
                continue;
            }

            if ($childPid === 0) {
                $childProcessPid = getmypid();
                \Log::channel('worker')->info("[W{$workerIndex}] FILHO {$childProcessPid} iniciado");

                $start = microtime(true);

                $netoPid = pcntl_fork();
                if ($netoPid === -1) {
                    \Log::channel('worker')->error("[W{$workerIndex}] Falha ao criar NETO");
                    exit(1);
                }

                if ($netoPid === 0) {
                    try {
                        app(\App\Http\Services\OrderService::class)->process($messages);
                        \Log::channel('worker')->info("[W{$workerIndex}] NETO ".getmypid()." processou mensagens com sucesso");
                    } catch (\Throwable $e) {
                        \Log::channel('worker')->error("[W{$workerIndex}] Erro no NETO: ".$e->getMessage());
                    }
                    exit(0);
                }

                while (true) {
                    pcntl_signal_dispatch();
                    $res = pcntl_waitpid($netoPid, $status, WNOHANG);
                    if ($res > 0) break;
                    if ((microtime(true) - $start) > $config['timeout']) {
                        \Log::channel('worker')->warning("[W{$workerIndex}] Timeout! Matando NETO {$netoPid}");
                        posix_kill($netoPid, SIGKILL);
                        pcntl_waitpid($netoPid, $status);
                        break;
                    }
                    usleep(20000);
                }

                \Log::channel('worker')->info("[W{$workerIndex}] FILHO {$childProcessPid} finalizado");
                exit(0);
            }

            usleep(50000);
        }
    }

    return $pid;
}

// =======================
// Loop principal do launcher/worker
// =======================
while (true) {
    pcntl_signal_dispatch();

    if ($shouldExit) {
        tlog("[Launcher] Encerrando todos os workers...");
        foreach ($workers as $i => $pid) {
            tlog("[Launcher] Matando worker {$i} (PID {$pid} e todo PGID) com SIGTERM...");
            posix_kill(-$pid, SIGTERM);
        }

        // Aguarda workers terminarem
        foreach ($workers as $i => $pid) {
            pcntl_waitpid($pid, $status);
            tlog("[Launcher] Worker {$i} finalizado com sucesso.");
        }

        exit(0);
    }

    $mem = memory_get_usage(true) / 1024 / 1024;
    if ($mem > $memoryLimitMB) {
        tlog("[Launcher] Memória excedida ({$mem}MB) – reiniciando launcher...");
        foreach ($workers as $i => $pid) {
            posix_kill(-$pid, SIGTERM);
        }
        exit(100);
    }

    foreach ($workers as $i => $pid) {
        $res = pcntl_waitpid($pid, $status, WNOHANG);
        if ($res > 0) {
            tlog("[Launcher] Worker {$i} morreu, liberando slot...");
            unset($workers[$i]);
        }
    }

    $pendingMessages = (int) Redis::get(env('QUEUE_SQS_TICKETS_NAME_TOTAL_NUMBER_MESSAGES_SQS', 'total_number_messages_sqs'));
    $requiredWorkers = max(1, min($maxWorkers, ceil($pendingMessages / 100)));

    for ($i = 1; $i <= $requiredWorkers; $i++) {
        if (!isset($workers[$i])) {
            tlog("[Launcher] Iniciando worker {$i} para {$pendingMessages} mensagens...");
            $workers[$i] = startWorker($i);
        }
    }

    $currentWorkers = count($workers);
    if ($currentWorkers > $requiredWorkers) {
        $diff = $currentWorkers - $requiredWorkers;
        tlog("[Launcher] Reduzindo {$diff} worker(s)...");

        for ($i = $maxWorkers; $i >= 1 && $diff > 0; $i--) {
            if (isset($workers[$i])) {
                $pid = $workers[$i];
                tlog("[Launcher] Matando worker {$i} (PID {$pid} e todo PGID) com SIGTERM...");
                posix_kill(-$pid, SIGTERM);

                pcntl_waitpid($pid, $status);
                tlog("[Launcher] Worker {$i} finalizado com sucesso.");

                unset($workers[$i]);
                $diff--;
            }
        }
    }

    sleep(2);
}
