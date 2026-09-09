<?php

namespace FluentBooking\App\Modules\MCP;

use FluentBooking\App\Modules\MCP\Support\MCPHelper;
use FluentBooking\App\Modules\MCP\Support\PermissionGate;
use FluentBooking\App\Modules\MCP\Prompts\BookingPrompts;
use FluentBooking\App\Modules\MCP\Tools\BookingTools;
use FluentBooking\App\Modules\MCP\Tools\BookingWriteTools;
use FluentBooking\App\Modules\MCP\Tools\ContextTools;
use FluentBooking\App\Modules\MCP\Tools\EventTypeTools;
use FluentBooking\App\Modules\MCP\Tools\ReportTools;
use FluentBooking\App\Modules\MCP\Tools\SchedulingTools;
use FluentBooking\App\Modules\MCP\Tools\SlotTools;
use FluentBooking\Framework\Support\Arr;

defined('ABSPATH') || exit;

/**
 * Single source of truth for every FluentBooking MCP ability.
 *
 * Each tool class owns its own `definitions()` slice, so a tool's schema lives
 * next to the code that answers it. This class merges those slices, filters
 * them by the toolsets the operator has enabled, wraps every execute_callback,
 * and registers the survivors with the WordPress Abilities API.
 *
 * Pro tools are NOT listed here — FluentBooking Pro pushes its abilities via
 * the `fluent_booking/mcp_loaded` action and the
 * `fluent_booking/mcp_ability_names` filter, registering into this same
 * namespace and server.
 */
class AbilitiesRegistrar
{
    const CATEGORY = 'fluent-booking';

    /**
     * Measured against this plugin's own definitions. It is a label on a
     * toggle, not an invoice.
     */
    const BYTES_PER_TOKEN = 3.5;

    /**
     * Tool classes per toolset. A class listed under a toolset is registered
     * only when that toolset is on, which is the whole point: an operator who
     * never asks an agent to edit event types should not pay for those schemas
     * in every request's context window.
     *
     * @return array toolset slug => tool class names
     */
    private static function toolClasses()
    {
        $classes = [
            PermissionGate::TOOLSET_CORE => [
                ContextTools::class,
                BookingTools::class,
                BookingWriteTools::class,
                SlotTools::class,
                EventTypeTools::class,
                ReportTools::class,
                BookingPrompts::class,
            ],
            PermissionGate::TOOLSET_SCHEDULING => [
                SchedulingTools::class,
            ],
            // Pro fills this in through the filter below.
            PermissionGate::TOOLSET_PAYMENTS => [],
        ];

        /**
         * The tool classes each toolset exposes, keyed by toolset.
         *
         * This is the extension point add-ons register through — Pro's payment
         * tools arrive here. Adding a class to a toolset means it inherits that
         * toolset's on/off switch and its context budget automatically, which
         * is why the hook is on the class map rather than on the finished
         * definitions.
         *
         * Every class listed must expose a static `definitions()` returning
         * ability-name => definition, in the shape documented in
         * docs/mcp-server-spec.md §8.
         *
         * @since 2.2.6
         *
         * @param array $classes toolset key => array of class names.
         */
        return (array) apply_filters('fluent_booking/mcp_tool_classes', $classes);
    }

    /**
     * Every definition the enabled toolsets expose.
     *
     * @param array|null $toolsets defaults to the operator's saved selection
     * @return array ability name => definition
     */
    public static function getDefinitions($toolsets = null)
    {
        if ($toolsets === null) {
            $toolsets = PermissionGate::enabledToolsets();
        }

        $defs = [];

        foreach (self::toolClasses() as $toolset => $classes) {
            if (!in_array($toolset, (array) $toolsets, true)) {
                continue;
            }

            foreach ($classes as $class) {
                if (class_exists($class) && method_exists($class, 'definitions')) {
                    $defs = array_merge($defs, (array) $class::definitions());
                }
            }
        }

        return $defs;
    }

