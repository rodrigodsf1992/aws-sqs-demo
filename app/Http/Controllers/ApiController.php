<?php

namespace App\Http\Controllers;

use App\Http\Services\AwsSqsService;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class ApiController extends Controller  
{
    public function createOrder(Request $request)
    {
        $awsSqsService = new AwsSqsService();

        $qtd = $request->input('qtd') ?? 100;

        for ($i = 0; $i < $qtd; $i++) {
            $uuid = Str::uuid()->toString();
            $expiresAt = Carbon::now()->addDays(4);
            Cache::put($uuid, ['etapa' => 0], $expiresAt);
            $awsSqsService->send($uuid);
        }

        return response()->json([
            'message' => 'UUID enviado com sucesso'
        ], 201);
    }

    public function deleteOrder(Request $request)
    {
        $awsSqsService = new AwsSqsService();

        $messages = $awsSqsService->receive();

        foreach ($messages as $itens) {
            $awsSqsService->delete($itens['ReceiptHandle']);
        }

        return response()->json([
            'message' => 'Excluido com sucesso'
        ], 200);
    }

    public function getOrder(Request $request)
    {
        ini_set('max_execution_time', 1);
        
        $awsSqsService = new AwsSqsService();

        $messages = $awsSqsService->receive();

        if (empty($messages)) {
            return response()->json([
                'message' => 'Nenhum ID na fila'
            ], 200);
        }

        $message = '';

        for ($i = 0; $i < count($messages); $i++) {
            $itens = $messages[$i];

            $id = $itens['Body'];

            $etapa = Cache::get($id)['etapa'];

            $message .= 'ID: ' . $id;

            for ($x = $etapa; $x < 5; $x++) {
                switch ($x) {
                    case 0: 
                    case 1:
                    case 2:
                    case 3: 
                        $message .= ' | Etapa: ' . $x + 1;
                        $this->updateEtapa($id, $x + 1); 
                        break;
                    case 4: 
                        Cache::forget($id);
                        $awsSqsService->delete($itens['ReceiptHandle']);
                        break;
                }
            }
            
            if (!Cache::has($id)) {
                $message .= ' | terminou o processo';
            }
            $message .= '<br>';
        }

        return response()->json([
            'message' => $message
        ], 200);
    }

    protected function updateEtapa($id, $etapa)
    {
        Cache::put($id, ['etapa' => $etapa]);
    }
}