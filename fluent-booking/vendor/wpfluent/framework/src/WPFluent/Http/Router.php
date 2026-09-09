<?php

namespace FluentBooking\Framework\Http;

use Closure;

class Router
{
    /**
     * Application Instance
     * @var \FluentBooking\Framework\Foundation\Application
     */
    protected $app = null;

    /**
     * Mapping of named routes.
     * @var array
     */
    protected $namedRoutes = [];

    /**
     * Registered routes collection
     * @var array
     */
    protected $routes = [];

    /**
     * Attributes staged by chained calls (prefix(), name(),
     * withPolicy(), before(), ...) that have not been claimed
     * yet. The next group() or route declaration claims them.
     * @var array
     */
    protected $staged = [];

    /**
     * Effective attributes of the group whose callback is
     * currently executing. Empty outside any group.
     * @var array
     */
    protected $context = [];

    /**
     * Whether routes created by this router should override existing ones.
     * @var bool
     */
    protected $shouldOverride = false;

    /**
     * Weak references to groups pending execution.
     * @var array
     */
    protected $pendingGroups = [];

    /**
     * Construct the routet instance
     * @param \FluentBooking\Framework\Foundation\Application $app
     */
    public function __construct($app)
    {
        $this->app = $app;
        $this->staged = $this->newAttributes();
        $this->context = $this->newAttributes();
    }

    /**
     * Create a route group.
     *
     * The group captures its effective attributes (the enclosing
     * group's attributes merged with anything staged for it) at
     * creation time, so its callback resolves the same routes no
     * matter when it runs: at end of statement, or later from
     * registerRoutes() when the instance was kept alive.
     *
     * @param  array|Closure $attributes
     * @param  Closure|null $callback
     * @return \FluentBooking\Framework\Http\Group
     */
    public function group($attributes = [], ?Closure $callback = null)
    {
        if ($attributes instanceof Closure) {
            $callback = $attributes;
            $attributes = [];
        }

        if (isset($attributes['name'])) {
            $this->name($attributes['name']);
        }

        if (isset($attributes['prefix'])) {
            $this->prefix($attributes['prefix']);
        }

        if (isset($attributes['namespace'])) {
            $this->namespace($attributes['namespace']);
        }

        if (isset($attributes['policy'])) {
            $this->withPolicy($attributes['policy']);
        }

        if (isset($attributes['middleware'])) {
            $middleware = $attributes['middleware'];

            if (isset($middleware['before'])) {
                $this->middleware('before', $middleware['before']);
            }

            if (isset($middleware['after'])) {
                $this->middleware('after', $middleware['after']);
            }
        }

        return new Group($this, $callback, $this->resolveAttributes());
    }

    /**
     * Set the route name
     * 
     * @param  string $name
     * @return self
     */
    public function name($name)
    {
        $this->staged['name'][] = $name;

        return $this;
    }

    /**
     * Set the route prefix
     * 
     * @param  string $prefix
     * @return self
     */
    public function prefix($prefix)
    {
        $this->staged['prefix'][] = $prefix;

        return $this;
    }

    /**
     * Set the namespace for the action/controller
     * 
     * @param  string $ns
     * @return self
     */
    public function namespace($ns)
    {
        $this->staged['namespace'][] = $ns;

        return $this;
    }

    /**
     * Set the default route policy.
     * 
     * @return self
     */
    public function withDefaultPolicy()
    {
        return $this->withPolicy(
            // @phpstan-ignore-next-line
            $this->app->__namespace__.'\\App\\Http\\Policies\\Policy'
        );
    }

    /**
     * Set the route policy
     * 
     * @param  mixed $handler
     * @param  string|null $method
     * @return self
     */
    public function withPolicy($handler, $method = null)
    {
        if (is_array($handler = $method ? func_get_args() : $handler)) {
            $handler = implode('@', $handler);
        }

        $this->staged['policy'] = $handler;

        return $this;
    }

    /**
     * Set the route before middleware
     * 
     * @param  array|string $middleware
     * @return self
     */
    public function before(...$middleware)
    {
        return $this->middleware('before', ...$middleware);
    }

