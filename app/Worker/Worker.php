<?php

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Worker iniciado...\n";

$timeout = 1;

while (true) {
    $messages = app(\App\Http\Services\OrderService::class)->getPendingOrders();

    if (empty($messages)) {
        echo "\n⏳ Nenhuma mensagem, dormindo 5s...";
        sleep(5); 
        continue;
    }

    // Há mensagens → processa em fork com timeout
    $pid = pcntl_fork();

    if ($pid === -1) {
        die("Erro ao criar processo filho\n");
    }

    if ($pid === 0) {
        // FILHO → processa mensagens com timeout curto
        try {
            app(\App\Http\Services\OrderService::class)->process($messages);
        } catch (\Throwable $e) {
            echo "Erro no worker: {$e->getMessage()}\n";
        }
        exit(0);
    }

    // PAI → monitora timeout
    $start = microtime(true);

    while (true) {
        $res = pcntl_waitpid($pid, $status, WNOHANG);

        if ($res > 0) break;

        if ((microtime(true) - $start) > $timeout) {
            echo "\n⛔ Timeout — matando worker…";
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
            break;
        }

        usleep(20000);
    }

    usleep(200000); // respira
}