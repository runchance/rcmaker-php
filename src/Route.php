<?php
namespace RC;
use RC\Config;
use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
class Route{
	private static $_disp = null;
	private static $_route = null;
	private static $_hasRoute = false;
    private static $_fallback = null;
    private static $_instance = null;
    private static $_middlewareRevision = 0;
    private $_middlewares = [];
    private $_parent = null;
    private $_failed = false;
    private $_compiledMiddlewares = [];
    private $_compiledRevision = -1;

	public static function init(){
		if(!self::$_disp){
			self::$_disp = \FastRoute\simpleDispatcher(function(RouteCollector $route){
			  	 self::$_route = $route;
                 Config::get('route',null);
			}, ['routeCollector' => \RC\Routing\RouteCollector::class]);
		}
		return true;
	}

	public static function group($path, $callbacks){
        if (static::$_route instanceof \RC\Routing\RouteCollector) {
            return static::$_route->addGroupDefinition($path, $callbacks);
        }
        // Retain native-collector compatibility for integrations that provide their own collector.
        return static::withinGroup(static function () use ($path, $callbacks): void {
            $failure = null;
            static::$_route->addGroup($path, static function (RouteCollector $collector) use ($callbacks, &$failure) {
                try { $callbacks($collector); } catch (\Throwable $error) { $failure = $error; }
            });
            if ($failure !== null) { throw $failure; }
        });
    }

    /** Internal collector hook: isolate RC middleware scope and fail closed after partial group registration. */
    public static function withinGroup(callable $collect): self
    {
        $previous = static::$_instance;
        $group = new static;
        $group->_parent = $previous;
        static::$_instance = $group;
        try {
            $collect();
        } catch (\Throwable $error) {
            // Even if application config catches the error, partial routes must not bypass group guards.
            $group->_failed = true;
            ++static::$_middlewareRevision;
            throw $error;
        } finally {
            static::$_instance = $previous;
        }
        return $group;
    }

    public static function get($path, $callback){
        return static::addRoute('GET', $path, $callback);
    }

    public static function post($path, $callback){
        return static::addRoute('POST', $path, $callback);
    }

    public static function put($path, $callback){
        return static::addRoute('PUT', $path, $callback);
    }

    public static function patch($path, $callback){
        return static::addRoute('PATCH', $path, $callback);
    }

    public static function delete($path, $callback){
        return static::addRoute('DELETE', $path, $callback);
    }

    public static function head($path, $callback){
        return static::addRoute('HEAD', $path, $callback);
    }

    public static function options($path, $callback){
        return static::addRoute('OPTIONS', $path, $callback);
    }

	public static function add($method, $path, $callback){
        return static::addRoute($method, $path, $callback);
    }

	public static function dispatch($method, $path){
		if(!static::$_disp){
			return;
		}
        return static::$_disp->dispatch($method, $path);
    }

    public static function any($path, $callback){
        return static::addRoute(['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS'], $path, $callback);
    }

    public function middleware($middleware)
    {
        if ($middleware === null) {
            return $this->_middlewares;
        }
        try {
            $items = is_string($middleware) ? [$middleware] : $middleware;
            if (!is_array($items)) { throw new \InvalidArgumentException('Route middleware must be a class name or an array of class names.'); }
            foreach ($items as $class) { Middleware::validateClass($class); }
        } catch (\Throwable $error) {
            $this->_failed = true;
            ++static::$_middlewareRevision;
            throw $error;
        }
        // Descriptive keys must not overwrite a guard on this definition or its parent.
        $this->_middlewares = array_merge($this->_middlewares, array_values($items));
        ++static::$_middlewareRevision;
        return $this;
    }

    public function getMiddleware()
    {
        if ($this->_failed) {
            throw new \LogicException('Cannot execute routes from a failed route group.');
        }
        if ($this->_compiledRevision !== static::$_middlewareRevision) {
            $this->_compiledMiddlewares = $this->_parent === null ? $this->_middlewares
                : array_merge($this->_parent->getMiddleware(), $this->_middlewares);
            $this->_compiledRevision = static::$_middlewareRevision;
        }
        return $this->_compiledMiddlewares;
    }

    protected static function addRoute($method, $path, $callback){
        $definition = static::createDefinition($callback);
        static::$_route->addRoute($method, $path, $definition);
        return $definition['route'];
    }

    /** Internal collector hook: attach one definition to the active RC group without touching the handler. */
    public static function createDefinition($callback): array
    {
        $route = new static;
        $route->_parent = static::$_instance;
        static::$_hasRoute = true;
        return ['callback' => $callback, 'route' => $route];
    }

    public static function fallback(callable $callback) {
        if (is_callable($callback)) {
            static::$_fallback = $callback;
        }
    }

    public static function getFallback() {
        return is_callable(static::$_fallback) ? static::$_fallback : null;
    }

}
?>
