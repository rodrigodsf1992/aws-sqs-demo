<?php

namespace App\Http\Queue\Interface;

interface AdapterInterface
{
    public function send($id, array $conf, array $data = []): bool;
    public function receive(array $conf, $delete = false): array;
    public function delete($handle, array $conf): bool;
}