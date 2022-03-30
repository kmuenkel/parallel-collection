<?php

namespace ParallelCollection;

use Closure;
use Throwable;
use Amp\MultiReasonException;
use function Amp\Promise\wait;
use Amp\Parallel\Sync\ContextPanicError;
use Amp\Parallel\Worker\TaskFailureThrowable;
use function Amp\ParallelFunctions\parallelMap;
use ParallelCollection\SerializerWrappers\{ItemSerializer, HandlerSerializer};

class ParallelItemHandler
{
    /**
     * @var bool    Set this to true for testing purposes
     */
    public static bool $sync = false;

    /**
     * @var array
     */
    protected array $items;

    public function __construct(array $items)
    {
        $this->items = $items;
    }

    /**
     * @param callable|null $handler
     * @param callable|null $resolver
     * @return array
     * @throws Throwable
     */
    public function execute(callable $handler = null, callable $resolver = null): array
    {
        $handler = app(HandlerSerializer::class, compact('handler'));
        $resolver = $this->makeResolver($resolver);
        $items = $this->serializeItems();

        try {
            if (static::$sync) {
                return $resolver(null, array_map(fn (array $item) => $handler($item), $items));
            }

            $promise = parallelMap($items, $handler);
            $promise->onResolve($resolver);

            return wait($promise);
        } catch (Throwable $exception) {
            $this->makeLogger()($exception);

            throw $exception;
        }
    }

    /**
     * The AsyncWrapper leverages the same trait Laravel uses to serializes Queued Jobs, reestablishing Model
     * connections afterwards. However, when Amphp unserializes a job-closure, it loses awareness of any
     * SerializableClosure resolver configurations, similar to the issue above in how it loses site
     * of the entire framework. So Models included among the static variables don't get converted back from
     * ModelIdentifiers to Models, unless we pre-emptively serialize the item here, so that the $handler may
     * unserialize it after the closure has been unserialized and the App reestablished.
     *
     * @return array{ItemSerializer|mixed, mixed}[]
     */
    protected function serializeItems(): array
    {
        $serialize = function ($value, $key): array {
            $request = request();
            $request = [
                'query' => $request->query,
                'attributes' => $request->attributes,
                'request' => $request->request,
                'headers' => $request->headers,
                'server' => $request->server,
                'files' => $request->files,
                'cookies' => $request->cookies,
                'json' => $request->json(),
                'method' => $request->method(),
                'route_resolver' => ItemSerializer::make($request->getRouteResolver()),
                'user_resolver' => ItemSerializer::make($request->getUserResolver()),
                'session' => $request->getSession(),
                'locale' => $request->getLocale()
            ];

            $value = serialize($value instanceof Closure ? ItemSerializer::make($value) : $value);

            return compact('value', 'key', 'request');
        };

        return array_map($serialize, $this->items, array_keys($this->items));
    }

    /**
     * @param callable|null $resolver
     * @return ItemSerializer
     */
    protected function makeResolver(?callable $resolver): ItemSerializer
    {
        $placeHolders = array_fill_keys(array_keys($this->items), null);
        $reasonLogger = $this->makeLogger();

        $resolver = function (?Throwable $exception, $values) use ($resolver, $reasonLogger, $placeHolders) {
            $reasonLogger($exception);
            //If something's gone wrong,$values will be null instead of an array of results. So this transformation
            //will allow the $resolver to not worry about array type-hint issues or missing keys.
            $values = (array)$values + $placeHolders;

            return $resolver ? $resolver($values, $exception) : $values;
        };

        return ItemSerializer::make($resolver);
    }

    /**
     * @return Closure
     */
    protected function makeLogger(): Closure
    {
        //MultiReasonException is a collection of Exceptions from parallel tasks, so let's make sure those details
        //make it somewhere useful.
        return function (?Throwable $exception) {
            if ($exception instanceof MultiReasonException) {
                array_map(function (Throwable $exception) {
                    $error = get_class($exception).': '
                        .'('.$exception->getCode().'): '
                        .$exception->getMessage().': '
                        .$exception->getTraceAsString();

                    if ($exception instanceof TaskFailureThrowable || $exception instanceof ContextPanicError) {
                        $error = get_class($exception).': '.
                            $exception->getOriginalClassName()
                            .'('.$exception->getOriginalCode().'): '
                            .$exception->getOriginalMessage().': '
                            .$exception->getOriginalTraceAsString();
                    }

                    logger()->error($error);
                }, $exception->getReasons());
            }
        };
    }
}
