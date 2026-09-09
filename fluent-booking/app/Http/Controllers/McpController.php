<?php

namespace FluentBooking\App\Http\Controllers;

use FluentBooking\App\Modules\MCP\AbilitiesRegistrar;
use FluentBooking\App\Modules\MCP\MCPInit;
use FluentBooking\App\Modules\MCP\Support\PermissionGate;
use FluentBooking\App\Modules\MCP\Tools\ContextTools;
use FluentBooking\Framework\Http\Request\Request;
use FluentBooking\Framework\Support\Arr;

/**
 * Settings surface for the MCP server (FluentBooking → Settings → MCP for AI
 * Agents).
 *
 * Bound to SettingsPolicy, whose verifyRequest() requires `manage_all_data`.
 * Writes additionally require `manage_options`: enabling MCP exposes booking
 * and attendee data to any client holding an application password, which is a
 * site-administration decision rather than a scheduling one. The read endpoint
 * reports `can_manage` so the UI can disable the controls instead of letting an
 * operator submit a change that would be refused.
 */
class McpController extends Controller
{
    /**
     * "FluentHub" in the UI is the fluent-toolkit plugin, which can bundle the
     * MCP adapter runtime. The standalone WordPress MCP Adapter plugin is the
     * other accepted provider.
     */
    const TOOLKIT_PLUGIN_FILE = 'fluent-toolkit/fluent-toolkit.php';

    const ADAPTER_PLUGIN_FILE = 'mcp-adapter/mcp-adapter.php';

    const TOOLKIT_DOWNLOAD_URL = 'https://github.com/WPManageNinja/fluent-toolkit';

    public function getSettings(Request $request)
    {
        return array_merge([
            'settings'           => [
                'enabled'  => PermissionGate::isEnabled(),
                'toolsets' => PermissionGate::enabledToolsets(),
            ],
            'available_toolsets' => self::withCosts(PermissionGate::availableToolsets()),
            'tools_count'        => MCPInit::toolsCount(),
            'can_manage'         => current_user_can('manage_options'),
        ], self::statusFields());
    }

    public function updateSettings(Request $request)
    {
        if (!current_user_can('manage_options')) {
            return $this->sendError([
                'message' => __('Only site administrators can change MCP settings.', 'fluent-booking'),
            ], 403);
        }

        $data = $request->get('settings', []);

        if (!is_array($data)) {
            $data = [];
        }

        // Absent keys keep their stored value rather than defaulting to off. A
        // partial POST — a caller sending only `toolsets`, say — would otherwise
        // read as "enabled: false" and silently switch the server off.
        //
        // Arr::isTrue(), never a (bool) cast: jQuery form-encodes the body, so
        // an off toggle arrives as the STRING "false" and `(bool) "false"` is
        // true — which makes the master switch one-way.
        $enabled = array_key_exists('enabled', $data)
            ? (bool) Arr::isTrue($data, 'enabled')
            : PermissionGate::isEnabled();

        $toolsets = isset($data['toolsets']) && is_array($data['toolsets'])
            ? $data['toolsets']
            : PermissionGate::enabledToolsets();

        // Toolsets first: enabling the server and its tool selection in one
        // request should never leave a window where the server is live with a
        // stale toolset list.
        PermissionGate::setToolsets($toolsets);
        PermissionGate::setEnabled($enabled);

        // get-booking-context reports the toolset list and names tools only the
        // enabled toolsets expose, so its cache has to go with a toolset change.
        ContextTools::invalidateCache();

        return [
            'message'  => __('MCP settings have been updated', 'fluent-booking'),
            'settings' => [
                'enabled'  => PermissionGate::isEnabled(),
                'toolsets' => PermissionGate::enabledToolsets(),
            ],
            'tools_count' => MCPInit::toolsCount(),
        ];
    }

