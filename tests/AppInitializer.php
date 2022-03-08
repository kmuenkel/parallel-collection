<?php

namespace ParallelCollection\Tests;

use Orchestra\Testbench\Concerns\CreatesApplication;
use ParallelCollection\SerializerWrappers\AppInitializer\AppInitializerContract;

class AppInitializer implements AppInitializerContract
{
    use CreatesApplication;
}