    /**
     * Set the route after middleware
     * 
     * @param  array|string $middleware
     * @return self
     */
    public function after(...$middleware)
    {
        return $this->middleware('after', ...$middleware);
    }

    /**
     * Set the route middleware
     * 
     * @param  array|string $middleware
     * @return self
     */
    public function middleware($type = 'before', ...$middleware)
    {
        if (is_array($middleware[0])) {
            $middleware = reset($middleware);
        }

        $this->staged[$type] = array_merge(
            $this->staged[$type], $middleware
        );

        return $this;
    }

    /**
     * Merge whatever is currently staged into the given attributes
     * and clear the staging area. Used by Group to absorb calls
     * chained after group() into its own attributes.
     *
     * @param  array $attributes
     * @return array
     */
    public function absorbStaged(array $attributes)
    {
        return $this->mergeAttributes($attributes, $this->takeStaged());
    }

    /**
     * Track a group so any instance kept alive past its statement
     * can still be executed before routes are registered. A weak
     * reference keeps the destructor firing at end of statement.
     *
     * @param  Group $group
     * @return void
     */
    public function trackGroup(Group $group)
    {
        if (class_exists(\WeakReference::class)) {
            $this->pendingGroups[] = \WeakReference::create($group);
        }
    }

    /**
     * Execute any groups still pending execution because a
     * reference to them was held beyond their statement.
     *
     * @return void
     */
    protected function executePendingGroups()
    {
        while ($this->pendingGroups) {
            $reference = array_shift($this->pendingGroups);

            if ($group = $reference->get()) {
                $group->execute();
            }
        }
    }

    /**
     * Execute a route group callback with the group's attributes
     * as the active context. The previous context is restored
     * afterwards, even if the callback throws, so execution is
     * safe at any nesting depth and at any time.
     *
     * @param  Closure $callback
     * @param  array $attributes
     * @return void
     */
    public function executeGroupCallback($callback, array $attributes = [])
    {
        $previous = $this->context;

        $this->context = $attributes + $this->newAttributes();

        try {
            $callback($this);
        } finally {
            $this->context = $previous;
        }
    }

    /**
     * Declare a GET route endpoint
     * @param  string $uri
     * @param  array|string|Closure $handler
     * @return \FluentBooking\Framework\Http\Route
     */
    public function get($uri, $handler)
    {
        $this->routes[] = $route = $this->newRoute(
            $uri, $handler, 'GET'
        );

        return $route;
    }

    /**
     * Declare a POST route endpoint
     * @param  string $uri
     * @param  array|string|Closure $handler
     * @return \FluentBooking\Framework\Http\Route
     */
    public function post($uri, $handler)
    {
        $this->routes[] = $route = $this->newRoute(
            $uri, $handler, 'POST'
        );

        return $route;
    }

    /**
     * Declare a PUT route endpoint
     * @param  string $uri
     * @param  array|string|Closure $handler
     * @return \FluentBooking\Framework\Http\Route
     */
    public function put($uri, $handler)
    {
        $this->routes[] = $route = $this->newRoute(
            $uri, $handler, 'PUT'
        );

        return $route;
    }

    /**
     * Declare a PATCH route endpoint
     * @param  string $uri
     * @param  array|string|Closure $handler
     * @return \FluentBooking\Framework\Http\Route
     */
    public function patch($uri, $handler)
    {
        $this->routes[] = $route = $this->newRoute(
            $uri, $handler, 'PATCH'
        );

        return $route;
    }

    /**
     * Declare a DELETE route endpoint
     * @param  string $uri
     * @param  array|string|Closure $handler
     * @return \FluentBooking\Framework\Http\Route
     */
    public function delete($uri, $handler)
    {
        $this->routes[] = $route = $this->newRoute(
            $uri, $handler, 'DELETE'
        );

        return $route;
    }

    /**
     * Declare a route endpoint that matches any HTTP Verb/Method
     * @param  string $uri
     * @param  array|string|Closure $handler
     * @return \FluentBooking\Framework\Http\Route
     */
    public function any($uri, $handler)
    {
        $this->routes[] = $route = $this->newRoute(
            $uri, $handler, \WP_REST_Server::ALLMETHODS
        );

        return $route;
    }

