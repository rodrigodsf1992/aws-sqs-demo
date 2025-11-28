<?php

namespace App\Http\Services;

use App\Http\Queue\AwsSqsAdapter;
use Aws\Sqs\SqsClient;

class AwsSqsService
{
    protected $adapter;

    protected $queueConfig;

    public function __construct()
    {
        $this->queueConfig = [
            'adapter' => [
                'region' => env('QUEUE_ADAPTER_SQS_REGION', 'us-east-1'),
                'verson' => env('QUEUE_ADAPTER_SQS_VERSION', 'latest'),
                'credentials' => [
                    'key' => env('QUEUE_ADAPTER_SQS_KEY'),
                    'secret' => env('QUEUE_ADAPTER_SQS_SECRET'),
                ],
            ],
            'type' => env('QUEUE_SQS_TICKETS_TYPE', 'fifo'),
            'default_group' => env('QUEUE_SQS_TICKETS_GROUP', 'default'),
            'max_number_of_messages' => (int) env('QUEUE_SQS_TICKETS_MAX_MESSAGES', 10),
            'wait_time_seconds' => (int) env('QUEUE_SQS_TICKETS_WAIT_TIME_SECS', 0),
            'url' => env('QUEUE_SQS_TICKETS_URL', 'https://sqs.us-east-1.amazonaws.com/667308168631/TicketsOrders.fifo'),
        ];
        $this->adapter = new AwsSqsAdapter(
            new SqsClient($this->queueConfig['adapter'])
        );
    }

    public function receive($delete = false): array
    {
        return $this->adapter->receive($this->queueConfig, $delete);
    }

    public function send($id, array $data = []): bool
    {
        return $this->adapter->send($id, $this->queueConfig, $data);
    }

    public function delete($handle): bool
    {
        return $this->adapter->delete($handle, $this->queueConfig);
    }

    public function total()
    {
        return $this->adapter->total($this->queueConfig);
    }

}
