<?php

$maxWorkers = 5;
$memoryLimitMB = 512;

$workers = [];

while (true) {

    // Verifica memória do container
    $mem = memory_get_usage(true) / 1024 / 1024;
    if ($mem > $memoryLimitMB) {
        echo "Memória excedida ({$mem}MB) – reiniciando launcher...\n";
        exit(100);
    }

    // Limpa processos mortos
    foreach ($workers as $i => $pid) {
        $res = pcntl_waitpid($pid, $status, WNOHANG);
        if ($res > 0) {
            echo "Worker {$i} morreu, reiniciando...\n";
            unset($workers[$i]);
        }
    }

    // Inicia novos workers faltando
    for ($i = 1; $i <= $maxWorkers; $i++) {
        if (!isset($workers[$i])) {
            echo "Iniciando worker {$i}...\n";
            $pid = pcntl_fork();

            if ($pid === -1) {
                echo "Falha ao iniciar worker {$i}\n";
                continue;
            }

            if ($pid === 0) {
                exec("php /var/www/worker.php {$i}");
                exit;
            }

            $workers[$i] = $pid;
        }
    }

    sleep(1);
}
