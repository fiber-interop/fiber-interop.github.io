<?php

enum Lifetime
{
    case Transient;  // a new instance on every get()
    case Singleton;  // one instance for the process
    case Scoped;     // one instance per \Scope (request, job)
}

final class Container
{
    /** @var array<string, array{Lifetime, Closure}> */
    private array $factories = [];
    /** @var array<string, object> */
    private array $singletons = [];
    /** @var WeakMap<Scope, array<string, object>> */
    private WeakMap $scoped;

    public function __construct()
    {
        $this->scoped = new WeakMap();
    }

    public function set(string $id, Lifetime $lifetime, Closure $factory): void
    {
        $this->factories[$id] = [$lifetime, $factory];
    }

    public function get(string $id): object
    {
        [$lifetime, $factory] = $this->factories[$id] ?? throw new LogicException("Unknown service $id");

        return match ($lifetime) {
            Lifetime::Transient => $factory($this),
            Lifetime::Singleton => $this->singletons[$id] ??= $factory($this),
            Lifetime::Scoped => $this->getScoped($id, $factory),
        };
    }

    private function getScoped(string $id, Closure $factory): object
    {
        $scope = Scope::getCurrent();
        if ($scope === Scope::getRootScope()) {
            throw new LogicException("Scoped service $id requested outside a request scope");
        }
        $instances = $this->scoped[$scope] ?? [];
        if (!isset($instances[$id])) {
            $instances[$id] = $factory($this);
            $this->scoped[$scope] = $instances;
        }
        return $instances[$id];
    }
}
