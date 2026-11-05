<?php

namespace RC\Routing;

use RC\Route;

/** FastRoute's original collector with RC metadata only; parsing and data generation remain upstream. */
class RouteCollector extends \FastRoute\RouteCollector
{
    /** Native get/post/addRoute calls receive the same independent RC definition as RC\Route declarations. */
    public function addRoute($httpMethod, $route, $handler)
    {
        if (!is_array($handler) || !isset($handler['route']) || !$handler['route'] instanceof Route
            || !array_key_exists('callback', $handler)) {
            $handler = Route::createDefinition($handler);
        }
        parent::addRoute($httpMethod, $route, $handler);
    }

    /** Keep FastRoute's void API while retaining RC parent scopes for native nested groups. */
    public function addGroup($prefix, callable $callback)
    {
        $this->addGroupDefinition($prefix, $callback);
    }

    /** Internal RC entry: return the group definition so middleware chained after registration remains effective. */
    public function addGroupDefinition($prefix, callable $callback): Route
    {
        return Route::withinGroup(function () use ($prefix, $callback): void {
            $previous = $this->currentGroupPrefix;
            try {
                parent::addGroup($prefix, $callback);
            } finally {
                // FastRoute 1.3.1 does not restore this protected field when its callback throws.
                $this->currentGroupPrefix = $previous;
            }
        });
    }
}
