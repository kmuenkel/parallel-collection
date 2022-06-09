<?php

namespace ParallelCollection\SerializerWrappers;

use Throwable;
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
        $this->handler = $handler;
        $this->appInitializer = $appInitializer;
    }

    /**
     * @param array $item
     * @return mixed
     */
    public function __invoke(array $item)
    {
        $app = $this->appInitializer->createApplication();

        //Bindings don't only occur in the booted service providers. PhpUnit for example, may attempt to override them
        array_map(function (array $binding, string $abstraction) use ($app) {
            $concrete = ItemSerializer::deserialize(\Opis\Closure\unserialize($binding['concrete']));

            try {
                $app->bind($abstraction, $concrete, $binding['shared']);
            } catch (Throwable $exception) {
                //
            }
        }, $item['bindings'], array_keys($item['bindings']));

        $item['request'] = \Opis\Closure\unserialize($item['request']);

        //request() returns a singleton, so we can reestablish the original request from prior to app reinitialization
        request()->query = $item['request']['query'];
        request()->attributes = $item['request']['attributes'];
        request()->request = $item['request']['request'];
        request()->headers = $item['request']['headers'];
        request()->server = $item['request']['server'];
        request()->files = $item['request']['files'];
        request()->cookies = $item['request']['cookies'];
        request()->setMethod($item['request']['method']);
        request()->setJson($item['request']['json']);
        request()->setRouteResolver($item['request']['route_resolver']);
        request()->setUserResolver($item['request']['user_resolver']);
        request()->setLaravelSession($item['request']['session']);
        request()->setLocale($item['request']['locale']);

        //If the items are themselves callables, let them handle themselves
        $value = \Opis\Closure\unserialize($item['value']);
        $key = $item['key'];
        $handler = $this->handler ?: fn (callable $item) => $item();

        return $handler($value, $key);
    }

    /**
     * @return array
     */
    public function __serialize(): array
    {
        return [
            'appInitializer' => $this->appInitializer,
            'handler' => \Opis\Closure\serialize($this->handler)
        ];
    }

    /**
     * @param array $data
     * @return void
     */
    public function __unserialize(array $data): void
    {
        $this->appInitializer = $data['appInitializer'];
        $this->handler = \Opis\Closure\unserialize($data['handler']);
    }
}
