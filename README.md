# ParallelCollection
A Laravel wrapper for [amphp/amp](https://github.com/amphp/amp) offering a Collection Macro that performs parallel processing.

There is a similar package called [spatie/laravel-collection-macros](https://github.com/spatie/laravel-collection-macros#parallelmap) that attempts this, but falls short when the parallel Closure attempts to leverage Laravel abstractions or Model instances. The problem is that, similar to isolated PhpUnit tests, the App needs to be reinitialized when the Closure is underialized for execution. Additionally, serializing and unserializing Model classes is not a straight-forward process, because there are database connections that need to be reestablished. Laravel has already solved this problem though, in the context of serializing Closures for Queued Jobs. So both issues are answerable using existing traits from the framework.

Note that although AmPhp this offers true parallel processing, the parent thread still needs to wait for all children to resolve before closing out. Similar to forking, this seems to be a limitation of Php itself, that if a child task is permitted to resolve _after_ the parent closes, either the parent will attempt to terminate the child prematurely, or the child thread will never be instructed to release its resources after it's done executing, resulting in a zombie process. If the intent is to fire a process intended to resolve later and not make the parent wait around for it, you'll still have to use something like a [Queued Job](https://laravel.com/docs/9.x/queues). However, a combination of a queuing and this parallel processor can get the best of both worlds and be extremely performant.

## Usage

### Parallel Closures

If the items to be handled in parallel are themselves closures, no special treatment is needed.

```php

$process1 = function () {
    sleep(5);   //Simulate taking a long time to handle the item
    return 'perform some long process';
};

$process2 = function () {
    sleep(5);   //Simulate taking a long time to handle the item
    return 'perform another long process';
};

$items = compact('process1', 'process1');

$before = now();
$results = collect($items)->mapToParallel()->toArray();
$after = now();

$elapsedTime = $after->diffInSeconds($before);
print_r(compact('results', 'elapsedTime'));

/**
 * Array:
 * (
 *    "results" => Array
 *    (
 *       [0] => 'perform some long process'
 *       [1] => 'perform another long process'
 *    )
 *    "elapsedTime" => 5
 * )
 */
```

### Parallel items with handler

If your items are not closures, but instead need one to act on them, the example would look like this:

```php
$items = ['Hello', 'World'];
$handler = function (string $item) {
    sleep(5);   //Simulate taking a long time to handle the item

    return $item;
};

$before = now();
$results = collect($items)->mapToParallel($handler)->toArray();
$after = now();

$elapsedTime = $after->diffInSeconds($before);
print_r(compact('results', 'elapsedTime'));

/**
 * Array:
 * (
 *    "results" => Array
 *    (
 *       [0] => 'Hello'
 *       [1] => 'World'
 *    )
 *    "elapsedTime" => 5
 * )
 */
```