    /**
     * Install FluentHub (the fluent-toolkit plugin, which carries the MCP
     * adapter) so the abilities become reachable without a manual upload.
     *
     * FluentBooking bundles no installer of its own. The actual download lives
     * behind `fluent_toolkit/do_auto_install`, which a paid tier registers; the
     * `fluent_toolkit/can_auto_install` filter reports whether that handler is
     * present. With nothing registered (free-only), the endpoint returns the
     * GitHub link so the operator can install it by hand. `install_plugins` is
     * required either way — installing a plugin is a site-administration action.
     */
    public function installAdapter(Request $request)
    {
        if (!current_user_can('install_plugins')) {
            return $this->sendError([
                'message' => __('Sorry! You do not have permission to install plugins.', 'fluent-booking'),
            ], 403);
        }

        $canAutoInstall = (bool) apply_filters('fluent_toolkit/can_auto_install', false);

        if (!$canAutoInstall) {
            return $this->sendError([
                'message'              => __('Please install FluentHub from GitHub, then reload this page to connect FluentBooking with AI agents.', 'fluent-booking'),
                'toolkit_download_url' => self::TOOLKIT_DOWNLOAD_URL,
            ], 422);
        }

        do_action('fluent_toolkit/do_auto_install');

        wp_clean_plugins_cache();

        $status = self::statusFields();

        if (Arr::get($status, 'adapter_active')) {
            $message = __('FluentHub is installed and connected. FluentBooking is now reachable by AI agents.', 'fluent-booking');
        } elseif (Arr::get($status, 'toolkit_active')) {
            $message = __('FluentHub is installed and active, but its MCP adapter is not available yet. Update FluentHub to the MCP-ready build.', 'fluent-booking');
        } elseif (Arr::get($status, 'toolkit_installed')) {
            $message = __('FluentHub was installed but could not be activated automatically. Activate it from the Plugins page.', 'fluent-booking');
        } else {
            $message = __('FluentHub could not be installed automatically. Please install it from GitHub.', 'fluent-booking');
        }

        return array_merge(['message' => $message], $status);
    }

    /**
     * Adapter / FluentHub detection shared by the read endpoint and the install
     * action so both report the same state after a change.
     *
     * @return array
     */
    private static function statusFields()
    {
        $standaloneInstalled = self::isPluginPresent(self::ADAPTER_PLUGIN_FILE);
        $standaloneActive    = self::isPluginActive(self::ADAPTER_PLUGIN_FILE);
        $toolkitInstalled    = self::isToolkitPresent();
        $toolkitLoaded       = self::isToolkitLoaded();
        $adapterAvailable    = MCPInit::adapterAvailable();

        // Which supplier is actually serving the adapter runtime.
        if ($standaloneActive && $adapterAvailable) {
            $provider = 'plugin';
        } elseif ($toolkitLoaded && $adapterAvailable) {
            $provider = 'toolkit';
        } else {
            $provider = '';
        }

        $currentUser = wp_get_current_user();

        return [
            'endpoint_url'             => MCPInit::getEndpointUrl(),
            'adapter_available'        => $adapterAvailable,
            'adapter_active'           => $adapterAvailable,
            // Any provider on disk (standalone adapter counts even when inactive).
            'adapter_installed'        => $adapterAvailable || $standaloneInstalled || $toolkitInstalled,
            'adapter_provider'         => $provider,
            'adapter_version'          => self::detectPluginVersion(self::ADAPTER_PLUGIN_FILE),
            'standalone_adapter_installed' => $standaloneInstalled,
            'toolkit_installed'        => $toolkitInstalled,
            'toolkit_active'           => $toolkitLoaded,
            'toolkit_version'          => self::detectToolkitVersion(),
            'can_install_adapter'      => current_user_can('install_plugins'),
            'can_auto_install_adapter' => (bool) apply_filters('fluent_toolkit/can_auto_install', false),
            'toolkit_download_url'     => self::TOOLKIT_DOWNLOAD_URL,
            'app_password_url'         => admin_url('profile.php#application-passwords-section'),
            'plugins_url'              => admin_url('plugins.php'),
            'current_user_login'       => $currentUser ? $currentUser->user_login : '',
            'is_local_dev'             => self::detectLocalDevEnvironment(),
            'pro_active'               => defined('FLUENT_BOOKING_PRO_DIR_FILE'),
        ];
    }

