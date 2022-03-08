<?php

namespace ParallelCollection\Providers;

use Closure;
use Throwable;
use Amp\MultiReasonException;
use ParallelCollection\ParallelItemHandler;
use function Amp\Promise\wait;
use Amp\Parallel\Sync\ContextPanicError;
use Amp\Parallel\Worker\TaskFailureException;
use function Amp\ParallelFunctions\parallelMap;
use Illuminate\Support\{Collection, ServiceProvider};
use ParallelCollection\SerializerWrappers\{ItemSerializer, HandlerSerializer};
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
