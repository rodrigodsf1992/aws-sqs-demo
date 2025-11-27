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

    protected function updateEtapa($id, $etapa)
    {
        Cache::put($id, ['etapa' => $etapa]);
    }
}