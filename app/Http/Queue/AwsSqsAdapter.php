<?php

namespace App\Http\Queue;

use Aws\Exception\AwsException;
use Aws\Sqs\SqsClient;
use App\Http\Queue\Interface\AdapterInterface;

class AwsSqsAdapter implements AdapterInterface
{
    /**
     * 
     * @var SqsClient
     */
    protected $client;

    public function __construct(SqsClient $client)
    {
        $this->client = $client;
    }

    public function send($id, array $conf, array $data = []): bool
    {
        try {

            $attributes = (array) $data;

            $attributes['uuid'] = [
                'DataType' => 'String',
                'StringValue' => $id
            ];

            $params = [
                'QueueUrl' => $conf['url'],
                'MessageBody' => $id,
                'MessageDeduplicationId' => $id,
            ];

            $params['MessageAttributes'] = $attributes;

            if ($conf['type'] == 'fifo') {
                $params['MessageGroupId'] = $id;
            }

            $result = $this->client->sendMessage($params);

            if (!empty($result->get('MessageId'))) {
                return true;
            }

            return false;

        } catch (AwsException $ex) {
            throw $ex;
        }
    }

    public function receive(array $conf, $delete = false): array
    {
        $result = [];

        try {

            $params = [
                'AttributeNames' => ['uuid'],
                'MaxNumberOfMessages' => $conf['max_number_of_messages'],
                'MessageAttributeNames' => ['All'],
                'QueueUrl' => $conf['url'],
                'WaitTimeSeconds' => $conf['wait_time_seconds'],
            ];

            $aux = $this->client->receiveMessage($params);

            $messages = $aux->get('Messages');

            if (empty($messages)) {
                return $result;
            }

            $result = $messages;

            if (!$delete) {
                return $result;
            }

            foreach ($messages as $message) {
                $this->delete($message['ReceiptHandle'], $conf);
            }

            return $result;

        } catch (AwsException $ex) {
            throw $ex;
        }
    }

    public function delete($handle, array $conf): bool
    {
        try {

            $params = [
                'QueueUrl' => $conf['url'],
                'ReceiptHandle' => $handle
            ];

            $this->client->deleteMessage($params);
            return true;

        } catch (AwsException $ex) {
            throw $ex;
        }
    }

    public function total(array $conf)
    {
        try {

            $params = [
                'QueueUrl' => $conf['url'],
                'AttributeNames' => [
                    'ApproximateNumberOfMessages',
                    'ApproximateNumberOfMessagesNotVisible',
                    'ApproximateNumberOfMessagesDelayed',
                ],
            ];
            $result = $this->client->getQueueAttributes($params);

            $approximateNumberOfMessages = $result['Attributes']['ApproximateNumberOfMessages'] ?? 0;
            $approximateNumberOfMessagesNotVisible = $result['Attributes']['ApproximateNumberOfMessagesNotVisible'] ?? 0;
            $approximateNumberOfMessagesDelayed = $result['Attributes']['ApproximateNumberOfMessagesDelayed'] ?? 0;

            return $approximateNumberOfMessages + $approximateNumberOfMessagesNotVisible + $approximateNumberOfMessagesDelayed;
        } catch (AwsException $ex) {
            throw $ex;
        }
    }
}