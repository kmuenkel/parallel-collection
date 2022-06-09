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
     * @var string[]
     */
    public static array $cantSerialize = [
        'Illuminate\Foundation\PackageManifest',
        'url',
        'encrypter',
        'filesystem.disk',
        'filesystem.cloud',
        'Illuminate\Testing\ParallelTesting',
        'view',
        'view.engine.resolver',
        'Facade\IgnitionContracts\SolutionProviderRepository',
        'Facade\Ignition\IgnitionConfig',
        'Facade\FlareClient\Flare',
        'Asm89\Stack\CorsService',
        'League\OAuth2\Server\AuthorizationServer',
        'League\OAuth2\Server\ResourceServer',
        'flare.logger',
        'queue.failer',
        'Facade\Ignition\DumpRecorder\MultiDumpHandler',
        'Illuminate\Console\Scheduling\Schedule',
        'command.ide-helper.generate',
        'command.ide-helper.meta',
        'Illuminate\Console\OutputStyle'
    ];

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
                $items = array_map(fn ($item) => unserialize(serialize($item)), $items);

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
     * @return array[]
     */
    protected function serializeItems(): array
    {
        static $bindings = null;
        static $request = null;

        $bindings = $bindings ?: array_filter(array_map(function (array $binding, string $abstraction): ?array {
            //Even with Opis's help, some things can't be serialized, and may cause the script to hang rather than fail
            //if attempted. So just preemptively skip those. opis\closure v4.x will fix a lot of quirks when it's done.
            if (in_array($abstraction, static::$cantSerialize)) {
                return null;
            }

            try {
                $binding['concrete'] = \Opis\Closure\serialize(ItemSerializer::makeSerializable($binding['concrete']));
            } catch (\Throwable $exception) {
                //Not everything is serializable, and that's ok. When the framework is spun up on the other side to
                //reestablish the closure job's access to its abstractions, its default settings will be permitted to
                //stand. Nothing short of opis\closure v4.x that literally rewrites the "Closure" parent class will
                //be a 100% solution, so this will just get us as close as we can.
                return null;
            }

            return $binding;
        }, $bindings = app()->getBindings(), array_keys($bindings)));

        $request = $request ?: \Opis\Closure\serialize([
            'query' => request()->query,
            'attributes' => request()->attributes,
            'request' => request()->request,
            'headers' => request()->headers,
            'server' => request()->server,
            'files' => request()->files,
            'cookies' => request()->cookies,
            'json' => request()->json(),
            'method' => request()->method(),
            'route_resolver' => request()->getRouteResolver(),
            'user_resolver' => request()->getUserResolver(),
            'session' => request()->getSession(),
            'locale' => request()->getLocale()
        ]);

        $serialize = function ($value, $key) use ($bindings, $request): array {
            $value = \Opis\Closure\serialize($value);

            return compact('value', 'key', 'request', 'bindings');
        };

        $keys = array_keys($this->items);
        $items = array_map($serialize, $this->items, $keys);

        return array_combine($keys, $items);
    }

    /**
     * @param callable|null $resolver
     * @return callable
     */
    protected function makeResolver(?callable $resolver): callable
    {
        $placeHolders = array_fill_keys(array_keys($this->items), null);
        $reasonLogger = $this->makeLogger();

        return function (?Throwable $exception, $values) use ($resolver, $reasonLogger, $placeHolders) {
            $reasonLogger($exception);
            //If something's gone wrong, $values will be null instead of an array of results. So this transformation
            //will allow the $resolver to not worry about array type-hint issues or missing keys.
            $values = (array)$values + $placeHolders;

            return $resolver ? $resolver($values, $exception) : $values;
        };
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
