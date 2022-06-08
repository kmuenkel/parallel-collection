<?php

namespace ParallelCollection\SerializerWrappers;

use Closure;
use Laravel\SerializableClosure\SerializableClosure;
use Illuminate\Queue\SerializesAndRestoresModelIdentifiers;
use Opis\Closure\SerializableClosure as OpisSerializableClosure;
use Laravel\SerializableClosure\Exceptions\PhpVersionNotSupportedException;

class ItemSerializer
{
    use SerializesAndRestoresModelIdentifiers {
        getSerializedPropertyValue as getSerializedModel;
        getRestoredPropertyValue as getRestoredModel;
    }

    /**
     * @var callable
     */
    protected $job;

    /**
     * @var array
     */
    protected array $serialized = [];

    /**
     * @param callable $job
     */
    public function __construct(callable $job)
    {
        $this->job = $job;
    }

    /**
     * Workaround for how SerializableClosure doesn't trigger __sleep() or __serialize() on objects in 'use'
     * @param $value
     * @return string
     */
    protected function getSerializedPropertyValue($value): string
    {
        $value = $this->getSerializedModel($value);

        return \Opis\Closure\serialize(static::makeSerializable($value));
    }

    /**
     * @param mixed $value
     * @return mixed|ItemSerializer
     */
    public static function makeSerializable($value)
    {
        $value = $value instanceof Closure ? static::make($value) : $value;
        $value = is_array($value) ? array_map([static::class, __FUNCTION__], $value) : $value;

        return $value;
    }

    /**
     * @param mixed $value
     * @return callable|mixed
     */
    public static function deserialize($value)
    {
        $value = $value instanceof static ? $value->getJob() : $value;
        $value = is_array($value) ? array_map([static::class, __FUNCTION__], $value) : $value;

        return $value;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    protected function getRestoredPropertyValue($value)
    {
        $value = $this->getRestoredModel(\Opis\Closure\unserialize($value));

        return static::deserialize($value);
    }

    /**
     * @param callable $job
     * @return static
     */
    public static function make(callable $job): self
    {
        return new static($job);
    }

    /**
     * @return callable
     */
    public function getJob(): callable
    {
        return $this->job;
    }

    /**
     * @throws PhpVersionNotSupportedException
     */
    public function __serialize(): array
    {
        SerializableClosure::transformUseVariablesUsing(function (array $data): array {
            return array_map(fn ($value) => $this->getSerializedPropertyValue($value), $data);
        });

        $job = $this->job instanceof OpisSerializableClosure ? $this->job->getClosure() : $this->job;

        return ['job' => serialize(new SerializableClosure($job))];
    }

    /**
     * @param array $data
     * @return void
     * @throws PhpVersionNotSupportedException
     */
    public function __unserialize(array $data): void
    {
        SerializableClosure::resolveUseVariablesUsing(function (array $data): array {
            return array_map(fn ($value) => $this->getRestoredPropertyValue($value), $data);
        });

        $this->job = unserialize($data['job']);
        //Simplify the stack-trace a little
        $this->job = $this->job instanceof SerializableClosure ? $this->job->getClosure() : $this->job;
    }

    /**
     * @param ...$args
     * @return mixed
     * @throws PhpVersionNotSupportedException
     */
    public function __invoke(...$args)
    {
        return ($this->job)(...$args);
    }
}
