<?php

namespace Saucy\Core\Subscriptions\AllStream;

use Saucy\Core\Serialisation\TypeMap;
use Saucy\Core\Subscriptions\Checkpoints;
use Saucy\Core\Subscriptions\Checkpoints\CheckpointStore;
use Saucy\Core\Subscriptions\ConsumePipe;
use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumeContext;
use Saucy\Core\Subscriptions\Metrics\ActivityStreamLogger;
use Saucy\Core\Subscriptions\Metrics\SubscriptionActivity;
use Saucy\Core\Subscriptions\StreamOptions;
use Saucy\MessageStorage\AllStreamQuery;
use Saucy\MessageStorage\AllStreamReader;
use Saucy\MessageStorage\Serialization\EventSerializer;
use Saucy\MessageStorage\Serialization\SerializationResult;
use Saucy\MessageStorage\StoredEvent;

final readonly class AllStreamSubscription
{
    public function __construct(
        public string $subscriptionId,
        public StreamOptions $streamOptions,
        public ConsumePipe $consumePipe,
        public AllStreamReader $eventReader,
        public EventSerializer $eventSerializer,
        public CheckpointStore $checkpointStore,
        public TypeMap $streamNameTypeMap,
        public ActivityStreamLogger $activityStreamLogger,
    ) {}

    public function poll(int $timeoutInSeconds = 100): int
    {
        $log = [];
        $this->appendToActivity($log, 'started_poll', 'started poll');
        $startTime = time();
        try {
            $checkpoint = $this->checkpointStore->get($this->subscriptionId);
        } catch (Checkpoints\CheckpointNotFound $e) {
            $checkpoint = new Checkpoints\Checkpoint($this->subscriptionId, $this->streamOptions->startingFromPosition);
        }

        $maxPosition = $this->eventReader->maxEventId();

        $this->appendToActivity($log, 'loading_events', 'loading events', [
            'fromPosition' =>  $checkpoint->position,
            'limit' =>  $this->streamOptions->pageSize,
            'eventTypes' =>  $this->streamOptions->eventTypes,
        ]);

        $storedEvents = $this->eventReader->paginate(
            new AllStreamQuery(
                fromPosition: $checkpoint->position,
                limit: $this->streamOptions->pageSize,
                eventTypes: $this->streamOptions->eventTypes,
            ),
        );

        $this->appendToActivity($log, 'loaded_events', 'loaded events', []);

        $messageCount = 0;
        $lastCommit = 0;

        $queueTimedOut = false;

        $processBatches = $this->consumePipe->canHandleBatches();
        if($processBatches) {
            $this->consumePipe->beforeHandlingBatch();
        }

        $this->storeLog($log);

        $lastProcessedEvent = null;

        $timePerMessageType = [];

        foreach ($storedEvents as $storedEvent) {
            if(time() - $startTime >= $timeoutInSeconds) {
                $queueTimedOut = true;
                $this->appendToActivity($log, 'queue_timeout', 'queue timeout', []);
                break;
            }
            $startTimeHandleMessage = microtime(true);

            $this->consumePipe->handle($this->storedMessageToContext($storedEvent));

            $processingTime = microtime(true) - $startTimeHandleMessage;

            if(!array_key_exists($storedEvent->eventType, $timePerMessageType)) {
                $timePerMessageType[$storedEvent->eventType] = [
                    'count' => 0,
                    'total_time' => 0,
                    'max_time' => 0,
                ];
            }

            $timePerMessageType[$storedEvent->eventType]['count']++;
            $timePerMessageType[$storedEvent->eventType]['total_time'] += $processingTime;
            $timePerMessageType[$storedEvent->eventType]['max_time'] = max($timePerMessageType[$storedEvent->eventType]['max_time'], $processingTime);

            $lastProcessedEvent = $storedEvent;
            $messageCount += 1;

            if($processBatches) {
                continue;
            }

            // if batch size reached, commit
            if($messageCount % $this->streamOptions->commitBatchSize === 0) {
                $this->appendToActivity($log, 'store_checkpoint', 'store checkpoint', [
                    'position' => $lastProcessedEvent->globalPosition,
                    'messages_processed' => $timePerMessageType,
                ]);
                $this->checkpointStore->store($checkpoint->withPosition($lastProcessedEvent->globalPosition));
                $this->storeLog($log);
                $lastCommit = $lastProcessedEvent->globalPosition;
                $timePerMessageType = [];
            }
        }

        if($processBatches) {
            $this->consumePipe->afterHandlingBatch();
        }

        if(isset($lastProcessedEvent) && $lastCommit !== $lastProcessedEvent->globalPosition) {
            $this->appendToActivity($log, 'store_checkpoint', 'store checkpoint, end of loop', [
                'position' => $lastProcessedEvent->globalPosition,
                'messages_processed' => $timePerMessageType,
            ]);
            $this->checkpointStore->store($checkpoint->withPosition($lastProcessedEvent->globalPosition));
        }

        if($messageCount === 0 && !$queueTimedOut) {
            $this->appendToActivity($log, 'store_checkpoint', 'store checkpoint, 0 handled', [
                'position' => $maxPosition,
                'messages_processed' => $timePerMessageType,
            ]);
            $this->checkpointStore->store($checkpoint->withPosition($maxPosition));
        }

        $this->storeLog($log);
        return $messageCount;
    }

    public function prepareForReplay(): void
    {
        $log = [];
        $this->appendToActivity($log, 'prepare_replay', 'prepare replay');
        $this->consumePipe->prepareReplay();
        $this->appendToActivity($log, 'store_checkpoint', 'store checkpoint', [
            'position' => $this->streamOptions->startingFromPosition,
            'reason' => 'replay',
        ]);
        $checkpoint = new Checkpoints\Checkpoint($this->subscriptionId, $this->streamOptions->startingFromPosition);
        $this->checkpointStore->store($checkpoint);
        $this->storeLog($log);
    }

    private function storedMessageToContext(StoredEvent $storedEvent): MessageConsumeContext
    {
        $payload = $this->eventSerializer->deserialize(
            new SerializationResult(
                eventType: $storedEvent->eventType,
                payload: $storedEvent->payloadJson,
            ),
        );

        /** @var array<string, mixed> $metaData */
        $metaData = json_decode($storedEvent->metadataJson, true);
        return new MessageConsumeContext(
            eventId: $storedEvent->eventId,
            subscriptionId: $this->subscriptionId,
            streamNameType: $storedEvent->streamNameType,
            streamType: $storedEvent->streamType,
            streamNameAsString: $storedEvent->streamName,
            streamName: $this->streamNameTypeMap->typeToClassName($storedEvent->streamNameType)::fromString($storedEvent->streamName),
            eventClass: get_class($payload),
            eventType: $storedEvent->eventType,
            event: $payload,
            metaData: $metaData,
            streamPosition: $storedEvent->streamPosition,
            globalPosition: $storedEvent->globalPosition,
        );
    }

    private function appendToActivity(array &$log, string $type, string $message, array $data = []): void
    {
        $log[] = new SubscriptionActivity(
            streamId: $this->subscriptionId,
            type: $type,
            message: $message,
            occurredAt: new \DateTime('now'),
            data: $data,
        );
    }

    private function storeLog(array &$log): void
    {
        $this->activityStreamLogger->log(...$log);
        $log = [];
    }


}
