<?php

namespace ParallelCollection\Tests\Feature;

use Exception;
use Throwable;
use ParallelCollection\Tests\TestCase;
use ParallelCollection\Tests\AppInitializer;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use ParallelCollection\SerializerWrappers\AppInitializer\AppInitializerContract;

/**
 * Class SampleTest
 * @package Fanout\Tests\Feature
 */
class ParallelHandlingTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(AppInitializerContract::class, AppInitializer::class);
    }

    /**
     * @test
     */
    public function canRunParallelActions()
    {
        $sleepTime = 3;
        $numTimers = 20;

        $items = array_pad([], $numTimers, function () use ($sleepTime) {
            $before = now();
            sleep($sleepTime);
            $after = now();

            return $after->diffInSeconds($before);
        });

        $before = now();
        collect($items)->mapToParallel()->toArray();
        $after = now();

        $elapsedTime = $after->diffInSeconds($before);
        $totalProcessTime = count($items) * $sleepTime;
        $performanceImprovement = 1 - $elapsedTime / $totalProcessTime;
        $expectedImprovement = 1 - $sleepTime / $totalProcessTime;
        $expectedImprovementPerItem = $expectedImprovement / count($items);
        $tolerance = .05;
        $expectedImprovementWithTolerance = $expectedImprovementPerItem * (1 - $tolerance) * count($items);

        self::assertGreaterThanOrEqual($expectedImprovementWithTolerance, $performanceImprovement);
    }

    /**
     * @test
     */
    public function canRunParallelActions2()
    {
        $sleepTime = 3;
        $items = ['Hello', 'World'];
        $testMessage = 'testing';
        $postResolution = null;

        $before = now();
        try {
            $results = collect($items)->mapToParallel(function (string $item) use ($sleepTime, $testMessage) {
                sleep($sleepTime);
                throw new Exception($testMessage);
                return $item;
            }, function (array $results, Throwable $exception = null) use (&$postResolution) {
                $postResolution = [$results, current($exception->getReasons())->getOriginalMessage()];

                return array_fill_keys(array_keys($results), 'testing');
            })->toArray();
        } catch (Throwable $exception) {
            //
        }
        $after = now();

        self::assertEquals($testMessage, $postResolution[1]);
        $placeholders = array_fill_keys(array_keys($items), null);
        self::assertEquals($placeholders, $postResolution[0]);

        $elapsedTime = $after->diffInSeconds($before);
        $totalProcessTime = count($items) * $sleepTime;
        $performanceImprovement = 1 - $elapsedTime / $totalProcessTime;
        $expectedImprovement = 1 - $sleepTime / $totalProcessTime;
        $expectedImprovementPerItem = $expectedImprovement / count($items);
        $tolerance = .05;
        $expectedImprovementWithTolerance = $expectedImprovementPerItem * (1 - $tolerance) * count($items);

        self::assertGreaterThanOrEqual($expectedImprovementWithTolerance, $performanceImprovement);
    }
}
