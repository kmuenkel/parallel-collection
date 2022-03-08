<?php

namespace ParallelCollection\Tests;

use Throwable;
use PHPUnit\Util\Test;
use BadMethodCallException;
use Illuminate\Http\Request;
use Faker\Generator as Faker;
use Illuminate\Routing\Router;
use Illuminate\Config\Repository;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\Exceptions\Handler;
use Illuminate\Validation\ValidationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Orchestra\Testbench\TestCase as BaseTestCase;
use ParallelCollection\Providers\ParallelCollectionProvider;
use Illuminate\Contracts\Routing\{BindingRegistrar, Registrar};

/**
 * Class TestCase
 * @package Tests
 */
class TestCase extends BaseTestCase
{
    /**
     * @var Faker
     */
    protected $faker;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        parent::setUp();
//        $this->artisan('vendor:publish', ['--all' => true, '--force' => true]);

        static::generateAppKey($this->app);
        $this->faker = app(Faker::class);
    }

    /**
     * @param Application $app
     * @return string
     */
    public static function generateAppKey(Application $app): string
    {
        /** @var Repository $config singleton */
        $config = $app->make('config');

        if ($key = !$config->get('app.key')) {
            $key = 'base64:' . base64_encode(Encrypter::generateKey($config->get('app.cipher')));
            $config->set('app.key', $key);
        }

        return $key;
    }

    /**
     * @param Application $app
     */
    protected function resolveApplicationExceptionHandler($app)
    {
        $app->singleton(ExceptionHandler::class, function (...$args) {
            return new class(...$args) extends Handler
            {
                /**
                 * @param Request $request
                 * @param Throwable $e
                 * @return mixed
                 */
                public function render($request, Throwable $e)
                {
                    $request->headers->set('Accept', 'application/json');

                    $debug = $e instanceof ValidationException ? [
                        'errors' => $e->errors(),
                        'data' => $e->validator->getData()
                    ] : [];

                    return parent::render($request, $e)->setContent(json_encode([
                        'type' => get_class($e),
                        'code' => $e->getCode(),
                        'message' => $e->getMessage(),
                        'debug' => $debug,
                        'trace' => $e->getTrace()
                    ]));
                }
            };
        });
    }

    /**
     * This only seems to be an issue when run in GitLab CI Jobs
     * @inheritDoc
     * @link https://github.com/orchestral/testbench/issues/132#issuecomment-252438072 IMS Global Documentation
     */
    protected function getPackageAliases($app)
    {
        return [
            'routes' => [Router::class, Registrar::class, BindingRegistrar::class]
        ];
    }

    /**
     * @inheritDoc
     */
    protected function getPackageProviders($app)
    {
        return [ParallelCollectionProvider::class];
    }

    /**
     * @return array
     */
    public function patchGetAnnotations() : array
    {
        return Test::parseTestMethodAnnotations(static::class, $this->getName());
    }

    /**
     * Address compatibility issues between Orchestra\Testbench and PHPUnit\Framework.
     * @param string $name
     * @param array $arguments
     * @return array
     */
    public function __call(string $name, array $arguments = [])
    {
        if ($name == 'getAnnotations') {
            return $this->patchGetAnnotations();
        }

        throw new BadMethodCallException("Undefined method ' $name'.");
    }
}
