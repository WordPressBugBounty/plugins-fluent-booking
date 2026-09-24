<?php

namespace FluentBooking\App\Modules\MCP;

use FluentBooking\App\Modules\MCP\Support\PermissionGate;
use FluentBooking\App\Modules\MCP\Tools\ContextTools;

defined('ABSPATH') || exit;

/**
 * Bootstrap for the MCP integration.
 *
 * Wires the Abilities API (WP 6.9+) to the MCP Adapter, supplied by
 * FluentToolkit or the standalone mcp-adapter plugin. Shows an admin notice
 * when neither is loaded.
 *
 * Off by default (PermissionGate::isEnabled()). When on, the endpoint needs WP
 * auth, the transport permission gate and per-ability permission checks.
 */
class MCPInit
{
    const SERVER_ID = 'fluent-booking';

    /**
     * Toolkit discovery and the settings entry always register, since they are
     * where the operator finds the switch. The server starts only when enabled.
     */
    public static function boot()
    {
        self::registerWithToolkit();
        self::registerSettingsMenu();

        if (PermissionGate::isEnabled()) {
            (new self())->init();
        }
    }

    public function init()
    {
        // Fire only on WP 6.9+ or with the Abilities API plugin.
        add_action('wp_abilities_api_categories_init', [$this, 'registerCategory']);
        add_action('wp_abilities_api_init', [$this, 'registerAbilities']);

        add_action('admin_init', [$this, 'registerPrivacyPolicyContent']);

        // Fires only when an adapter is loaded.
        add_action('mcp_adapter_init', [$this, 'registerCustomServer']);

        // Drop get-booking-context's cache when anything it reports changes.
        // Static callback so a site can remove_action it.
        $invalidate = [ContextTools::class, 'invalidateCache'];

        foreach ([
            'fluent_booking/after_create_calendar',
            'fluent_booking/after_update_calendar',
            'fluent_booking/after_delete_calendar',
            'fluent_booking/after_create_calendar_slot',
            'fluent_booking/after_create_event',
            'fluent_booking/after_update_event_details',
            'fluent_booking/after_delete_calendar_event',
        ] as $hook) {
            add_action($hook, $invalidate);
        }

        add_action('admin_notices', [$this, 'maybeShowAdapterNotice']);
    }

    public function registerCategory()
    {
        wp_register_ability_category(AbilitiesRegistrar::CATEGORY, [
            'label'       => __('FluentBooking', 'fluent-booking'),
            'description' => __('Scheduling abilities for FluentBooking — bookings, availability, event types and reports.', 'fluent-booking'),
        ]);
    }

    public function registerAbilities()
    {
        AbilitiesRegistrar::register();

        /**
         * Fires after FluentBooking registers its core MCP abilities. Pro
         * registers its own here, under the same namespace and server.
         *
         * @since 2.3.0
         */
        do_action('fluent_booking/mcp_loaded');
    }

    /**
     * Register the FluentBooking MCP server at /wp-json/fluent-booking/mcp,
     * outside fluent-booking/v2 so the admin policy stack doesn't apply.
     *
     * @param object $adapter the \WP\MCP\Core\McpAdapter instance
     */
    public function registerCustomServer($adapter)
    {
        if (!$adapter || !is_object($adapter) || !method_exists($adapter, 'create_server')) {
            return;
        }

        $abilityNames = AbilitiesRegistrar::getToolNames();

        /**
         * Filters the ability names exposed by the FluentBooking MCP server.
         * Pro and extensions add theirs here to share the server.
         *
         * @since 2.3.0
         *
         * @param array $abilityNames fully-qualified ability names
         */
        $abilityNames = apply_filters('fluent_booking/mcp_ability_names', $abilityNames);
        $abilityNames = array_values(array_unique(array_filter((array) $abilityNames)));

        // Prompts go in their own argument, not in tools/list.
        $promptNames = AbilitiesRegistrar::getPromptNames();

        /**
         * Filters the prompt ability names exposed by the server.
         *
         * @since 2.3.0
         *
         * @param array $promptNames fully-qualified ability names
         */
        $promptNames = apply_filters('fluent_booking/mcp_prompt_names', $promptNames);
        $promptNames = array_values(array_unique(array_filter((array) $promptNames)));

        $namespace = self::serverNamespace();
        $route     = self::serverRoute();

        $adapter->create_server(
            self::SERVER_ID,
            $namespace,
            $route,
            __('FluentBooking MCP Server', 'fluent-booking'),
            __('AI agent tools for FluentBooking bookings, availability, event types and reports.', 'fluent-booking'),
            defined('FLUENT_BOOKING_VERSION') ? FLUENT_BOOKING_VERSION : '1.0.0',
            ['\WP\MCP\Transport\HttpTransport'],
            '\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler',
            '\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler',
            $abilityNames,
            [],
            $promptNames,
            [PermissionGate::class, 'transport']
        );
    }

