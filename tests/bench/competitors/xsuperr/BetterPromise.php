<?php

namespace xSuperr\Promise;

use Closure;
use pocketmine\promise\PromiseSharedData;
use function spl_object_id;

/**
 * @phpstan-template TValue
 */
final class BetterPromise{
    /**
     * @internal Do NOT call this directly; create a new Resolver and call Resolver->promise()
     * @see PromiseResolver
     * @phpstan-param PromiseSharedData<TValue> $shared
     */
    public function __construct(private PromiseSharedData $shared){}

    public function then(Closure $then): self
    {
        $state = $this->shared->state;
        if($state === true){
            $then($this->shared->result);
        } else{
            $this->shared->onSuccess[spl_object_id($then)] = $then;
        }
        return $this;
    }

    public function fail(Closure $fail): self
    {
        $state = $this->shared->state;
        if($state === false){
            $fail($this->shared->result);
        }else{
            $this->shared->onFailure[spl_object_id($fail)] = $fail;
        }
        return $this;
    }

    public function isResolved() : bool{
        return $this->shared->state === true;
    }
}