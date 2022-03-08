<?php

namespace ParallelCollection\Tests\Feature;

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

        $timers = array_pad([], $numTimers, function () use ($sleepTime) {
            $before = now();
            sleep($sleepTime);
            $after = now();

            return $after->diffInSeconds($before);
        });

        $before = now();
        collect($timers)->mapToParallel()->toArray();
        $after = now();

        $elapsedTime = $after->diffInSeconds($before);
        $totalProcessTime = $numTimers * $sleepTime;
        $performanceImprovement = 1 - $elapsedTime / $totalProcessTime;
        $expectedImprovement = 1 - $sleepTime / $totalProcessTime;
        $expectedImprovementPerItem = $expectedImprovement / $numTimers;

        $tolerance = .05;
        $expectedImprovementWithTolerance = $expectedImprovementPerItem * (1 - $tolerance) * $numTimers;

        self::assertGreaterThanOrEqual($expectedImprovementWithTolerance, $performanceImprovement);
    }
}
