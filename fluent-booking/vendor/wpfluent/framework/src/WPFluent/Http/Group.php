<?php

namespace FluentBooking\Framework\Http;

/**
 * Route group with deferred, destructor-driven registration.
 *
 * The group callback runs from __destruct, NOT from the constructor.
 * This is a deliberate design decision: it lets the caller chain
 * configuration onto the group before the callback executes:
 *
 *     $router->group($attrs, $cb)->withPolicy(...);
 *     // The callback runs here — when the statement's temporary is
 *     // destroyed, after the whole chain has been applied.
 *
 * The group owns its attributes. It captures the enclosing group's
 * attributes plus its own at creation time, and every call chained
 * after group() is absorbed into that set. When the callback finally
 * runs, the router activates exactly this set as the context, then
 * restores the previous one. So the routes a group declares come
 * out the same whether the callback runs at end of statement or
 * later from registerRoutes(), at any nesting depth, and whether or
 * not a sibling group was executed in between.
 *
 * INVARIANT: the caller's expression should stay the only strong
 * reference to a Group. A second strong reference — or a reference
 * cycle — defers destruction to PHP's cyclic GC, whose timing varies
 * per host (zend.enable_gc, loaded extensions, allocation churn).
 * Two safety nets back the invariant: the router tracks every group
 * as a WeakReference (so tracking never extends a group's lifetime),
 * and registerRoutes() flushes any still-pending group before routes
 * are handed to WordPress, while the $executed flag keeps execute()
 * idempotent so the destructor and the flush can never run a
 * callback twice (see the fluentform 6.2.x incident).
 *
 * Do NOT "simplify" this by executing the callback in the
 * constructor: the constructor runs before the chain, so every
 * chained configuration would be silently ignored.
 */
class Group
{
	protected $router = null;
	protected $callback = null;
	protected $attributes = [];
	protected $executed = false;

	public function __construct($router, $callback, array $attributes = [])
	{
		$this->router = $router;
		$this->callback = $callback;
		$this->attributes = $attributes;
		$this->router->trackGroup($this);
	}

	/**
	 * Forward chained configuration to the router, then claim
	 * whatever it staged as this group's own attributes.
	 */
	public function __call($method, $params)
	{
		$this->router->{$method}(...$params);

		$this->attributes = $this->router->absorbStaged($this->attributes);

		return $this;
	}

	public function getAttributes()
	{
		return $this->attributes;
	}

	public function execute()
	{
		if (!$this->executed) {
			$this->executed = true;
			$this->router->executeGroupCallback(
				$this->callback, $this->attributes
			);
		}
	}

	public function __destruct()
	{
		$this->execute();
	}
}