    /**
     * The ability names that are prompts rather than tools.
     *
     * The adapter takes tools and prompts as separate arguments to
     * create_server(), and a prompt listed as a tool would appear in
     * `tools/list` with a body that reads as instructions — which is both
     * wrong and expensive.
     *
     * @param array|null $toolsets
     * @return array
     */
    public static function getPromptNames($toolsets = null)
    {
        $names = [];

        foreach (self::getDefinitions($toolsets) as $name => $definition) {
            if (!empty($definition['is_prompt'])) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * The ability names that are tools.
     *
     * @param array|null $toolsets
     * @return array
     */
    public static function getToolNames($toolsets = null)
    {
        $names = [];

        foreach (self::getDefinitions($toolsets) as $name => $definition) {
            if (empty($definition['is_prompt'])) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Register every enabled definition as a WP ability.
     */
    public static function register()
    {
        foreach (self::getDefinitions() as $name => $definition) {
            try {
                // wp_register_ability() returns null on every validation
                // failure rather than throwing: WP_Abilities_Registry::register()
                // catches its own InvalidArgumentException, calls
                // _doing_it_wrong() and returns. So the return value is the ONLY
                // signal that a definition was rejected — ignore it and a tool
                // goes missing from tools/list with nothing recorded anywhere.
                $registered = self::registerAbility($name, $definition);

                if (!$registered) {
                    self::reportRegistrationFailure($name, 'wp_register_ability() rejected the definition; see the _doing_it_wrong notice for the reason.');
                }
            } catch (\Throwable $e) {
                // Belt and braces for the paths core does NOT guard: a TypeError
                // raised while building $args, or a future core version that
                // lets an exception escape. Registration runs on
                // wp_abilities_api_init, which the adapter fires lazily from
                // INSIDE our own create_server() call, so an uncaught throw here
                // would not just drop this one ability — it aborts every later
                // callback on that action, other plugins' abilities included,
                // and takes the FluentBooking MCP server down with it. One
                // malformed definition must never cost the whole surface.
                self::reportRegistrationFailure($name, $e);
            }
        }
    }

    /**
     * Record that one ability did not register, without taking the rest down.
     *
     * @param string            $name
     * @param \Throwable|string $reason
     */
    private static function reportRegistrationFailure($name, $reason)
    {
        if (defined('FLUENT_BOOKING_DEBUG') && FLUENT_BOOKING_DEBUG) {
            // No booking data or tokens here — just the ability name and the
            // failure site.
            $detail = $reason instanceof \Throwable
                ? get_class($reason) . ': ' . $reason->getMessage() . ' at ' . basename($reason->getFile()) . ':' . $reason->getLine()
                : (string) $reason;

            error_log('FluentBooking MCP ability registration failed: ' . $name . ' - ' . $detail); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }

        /**
         * Fires when a single MCP ability fails to register. The remaining
         * abilities still register; this lets a site alert on the gap rather
         * than discover it through a missing tool.
         *
         * @since 2.3.0
         *
         * @param string            $name   the ability name that failed
         * @param \Throwable|string $reason the exception, or a description of
         *                                  why core rejected the definition
         */
        do_action('fluent_booking/mcp_ability_registration_failed', $name, $reason);
    }

    /**
     * Register one definition with the Abilities API. Kept separate from
     * register() so that method's try/catch stays a thin skip-and-continue shell.
     *
     * @param string $name
     * @param array  $definition
     * @return object|null the registered WP_Ability, or null when core refused
     */
    private static function registerAbility($name, $definition)
    {
        // Cast before array_keys(): a no-argument tool declares `properties` as
        // an stdClass so the schema serialises as {} rather than [], and
        // array_keys() rejects an object with a TypeError on PHP 8.
        $properties = Arr::get($definition, 'input_schema.properties', []);

        $declaredParams = $properties ? array_keys((array) $properties) : [];

        $args = [
            'label'               => Arr::get($definition, 'label'),
            'description'         => Arr::get($definition, 'description'),
            'category'            => self::CATEGORY,
            'execute_callback'    => self::wrapExecuteCallback($name, Arr::get($definition, 'execute_callback'), $declaredParams),
            'permission_callback' => Arr::get($definition, 'permission_callback'),
            'meta'                => [
                'show_in_rest' => true,
                'mcp'          => array_merge(
                    ['public' => true],
                    !empty($definition['is_prompt']) ? ['type' => 'prompt'] : []
                ),
            ],
        ];

        if (!empty($definition['input_schema'])) {
            $args['input_schema'] = $definition['input_schema'];
        }

        if (!empty($definition['output_schema'])) {
            $args['output_schema'] = $definition['output_schema'];
        }

        if (!empty($definition['annotations'])) {
            $mapped = self::mapAnnotations($definition['annotations']);
            if (!empty($mapped)) {
                $args['meta']['annotations'] = $mapped;
            }
        }

        return wp_register_ability($name, $args);
    }

    /**
     * Emit a tool's behaviour hints under BOTH vocabularies.
     *
     * There are two, and which one is read depends on who is reading:
     *
     *  - WordPress core owns `meta.annotations` and defines it in snake_case —
     *    `readonly`, `destructive`, `idempotent` (see WP_Ability::
     *    $default_annotations). Core merges its own nulls over whatever is
     *    passed, so an ability that supplies only camelCase ends up recorded
     *    with `destructive => null`: not destructive, as far as core and
     *    anything reading core is concerned.
     *  - The MCP wire format names them `readOnlyHint` / `destructiveHint` /
     *    `idempotentHint` / `openWorldHint`, and an adapter that forwards
     *    meta.annotations verbatim needs those spellings to reach the client.
     *
     * Emitting one spelling and hoping is how every destructive tool on this
     * server silently loses its confirmation prompt. Emitting both costs a few
     * bytes per tool and is correct under either reader, so that is what this
     * does. Unknown keys are still dropped rather than passed through as noise.
     *
     * Public so scripts/check-mcp-budget.php can measure the annotations a
     * client actually receives. Measuring the pre-mapping shape under-reports
     * every tool by the size of the second vocabulary.
     *
     * @param array $annotations
     * @return array
     */
    /**
     * The wire size of one definition as a client receives it in tools/list.
     *
     * The MAPPED annotations, not the declared ones: tools declare `readonly`
     * and this class emits both that and `readOnlyHint`, so measuring the
     * declared shape under-reports every tool.
     *
     * @param string $name
     * @param array  $definition
     *
     * @return int
     */
    public static function wireBytes($name, $definition)
    {
        return strlen((string) wp_json_encode([
            'name'        => $name,
            'description' => isset($definition['description']) ? $definition['description'] : '',
            'inputSchema' => isset($definition['input_schema']) ? $definition['input_schema'] : [],
            'annotations' => self::mapAnnotations(
                isset($definition['annotations']) ? $definition['annotations'] : []
            ),
        ]));
    }

    /**
     * @param int $bytes
     * @return int
     */
    public static function wireTokens($bytes)
    {
        return (int) round($bytes / self::BYTES_PER_TOKEN);
    }

    public static function mapAnnotations($annotations)
    {
        $map = [
            'readonly'    => 'readOnlyHint',
            'destructive' => 'destructiveHint',
            'idempotent'  => 'idempotentHint',
            'open_world'  => 'openWorldHint',
        ];

        $out = [];

        foreach ((array) $annotations as $key => $value) {
            if ($key === 'title') {
                $out['title'] = (string) $value;
                continue;
            }

            if (isset($map[$key])) {
                $out[$key]        = (bool) $value;   // core's vocabulary
                $out[$map[$key]]  = (bool) $value;   // the MCP wire vocabulary
            }
        }

        // A read-only tool cannot be destructive. destructiveHint defaults to
        // TRUE when absent per the MCP spec, so state it explicitly for read
        // tools — otherwise a client gating on destructiveHint would prompt for
        // confirmation before every report.
        if (!empty($out['readOnlyHint']) && !isset($out['destructiveHint'])) {
            $out['destructive']     = false;
            $out['destructiveHint'] = false;
        }

        return $out;
    }

    /**
     * Wrap a tool callback so it (a) rejects input parameters the tool does not
     * declare and (b) converts an unhandled exception into a structured error.
     *
     * The rejection matters more than it looks. `input_schema` sets no
     * `additionalProperties`, so an undeclared key would otherwise pass
     * validation and be silently dropped — and the agent would receive a full,
     * plausible-looking result that is NOT filtered the way it asked. That is
     * the worst failure mode available: a wrong number reads as a right one,
     * whereas an error is recoverable. Sibling tools also name overlapping
     * concepts differently, so a carried-over parameter name is a realistic slip
     * rather than a rare typo. The error names the accepted parameters so the
     * agent can self-correct in one step — richer than the schema validator's
     * message, which is why this lives here rather than in the schema.
     *
     * @param string   $toolName
     * @param callable $callback
     * @param array    $declaredParams
     * @return \Closure
     */
    private static function wrapExecuteCallback($toolName, $callback, $declaredParams)
    {
        return function ($params = []) use ($toolName, $callback, $declaredParams) {
            if (!is_array($params)) {
                $params = [];
            }

            $unknown = array_diff(array_keys($params), $declaredParams);

            if ($unknown) {
                return MCPHelper::error(
                    'unknown_parameter',
                    sprintf(
                        /* translators: 1: tool name, 2: rejected parameter names, 3: accepted parameter names */
                        __('%1$s does not accept: %2$s. Accepted parameters: %3$s.', 'fluent-booking'),
                        $toolName,
                        implode(', ', $unknown),
                        $declaredParams ? implode(', ', $declaredParams) : __('none', 'fluent-booking')
                    ),
                    ['accepted_parameters' => $declaredParams]
                );
            }

            try {
                return call_user_func($callback, $params);
            } catch (\Throwable $e) {
                if (defined('FLUENT_BOOKING_DEBUG') && FLUENT_BOOKING_DEBUG) {
                    error_log('FluentBooking MCP tool failed: ' . $toolName . ' - ' . get_class($e) . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                }

                // Deliberately not surfacing $e->getMessage(): it can carry SQL
                // fragments or file paths, and the agent cannot act on either.
                return MCPHelper::error(
                    'tool_failed',
                    sprintf(
                        /* translators: %s: tool name */
                        __('%s could not complete. The site logged the details.', 'fluent-booking'),
                        $toolName
                    )
                );
            }
        };
    }
}
