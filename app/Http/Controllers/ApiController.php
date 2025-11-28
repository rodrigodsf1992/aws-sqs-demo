<?php

namespace App\Http\Controllers;

use App\Http\Services\AwsSqsService;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use Illuminate\Support\Facades\Redis;

class ApiController extends Controller  
{
    public function createOrder(Request $request)
    {
        $awsSqsService = new AwsSqsService();

        $qtd = $request->input('qtd') ?? 100;

        $messages = [];
        for ($i = 0; $i < $qtd; $i++) {
            $uuid = Str::uuid()->toString();
            $expiresAt = Carbon::now()->addSeconds(60);
            Cache::put($uuid, ['etapa' => 0], $expiresAt);
            Redis::incr(env('QUEUE_SQS_TICKETS_NAME_TOTAL_NUMBER_MESSAGES_SQS', 'total_number_messages_sqs'));
            $messages[] = $uuid;
        }

        $messages = array_chunk($messages, env('QUEUE_SQS_TICKETS_MAX_MESSAGES', 10));
        $responses = [];
        foreach ($messages as $message) {
            $response = $awsSqsService->sendMessages($message);
            $responses = array_merge($responses, $response);
        }

        return response()->json([
            'message' => 'UUID enviado com sucesso',
            'failed' => $responses['failed'],
            'success' => $responses['success']
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