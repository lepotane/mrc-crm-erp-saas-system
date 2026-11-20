<?php

namespace App\Integration\Contracts;

interface IdempotencyStoreInterface
{
    /**
     * Aynı anahtar daha önce işlendiyse sonucu tekrar döndürür, callable'ı çalıştırmaz.
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    public function remember(string $key, callable $operation): mixed;

    public function forget(string $key): void;

    public function exists(string $key): bool;
}
