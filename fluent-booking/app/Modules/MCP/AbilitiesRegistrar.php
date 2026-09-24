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
 * Registers every FluentBooking MCP ability.
 *
 * Each tool class owns its `definitions()`. This class merges them, keeps the
 * enabled toolsets, wraps each execute_callback and registers the result with
 * the Abilities API. Pro adds its own via `fluent_booking/mcp_loaded` and
 * `fluent_booking/mcp_ability_names`.
 */
class AbilitiesRegistrar
{
    const CATEGORY = 'fluent-booking';

    /**
     * Measured against this plugin's definitions. A rough label, not exact.
     */
    const BYTES_PER_TOKEN = 3.5;

    /**
     * Tool classes per toolset. A class registers only when its toolset is on,
     * so unused schemas don't cost context.
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
         * Add-ons (e.g. Pro's payment tools) register here so they inherit the
         * toolset's on/off switch. Each class needs a static `definitions()`
         * returning ability-name => definition (docs/mcp-server-spec.md §8).
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
     * The ability names that are prompts rather than tools. create_server()
     * takes them separately; a prompt passed as a tool would show in tools/list.
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
                // Core catches its own validation errors and returns null, so
                // the return value is the only sign a definition was rejected.
                $registered = self::registerAbility($name, $definition);

                if (!$registered) {
                    self::reportRegistrationFailure($name, 'wp_register_ability() rejected the definition; see the _doing_it_wrong notice for the reason.');
                }
            } catch (\Throwable $e) {
                // For what core doesn't catch, e.g. a TypeError building $args.
                // This runs on wp_abilities_api_init inside create_server(), so
                // an uncaught throw would abort every later ability, other
                // plugins' included, and the whole server.
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
            // Ability name and failure site only, no booking data or tokens.
            $detail = $reason instanceof \Throwable
                ? get_class($reason) . ': ' . $reason->getMessage() . ' at ' . basename($reason->getFile()) . ':' . $reason->getLine()
                : (string) $reason;

            error_log('FluentBooking MCP ability registration failed: ' . $name . ' - ' . $detail); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }

        /**
         * Fires when a single MCP ability fails to register. The rest still
         * register; this lets a site alert on the missing tool.
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
     * Register one definition with the Abilities API.
     *
     * @param string $name
     * @param array  $definition
     * @return object|null the registered WP_Ability, or null when core refused
     */
    private static function registerAbility($name, $definition)
    {
        // No-argument tools declare `properties` as stdClass (to encode as {}),
        // and array_keys() throws on an object in PHP 8.
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
     * The wire size of one definition as a client receives it in tools/list,
     * measured with the mapped annotations, which carry both vocabularies.
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

    /**
     * Emit a tool's behaviour hints in both vocabularies.
     *
     * Core reads snake_case (`readonly`, `destructive`, ...) and merges its own
     * nulls over anything else, so camelCase alone reads as not destructive.
     * MCP clients read `readOnlyHint`, `destructiveHint`, ... Unknown keys are dropped.
     *
     * @param array $annotations
     * @return array
     */
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

        // MCP defaults destructiveHint to true when absent, so say false for
        // read-only tools or clients would ask to confirm every report.
        if (!empty($out['readOnlyHint']) && !isset($out['destructiveHint'])) {
            $out['destructive']     = false;
            $out['destructiveHint'] = false;
        }

        return $out;
    }

    /**
     * Wrap a tool callback to reject undeclared parameters and turn an
     * unhandled exception into a structured error.
     *
     * The schema sets no additionalProperties, so an unknown key would be
     * dropped and the agent would get an unfiltered result that looks right.
     * The error lists the accepted parameters so the agent can correct itself.
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

                // Not $e->getMessage(): it can carry SQL or file paths.
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