    /**
     * FluentHub loaded in this request (its constant is defined) or present on
     * disk as an installed plugin.
     *
     * @return bool
     */
    private static function isToolkitPresent()
    {
        return self::isToolkitLoaded() || self::isPluginPresent(self::TOOLKIT_PLUGIN_FILE);
    }

    /**
     * @return bool
     */
    private static function isToolkitLoaded()
    {
        return defined('FLUENT_TOOLKIT_VERSION');
    }

    /**
     * @return string|null
     */
    private static function detectToolkitVersion()
    {
        if (self::isToolkitLoaded()) {
            return (string) FLUENT_TOOLKIT_VERSION;
        }

        return self::detectPluginVersion(self::TOOLKIT_PLUGIN_FILE);
    }

    /**
     * @param string $pluginFile
     *
     * @return bool
     */
    private static function isPluginPresent($pluginFile)
    {
        return isset(self::installedPlugins()[$pluginFile]);
    }

    /**
     * @param string $pluginFile
     *
     * @return bool
     */
    private static function isPluginActive($pluginFile)
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return is_plugin_active($pluginFile);
    }

    /**
     * @param string $pluginFile
     *
     * @return string|null
     */
    private static function detectPluginVersion($pluginFile)
    {
        $plugins = self::installedPlugins();

        if (!isset($plugins[$pluginFile])) {
            return null;
        }

        return isset($plugins[$pluginFile]['Version']) ? $plugins[$pluginFile]['Version'] : null;
    }

    /**
     * @return array
     */
    private static function installedPlugins()
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return get_plugins();
    }

    /**
     * Heuristic for a local development host, used to offer the self-signed-TLS
     * override in the Claude Desktop connection snippet. Filterable so a real
     * deployment on an unusual TLD can correct it.
     *
     * @return bool
     */
    private static function detectLocalDevEnvironment()
    {
        $host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
        $isDev = false;

        // .dev is intentionally excluded — it is a real public TLD (HSTS-preloaded),
        // not a local-only suffix, so it must never trip the TLS-bypass hint.
        $devTlds = ['.test', '.lab', '.local', '.localhost', '.docker'];

        foreach ($devTlds as $tld) {
            $len = strlen($tld);

            if ($len > 0 && substr($host, -$len) === $tld) {
                $isDev = true;
                break;
            }
        }

        if (!$isDev && ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1')) {
            $isDev = true;
        }

        if (!$isDev && filter_var($host, FILTER_VALIDATE_IP)) {
            $isPrivate = !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);

            if ($isPrivate) {
                $isDev = true;
            }
        }

        return (bool) apply_filters('fluent_booking/mcp_is_local_dev', $isDev, $host);
    }

    /**
     * Attach each toolset's tool count and rough context cost.
     *
     * Every tool definition sits in the AI client's context for the whole
     * session whether it gets called or not, so the operator deciding whether
     * to switch a toolset on is really deciding how much of their agent's
     * working memory to spend. That number belongs on the toggle, not in a
     * design document.
     *
     * The measurement itself lives on AbilitiesRegistrar so this and
     * scripts/check-mcp-budget.php cannot report different figures for the
     * same toolset.
     *
     * @param array $toolsets
     *
     * @return array
     */
    private static function withCosts($toolsets)
    {
        foreach ($toolsets as $key => $meta) {
            $definitions = AbilitiesRegistrar::getDefinitions([$key]);

            // Prompts are excluded: they are not in tools/list, and their
            // bodies are fetched only when someone runs them.
            $definitions = array_filter($definitions, function ($definition) {
                return empty($definition['is_prompt']);
            });

            $bytes = 0;

            foreach ($definitions as $name => $definition) {
                $bytes += AbilitiesRegistrar::wireBytes($name, $definition);
            }

            $toolsets[$key]['tools_count']   = count($definitions);
            $toolsets[$key]['approx_tokens'] = AbilitiesRegistrar::wireTokens($bytes);
        }

        return $toolsets;
    }

}
