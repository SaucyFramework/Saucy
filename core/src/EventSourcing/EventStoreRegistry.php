<?php

namespace Saucy\Core\EventSourcing;

use Saucy\MessageStorage\AllStreamMessageRepository;
use Saucy\MessageStorage\AllStreamReader;
use Saucy\MessageStorage\StreamReader;

final class EventStoreRegistry
{
    /**
     * @var array<string, AllStreamMessageRepository>
     */
    private array $stores = [];

    /**
     * @var array<string, StreamReader>
     */
    private array $streamReaders = [];

    /**
     * @var array<string, AllStreamReader>
     */
    private array $allStreamReaders = [];

    /**
     * @var array<string, \Saucy\MessageStorage\ReadEventData>
     */
    private array $readEventData = [];

    private ?string $defaultId = null;

    public function register(
        string $id,
        AllStreamMessageRepository $store,
        ?StreamReader $streamReader = null,
        ?AllStreamReader $allStreamReader = null,
        ?\Saucy\MessageStorage\ReadEventData $readEventData = null,
    ): void {
        $this->stores[$id] = $store;

        if ($streamReader !== null) {
            $this->streamReaders[$id] = $streamReader;
        } elseif ($store instanceof StreamReader) {
            $this->streamReaders[$id] = $store;
        }

        if ($allStreamReader !== null) {
            $this->allStreamReaders[$id] = $allStreamReader;
        } elseif ($store instanceof AllStreamReader) {
            $this->allStreamReaders[$id] = $store;
        }

        if ($readEventData !== null) {
            $this->readEventData[$id] = $readEventData;
        } elseif ($store instanceof \Saucy\MessageStorage\ReadEventData) {
            $this->readEventData[$id] = $store;
        }
    }

    public function setDefault(string $id): void
    {
        if (!isset($this->stores[$id])) {
            throw EventStoreNotFoundException::forStoreId($id, 'default store');
        }
        $this->defaultId = $id;
    }

    public function has(?string $id): bool
    {
        if ($id === null) {
            return $this->defaultId !== null || count($this->stores) > 0;
        }
        return isset($this->stores[$id]);
    }

    public function get(?string $id = null): AllStreamMessageRepository
    {
        $storeId = $id ?? $this->defaultId;
        if ($storeId === null) {
            // If no default is set but we have stores, use the first one
            if (count($this->stores) > 0) {
                return reset($this->stores);
            }
            throw new \Exception('No event store registered and no default store set.');
        }

        if (!isset($this->stores[$storeId])) {
            throw EventStoreNotFoundException::forStoreId($storeId);
        }

        return $this->stores[$storeId];
    }

    public function getStreamReader(?string $id = null): StreamReader
    {
        $storeId = $id ?? $this->defaultId;
        if ($storeId === null && count($this->streamReaders) > 0) {
            return reset($this->streamReaders);
        }

        if ($storeId !== null && !isset($this->streamReaders[$storeId])) {
            throw EventStoreNotFoundException::forStoreId($storeId, 'StreamReader');
        }

        if ($storeId === null) {
            throw new \Exception('No StreamReader available and no default store set.');
        }

        return $this->streamReaders[$storeId];
    }

    public function getAllStreamReader(?string $id = null): AllStreamReader
    {
        $storeId = $id ?? $this->defaultId;
        if ($storeId === null && count($this->allStreamReaders) > 0) {
            return reset($this->allStreamReaders);
        }

        if ($storeId !== null && !isset($this->allStreamReaders[$storeId])) {
            throw EventStoreNotFoundException::forStoreId($storeId, 'AllStreamReader');
        }

        if ($storeId === null) {
            throw new \Exception('No AllStreamReader available and no default store set.');
        }

        return $this->allStreamReaders[$storeId];
    }

    public function getReadEventData(?string $id = null): ?\Saucy\MessageStorage\ReadEventData
    {
        $storeId = $id ?? $this->defaultId;
        if ($storeId === null && count($this->readEventData) > 0) {
            return reset($this->readEventData);
        }

        if ($storeId !== null && isset($this->readEventData[$storeId])) {
            return $this->readEventData[$storeId];
        }

        return null;
    }

    public function getDefault(): AllStreamMessageRepository
    {
        if ($this->defaultId === null) {
            if (count($this->stores) === 0) {
                throw new \Exception('No event stores registered.');
            }
            return reset($this->stores);
        }
        return $this->get($this->defaultId);
    }
}

