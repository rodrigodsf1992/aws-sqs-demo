<?php

$maxWorkers = 5;
$memoryLimitMB = 512;
$workers = []; // [workerId => pid]
$workerCount = 0;

// Função para consultar número de mensagens na fila via endpoint
function getPendingMessages(): int {
    // Exemplo: usando file_get_contents ou curl
    $url = "http://localhost/api/queue/pending"; 
    $json = @file_get_contents($url);
    if (!$json) return 0;
    $data = json_decode($json, true);
    return intval($data['pending'] ?? 0);
}

while (true) {
    // =========================
    // Verifica memória do container
    // =========================
    $mem = memory_get_usage(true) / 1024 / 1024;
    if ($mem > $memoryLimitMB) {
        echo "Memória excedida ({$mem}MB) – reiniciando launcher...\n";
        exit(100);
    }

    // =========================
    // Limpa processos mortos
    // =========================
    foreach ($workers as $i => $pid) {
        $res = pcntl_waitpid($pid, $status, WNOHANG);
        if ($res > 0) {
            echo "Worker {$i} morreu, liberando slot...\n";
            unset($workers[$i]);
        }
    }

    // =========================
    // Consulta número de mensagens
    // =========================
    $pendingMessages = getPendingMessages();
    $requiredWorkers = max(1, min($maxWorkers, ceil($pendingMessages / 100)));

    // =========================
    // Inicia novos workers se necessário
    // =========================
    for ($i = 1; $i <= $requiredWorkers; $i++) {
        if (!isset($workers[$i])) {
            echo "Iniciando worker {$i} para {$pendingMessages} mensagens...\n";
            $pid = pcntl_fork();

            if ($pid === -1) {
                echo "Falha ao iniciar worker {$i}\n";
                continue;
            }

            if ($pid === 0) {
                // Seta ID do worker para logging
                $_SERVER['WORKER_ID'] = $i;
                // Chama o worker
                exec("php /var/www/worker.php {$i}");
                exit;
            }

            $workers[$i] = $pid;
        }
    }

    // =========================
    // Reduz workers se necessário
    // =========================
    $currentWorkers = count($workers);
    if ($currentWorkers > $requiredWorkers) {
        $diff = $currentWorkers - $requiredWorkers;
        echo "Reduzindo {$diff} worker(s) por fila menor...\n";
        // Mata os últimos workers excedentes
        for ($i = $maxWorkers; $i >= 1 && $diff > 0; $i--) {
            if (isset($workers[$i])) {
                posix_kill($workers[$i], SIGTERM);
                unset($workers[$i]);
                $diff--;
            }
        }
    }

    sleep(2); // aguarda antes da próxima verificação
}
