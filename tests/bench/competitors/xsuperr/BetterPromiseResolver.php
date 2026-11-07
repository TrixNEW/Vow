<?php

namespace xSuperr\Promise;

use pocketmine\promise\PromiseSharedData;

/**
 * @phpstan-template TValue
 */
final class BetterPromiseResolver{
    /** @phpstan-var PromiseSharedData<TValue> */
    private PromiseSharedData $shared;
    /** @phpstan-var BetterPromise<TValue> */
    private BetterPromise $promise;

    public function __construct(){
        $this->shared = new PromiseSharedData();
        $this->promise = new BetterPromise($this->shared);
    }

    /**
     * @phpstan-param TValue $value
     */
    public function resolve(mixed $value) : void{
        if($this->shared->state !== null){
            throw new \LogicException("Promise has already been resolved/rejected");
        }

        $this->shared->state = true;
        $this->shared->result = $value;
        foreach($this->shared->onSuccess as $c){
            $c($value);
        }
        $this->shared->onSuccess = [];
        $this->shared->onFailure = [];
    }

    public function reject(mixed $err) : void{
        if($this->shared->state !== null){
            throw new \LogicException("Promise has already been resolved/rejected");
        }
        $this->shared->state = false;
        $this->shared->result = $err;
        foreach($this->shared->onFailure as $c){
            $c($err);
        }
        $this->shared->onSuccess = [];
        $this->shared->onFailure = [];
    }

    /**
     * @phpstan-return BetterPromise<TValue>
     */
    public function getPromise() : BetterPromise{
        return $this->promise;
    }
}