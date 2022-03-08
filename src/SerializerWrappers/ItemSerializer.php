<?php

namespace ParallelCollection\SerializerWrappers;

use Laravel\SerializableClosure\SerializableClosure;
use Illuminate\Queue\SerializesAndRestoresModelIdentifiers;
use Laravel\SerializableClosure\Exceptions\PhpVersionNotSupportedException;

class ItemSerializer
{
    use SerializesAndRestoresModelIdentifiers;

    /**
     * @var callable
     */
    protected $job;

    /**
     * @param callable $job
     */
    public function __construct(callable $job)
    {
        $this->job = $job;

        $this->prepareForModelSerialization();
    }

    /**
     * @return void
     */
    protected function prepareForModelSerialization()
    {
        SerializableClosure::transformUseVariablesUsing(function ($data) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->getSerializedPropertyValue($value);
            }

            return $data;
        });

        SerializableClosure::resolveUseVariablesUsing(function ($data) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->getRestoredPropertyValue($value);
            }

            return $data;
        });
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
     * @throws PhpVersionNotSupportedException
     */
    public function __serialize(): array
    {
        return ['job' => new SerializableClosure($this->job)];
    }

    /**
     * @param array $data
     * @return void
     */
    public function __unserialize(array $data): void
    {
        $this->job = $data['job'];
    }

    /**
     * @return mixed
     * @throws PhpVersionNotSupportedException
     */
    public function __invoke()
    {
        return ($this->job)();
    }
}
