<?php

namespace ParallelCollection\SerializerWrappers;

use ParallelCollection\SerializerWrappers\AppInitializer\AppInitializerContract as AppInitializer;

/**
 * The App Container isn't part of the native scope to be included in serialization, so the parallel task
 * looses site of it and all abstraction bindings when it executes. There's too much complexity involved
 * in serializing an entire App instance. Easier to just spin up a new instance when the time comes,
 * similar to how Laravel handles isolated PhpUnit tests.
 */
class HandlerSerializer
{
    /**
     * @var callable|null
     */
    protected $handler;

    /**
     * @var AppInitializer
     */
    protected AppInitializer $appInitializer;

    /**
     * @param callable|null $handler
     * @param AppInitializer $appInitializer
     */
    public function __construct(?callable $handler, AppInitializer $appInitializer)
    {
        $this->handler = $handler ? ItemSerializer::make($handler) : $handler;
        $this->appInitializer = $appInitializer;
    }

    /**
     * @param string $item
     * @return mixed
     */
    public function __invoke(string $item)
    {
        $this->appInitializer->createApplication();
        $item = unserialize($item);

        //If the items are themselves callables, let them handle themselves
        return ($this->handler ?: fn (callable $item) => $item())($item);
    }
}