    /**
     * List FluentBooking on FluentToolkit's MCP page, even while MCP is off,
     * so the operator can switch it on there.
     */
    public static function registerWithToolkit()
    {
        // Static callbacks, not closures, so a site can remove_filter them.
        add_filter('fluent_kit/mcp_products', [self::class, 'addToolkitProduct']);
        add_filter('fluent_kit/mcp_toggle_handlers', [self::class, 'addToolkitToggleHandler']);
    }

    /**
     * @param array $products
     * @return array
     */
    public static function addToolkitProduct($products)
    {
        if (!is_array($products)) {
            $products = [];
        }

        $products[] = [
            'slug'         => self::SERVER_ID,
            'name'         => __('FluentBooking', 'fluent-booking'),
            'mcp_enabled'  => PermissionGate::isEnabled(),
            'tools_count'  => self::toolsCount(),
            'endpoint_url' => self::getEndpointUrl(),
            'status'       => self::toolkitStatus(),
        ];

        return $products;
    }

    /**
     * @param array $handlers
     * @return array
     */
    public static function addToolkitToggleHandler($handlers)
    {
        if (!is_array($handlers)) {
            $handlers = [];
        }

        $handlers[self::SERVER_ID] = [
            'get_enabled' => [PermissionGate::class, 'isEnabled'],
            'set_enabled' => [PermissionGate::class, 'setEnabled'],
        ];

        return $handlers;
    }

    /**
     * Add the MCP entry to Settings. Registered while off too, since it holds
     * the switch. Priority 30 puts it after the existing entries.
     */
    public static function registerSettingsMenu()
    {
        add_filter('fluent_booking/settings_menu_items', [self::class, 'addSettingsMenuItem'], 30);
    }

    /**
     * @param array $items
     * @return array
     */
    public static function addSettingsMenuItem($items)
    {
        if (!is_array($items)) {
            $items = [];
        }

        $items['mcp'] = [
            'title'          => __('MCP for AI Agents', 'fluent-booking'),
            'disable'        => false,
            'el_icon'        => 'MagicStick',
            'component_type' => 'StandAloneComponent',
            'class'          => 'mcp_settings',
            'route'          => [
                'name' => 'mcpSettings',
            ],
        ];

        return $items;
    }

    /**
     * How many tools the server exposes for the enabled toolsets, Pro's
     * included. Prompts don't count; they aren't in the tool list.
     *
     * @return int
     */
    public static function toolsCount()
    {
        $names = AbilitiesRegistrar::getToolNames();

        $names = apply_filters('fluent_booking/mcp_ability_names', $names);

        return is_array($names) ? count(array_unique($names)) : 0;
    }

    /**
     * Status key the Toolkit renders on the FluentBooking card.
     *
     * @return string
     */
    public static function toolkitStatus()
    {
        if (!self::adapterAvailable()) {
            return 'adapter_required';
        }

        return PermissionGate::isEnabled() ? 'ready' : 'disabled';
    }

    /**
     * Stable endpoint URL for the settings UI and connection-snippet generator.
     *
     * @return string
     */
    public static function getEndpointUrl()
    {
        return get_rest_url(null, trailingslashit(self::serverNamespace()) . self::serverRoute());
    }

    /**
     * True when both an MCP adapter and the Abilities API are available.
     *
     * @return bool
     */
    public static function adapterAvailable()
    {
        return defined('WP_MCP_VERSION')
            && class_exists('\WP\MCP\Core\McpAdapter')
            && function_exists('wp_register_ability');
    }

    /**
     * Suggest privacy-policy wording while MCP is on. The connected client's
     * model provider receives attendee data, which the site owner must disclose.
     */
    public function registerPrivacyPolicyContent()
    {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }

        $content = '<p>' . __('This site can expose booking data to AI assistants over the Model Context Protocol. While it is enabled, a connected client authenticates as one WordPress user and can read attendee names, email addresses, phone numbers and booking form answers, and can create, reschedule and cancel bookings, within that account\'s permissions.', 'fluent-booking') . '</p>';

        $content .= '<p>' . __('Data a client reads leaves this site. The provider of the AI assistant is therefore a recipient of that data, and you should name them here. Access is granted per WordPress application password and is revoked by deleting it.', 'fluent-booking') . '</p>';

        wp_add_privacy_policy_content(__('FluentBooking — MCP for AI Agents', 'fluent-booking'), wp_kses_post($content));
    }

    public function maybeShowAdapterNotice()
    {
        if (self::adapterAvailable() || !current_user_can('manage_options')) {
            return;
        }

        echo '<div class="notice notice-warning"><p>';
        echo esc_html__('FluentBooking MCP is enabled but no MCP adapter was found. Install Fluent Toolkit (recommended) or the MCP Adapter plugin, on WordPress 6.9 or newer.', 'fluent-booking');
        echo '</p></div>';
    }

    /**
     * @return string
     */
    private static function serverNamespace()
    {
        return 'fluent-booking';
    }

    /**
     * @return string
     */
    private static function serverRoute()
    {
        return 'mcp';
    }
}
