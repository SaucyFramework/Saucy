<?php

namespace Saucy\Core\Subscriptions;

use EventSauce\EventSourcing\MessageDispatcher;
use Generator;
use Saucy\Core\Events\Streams\StreamEvent;
use Saucy\Core\Serialisation\TypeMap;
use Saucy\Core\Subscriptions\Checkpoints\CheckpointCommitter;
use Saucy\Core\Subscriptions\Checkpoints\CheckpointStore;
use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumeContext;
use Saucy\Ids\Ulid;
use Saucy\MessageStorage\AllStreamMessageRepository;
use Saucy\MessageStorage\AllStreamQuery;
use Saucy\MessageStorage\StoredEvent;

final readonly class AllStreamSubscription
{
    public function __construct(
        public string $subscriptionId,
        public StreamOptions $streamOptions,
        public ConsumePipe $consumePipe,
        public AllStreamMessageRepository $messageRepository,
        public CheckpointStore $checkpointStore,
        public StreamProcessManager $streamProcessManager,
    )
    {
    }

    public function poll(): void
    {
        $processId = Ulid::generate()->toString();
        // get lock on process
        $this->streamProcessManager->start($this->subscriptionId, $processId);

        try {
            $checkpoint = $this->checkpointStore->get($this->subscriptionId);
        } catch (Checkpoints\CheckpointNotFound $e) {
            $checkpoint = new Checkpoints\Checkpoint($this->subscriptionId, $this->streamOptions->startingFromPosition);
        }

        // moved to setup
//        $eventTypes = null;
//        if($this->messageDispatcher instanceof ListsEventsItCanConsume){
//            $eventTypes = array_map(fn(string $eventClass) => $this->typeMap->classNameToType($eventClass), $this->messageDispatcher->getEventClasses());
//        }

        $messages = $this->messagesToEvents($this->messageRepository->paginate(
            new AllStreamQuery(
                fromPosition: $checkpoint->position,
                limit: $this->streamOptions->pageSize,
                eventTypes: $this->streamOptions->eventTypes
            )
        ));

        $messageCount = 0;

        foreach ($messages as $message) {
            $this->consumePipe->handle($this->storedMessageToContext($message));
            $messageCount += 1;
            // if batch size reached, commit
            if($messageCount === $this->streamOptions->commitBatchSize){
                $this->checkpointStore->store($checkpoint->withPosition($message->position));
                $messagesToSend = [];
            }
        }

    }

    private function storedMessageToContext(StoredEvent $storedEvent): MessageConsumeContext
    {
        return new MessageConsumeContext(
            eventId: $storedEvent->eventId,
            subscriptionId: 'subscriptionId',
            streamName: '',
            eventClass: '',
            eventType: '',
            event: '',
            metaData: '',
            streamPosition: '',
            globalPosition: '',
        );
    }
}
