<?php

namespace App\Http\Services;

use App\Http\Services\AwsSqsService;
use Illuminate\Support\Facades\Cache;

class OrderService {

    public function getPendingOrders() {
        $awsSqsService = new AwsSqsService();
        return $awsSqsService->receive();
    }
    
    public function process($messages){
        $awsSqsService = new AwsSqsService();

        foreach ($messages as $itens) {

            $id = $itens['Body'];
            $etapa = Cache::get($id)['etapa'] ?? 0;

            echo "\nID: $id";

            for ($x = $etapa; $x < 5; $x++) {

                usleep(150000);

                switch ($x) {
                    case 0: 
                    case 1: 
                    case 2:
                    case 3:
                        $etapaAtual = $x + 1;
                        echo " | Etapa: {$etapaAtual}";
                        $this->updateEtapa($id, $etapaAtual);
                        break;

                    case 4:
                        $awsSqsService->delete($itens['ReceiptHandle']);
                        Cache::forget($id);
                        echo " | terminou";
                        break;
                }
            }
        }
    }

    protected function updateEtapa($id, $etapa)
    {
        Cache::put($id, ['etapa' => $etapa]);
    }

}
