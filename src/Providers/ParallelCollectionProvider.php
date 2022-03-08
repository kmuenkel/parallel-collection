<?php

namespace ParallelCollection\Providers;

use Throwable;
use ParallelCollection\ParallelItemHandler;
use Illuminate\Support\{Collection, ServiceProvider};
use ParallelCollection\SerializerWrappers\AppInitializer\{AppInitializer, AppInitializerContract};

class ParallelCollectionProvider extends ServiceProvider
{
    /**
     * @inheritDoc
     */
    public function register()
    {
        $this->app->bind(AppInitializerContract::class, AppInitializer::class);
    }

    /**
     * @return void
     * @throws Throwable
     */
    public function boot()
    {
        $mapToParallel = function (callable $handler = null, callable $resolver = null): Collection {
            /** @var Collection $this */
            $items = $this->all();
            $parallel = app(ParallelItemHandler::class, compact('items'));
            $results = $parallel->execute($handler, $resolver);

            return collect($results);
        };

        Collection::macro('mapToParallel', $mapToParallel);
    }
}
