<?php

namespace FluentBooking\App\Modules\MCP;

use FluentBooking\App\Modules\MCP\Support\PermissionGate;
use FluentBooking\App\Modules\MCP\Tools\ContextTools;

defined('ABSPATH') || exit;

/**
 * Bootstrap for FluentBooking's Model Context Protocol integration.
 *
 * Wires the WordPress Abilities API (core 6.9+) to the WP MCP Adapter, which is
 * provided by FluentToolkit (which bundles it) or the standalone mcp-adapter
 * plugin — whichever is present. FluentBooking bundles nothing; it consumes
 * whatever is loaded, and surfaces an admin notice rather than failing silently
 * when neither is.
 *
 * The whole surface is gated behind PermissionGate::isEnabled() (default off).
 * An operator turns it on in Settings and connects with an application password.
 * Even when on, the endpoint sits behind WP authentication, a FluentBooking
 * permission (transport gate), and per-ability permission checks.
 *
 * Called once from app/Hooks/actions.php.
 */
class MCPInit
{
    const SERVER_ID = 'fluent-booking';

    /**
     * Bootstrap entry point.
     *
     * Toolkit discovery and the settings card register UNCONDITIONALLY: the
     * Toolkit needs to list FluentBooking on its MCP page while the feature is
     * still off, or the operator has no way to find the switch. Both are
     * add_filter calls that no-op unless something applies them, so the cost
     * when nothing does is nil.
     *
     * The server itself is instantiated only when enabled, so a site that never
     * turns MCP on pays nothing beyond one autoloaded option read.
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
        // Abilities API hooks — fire only on WP 6.9+ (or with the Abilities API
        // feature plugin active).
        add_action('wp_abilities_api_categories_init', [$this, 'registerCategory']);
        add_action('wp_abilities_api_init', [$this, 'registerAbilities']);

        add_action('admin_init', [$this, 'registerPrivacyPolicyContent']);

        // Server registration — fires only when an adapter is loaded.
        add_action('mcp_adapter_init', [$this, 'registerCustomServer']);

        // Keep get-booking-context honest: drop its cache whenever something it
        // reports changes, so an operator's edit is visible to the agent on the
        // next call instead of up to CACHE_TTL later. Static callback so a site
        // can remove_action it.
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

        // Warn the operator if they enabled MCP but no adapter is installed.
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
         * Fires after FluentBooking registers its core MCP abilities.
         * FluentBooking Pro hooks this to register its own abilities (payments)
         * under the same `fluent-booking/` namespace and on the same server.
         *
         * @since 2.3.0
         */
        do_action('fluent_booking/mcp_loaded');
    }

    /**
     * Register the dedicated FluentBooking MCP server. The endpoint defaults to
     * /wp-json/fluent-booking/mcp — a sibling of the admin REST namespace
     * (fluent-booking/v2) but deliberately outside it, so it is not caught by
     * that policy stack.
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
         * Pro and extensions push their ability names here so they land on the
         * same server as the free ones.
         *
         * @since 2.3.0
         *
         * @param array $abilityNames fully-qualified ability names
         */
        $abilityNames = apply_filters('fluent_booking/mcp_ability_names', $abilityNames);
        $abilityNames = array_values(array_unique(array_filter((array) $abilityNames)));

        // Prompts are a separate argument to create_server(). Listed as tools
        // they would show up in tools/list carrying a body of instructions —
        // both wrong and, at a few hundred tokens each, expensive.
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
     * Announce FluentBooking to FluentToolkit's MCP page.
     *
     * The Toolkit discovers products through `fluent_kit/mcp_products` and
     * toggles them through `fluent_kit/mcp_toggle_handlers`. Without these,
     * FluentBooking's server would be fully functional yet never appear in the
     * Toolkit's list, which reads to an operator as "not supported".
     *
     * Runs even while MCP is off so the card is reachable to switch on.
     */
    public static function registerWithToolkit()
    {
        // Static callbacks rather than closures so a site can remove_filter
        // them — a closure registered here would be unreachable forever.
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
     * Add the MCP entry to FluentBooking → Settings.
     *
     * Registered even while the feature is off — it is the only place an
     * operator can turn it on, so hiding it when disabled would make the switch
     * unreachable. Priority 30 keeps it after the existing settings entries
     * rather than in the middle of them.
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
     * How many abilities the server currently exposes, Pro's included. Reflects
     * the operator's toolset selection, because that is the number that governs
     * how much of every request's context window this server occupies.
     *
     * @return int
     */
    public static function toolsCount()
    {
        // Tools only: prompts do not occupy the tool list, which is the number
        // this count exists to report.
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
     * Suggest privacy-policy wording while MCP is on.
     *
     * Enabling this makes whichever model provider the connected client uses a
     * recipient of attendee data the moment a read tool is called — the site
     * owner is the controller and has to disclose that. WordPress has a place
     * for exactly this text; not using it left the transfer undisclosed
     * everywhere except the settings screen.
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
