<?php

namespace ParallelCollection\SerializerWrappers\AppInitializer;

use Illuminate\Contracts\Foundation\Application;

/**
 * The CreatesApplication trait may be in a different location depending on whether this is running in an app or
 * an Orchestra\Testbench-driven PhpUnit test. So this offers the opportunity to swap out the concrete implementation.
 */
interface AppInitializerContract
{
    /**
     * @return Application
     */
    public function createApplication();
}