    /**
     * Create a new route instance
     * @param  string $uri
     * @param  string|Closure $handler
     * @param  string $method HTTP Method
     * @return \FluentBooking\Framework\Http\Route
     */
    protected function newRoute($uri, $handler, $method)
    {
        $attributes = $this->resolveAttributes();

        $route = Route::create(
            $this->app,
            $this->getRestNamespace(),
            $this->buildUriWithPrefix($uri, $attributes['prefix']),
            $handler,
            $method
        );

        if ($attributes['name']) {
            $route->withName($attributes['name']);
        }

        if ($attributes['namespace']) {
            $route->withNamespace($attributes['namespace']);
        }

        if ($attributes['policy']) {
            $route->withPolicy($attributes['policy']);
        }

        if ($attributes['before']) {
            $route->before($attributes['before']);
        }

        if ($attributes['after']) {
            $route->after($attributes['after']);
        }

        if ($this->shouldOverride) {
            $route->override();
        }

        return $route->preparefrontendHandlers();
    }

    /**
     * An empty attribute set.
     *
     * @return array
     */
    protected function newAttributes()
    {
        return [
            'name' => [],
            'prefix' => [],
            'namespace' => [],
            'policy' => null,
            'before' => [],
            'after' => [],
        ];
    }

    /**
     * Return the staged attributes and reset the staging area.
     *
     * @return array
     */
    protected function takeStaged()
    {
        $staged = $this->staged;

        $this->staged = $this->newAttributes();

        return $staged;
    }

    /**
     * The effective attributes for a route or group declared right
     * now: the current group context merged with the staged
     * attributes, which are claimed in the process.
     *
     * @return array
     */
    protected function resolveAttributes()
    {
        return $this->mergeAttributes($this->context, $this->takeStaged());
    }

    /**
     * Merge attribute sets, outermost first. List attributes
     * accumulate; the policy of the innermost set that declares
     * one wins, so nested groups inherit their parent's policy
     * unless they declare their own.
     *
     * @param  array ...$sets
     * @return array
     */
    protected function mergeAttributes(array ...$sets)
    {
        $merged = $this->newAttributes();

        foreach ($sets as $set) {
            foreach (['name', 'prefix', 'namespace', 'before', 'after'] as $key) {
                if (!empty($set[$key])) {
                    $merged[$key] = array_merge($merged[$key], $set[$key]);
                }
            }

            if (isset($set['policy'])) {
                $merged['policy'] = $set['policy'];
            }
        }

        return $merged;
    }

    /**
     * Resolve the rest namespace for the plugin
     * 
     * @return string
     */
    protected function getRestNamespace()
    {
        $version = $this->app->config->get('app.rest_version');

        $namespace = trim(
            $this->app->config->get('app.rest_namespace', ''), '/'
        );

        return "{$namespace}/{$version}";
    }

    /**
     * Build the URI with the prefix
     * 
     * @param  string $uri
     * @param  array $prefixes
     * @return string The URI
     */
    protected function buildUriWithPrefix($uri, array $prefixes = [])
    {
        $uri = trim($uri, '/');

        $prefix = array_map(function($prefix) {
            return trim($prefix, '/');
        }, $prefixes);

        $prefix = implode('/', $prefix);

        return trim($prefix, '/') . '/' . trim($uri, '/');
    }

    /**
     * Mark all routes created by this router to override existing ones.
     *
     * @return $this
     */
    public function overrideExisting()
    {
        $this->shouldOverride = true;

        return $this;
    }

    /**
     * Register all the routse in WordPress Rest Engine
     *
     * @return void
     */
    public function registerRoutes()
    {
        $this->executePendingGroups();

        foreach ($this->getRoutes() as $route) {
            $route->register();
        }
    }

    /**
     * Get all ther registered routes
     * @return array
     */
    public function getRoutes()
    {
        return $this->routes;
    }

    /**
     * Set a named route in the router.
     * 
     * @param string $name
     * @param Route  $route
     */
    public function setNamedRoute($name, Route $route)
    {
        $this->namedRoutes[$name] = $route;

        return $route;
    }

    /**
     * Get a route by name.
     * 
     * @param  string $name
     * @return Route|null
     */
    public function getByName($name)
    {
        return $this->namedRoutes[$name] ?? null;
    }
}
