<?php

namespace Blessing;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;

/** Local, maintained plugin API adapted from bs-community/filter (see NOTICE). */
class Filter
{
    protected $listeners = [];

    public function __construct(protected Container $container)
    {
    }

    public function add(string $hook, $filter, $priority = 20): void
    {
        ($this->listeners[$hook] ??= new Collection())->push([
            'filter' => $filter,
            'priority' => $priority,
        ]);
    }

    public function apply(string $hook, $init, $args = [])
    {
        return $this->getListeners($hook)->sortBy('priority')->reduce(function ($value, $entry) use ($args) {
            $filter = $entry['filter'];
            $callback = is_callable($filter) ? $filter : [$this->container->make($filter), 'filter'];

            return $callback($value, ...array_values($args));
        }, $init);
    }

    public function remove(string $hook): void
    {
        unset($this->listeners[$hook]);
    }

    public function getListeners(string $hook): Collection
    {
        return $this->listeners[$hook] ?? new Collection();
    }
}
