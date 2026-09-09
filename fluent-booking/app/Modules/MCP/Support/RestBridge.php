<?php

namespace FluentBooking\App\Modules\MCP\Support;

use FluentBooking\Framework\Support\Arr;

defined('ABSPATH') || exit;

/**
 * Dispatches an MCP write through the plugin's own REST API.
 *
 * Configuration writes — event types, availability schedules — carry a lot of
 * validation: slug uniqueness, timezone conversion of weekly hours, duration
 * lists, host assignment rules, refusal to delete a schedule still in use.
 * Reimplementing any of it in the MCP layer would create a second copy that
 * drifts, and the first drift is a schedule an agent saved that the admin UI
 * then refuses to load.
 *
 * So the writes go through `rest_do_request()` against the same
 * `fluent-booking/v2` routes the admin SPA calls. The policy layer runs
 * unchanged — verified: a host without permission gets 403 from an internal
 * dispatch exactly as they would over HTTP — and so does every validation rule.
 *
 * Reads deliberately do NOT go through here. Admin responses are shaped for a
 * UI and carry scaffolding an agent has no use for; paying for that in context
 * on every call is the thing this whole design exists to avoid. Reads get
 * hand-built projections instead.
 *
 * @since 2.2.6
 */
class RestBridge
{
    const NAMESPACE_PATH = '/fluent-booking/v2';

    /**
     * @param string $method GET|POST|PUT|DELETE
     * @param string $route  Path after the namespace, e.g. '/availability/3'.
     * @param array  $body   Request parameters.
     *
     * @return array|\WP_Error The response data, or the failure translated into
     *                         an MCP error the agent can act on.
     */
    public static function call($method, $route, $body = [])
    {
        // The framework's Controller::validate() only re-throws its
        // ValidationException when REST_REQUEST is set; without it the
        // exception is swallowed and the controller runs on with invalid data.
        // MCP always serves over /wp-json/ so the constant is always there —
        // but a silent validation bypass is not a failure to discover in
        // production, so refuse loudly instead of writing unvalidated data.
        if (!defined('REST_REQUEST') || !REST_REQUEST) {
            return MCPHelper::error(
                'not_a_rest_request',
                __('Configuration writes are only available over the REST transport, because that is where validation runs.', 'fluent-booking')
            );
        }

        $request = new \WP_REST_Request($method, self::NAMESPACE_PATH . $route);

        foreach ($body as $key => $value) {
            $request->set_param($key, $value);
        }

        // The plugin's controllers read the body for non-GET verbs; setting it
        // as JSON keeps nested arrays (weekly schedules, booking fields) intact
        // rather than flattening them the way a form-encoded body would.
        if ($method !== 'GET') {
            $request->set_header('content-type', 'application/json');
            $request->set_body(wp_json_encode($body));
        }

        $response = rest_do_request($request);

        if ($response->is_error()) {
            return self::translateError($response);
        }

        return (array) $response->get_data();
    }

    /**
     * Turn a REST failure into an MCP error whose message is the one the admin
     * UI would have shown, so the agent gets the real reason rather than
     * "request failed".
     *
     * @return \WP_Error
     */
    private static function translateError($response)
    {
        $status = $response->get_status();
        $data   = (array) $response->get_data();

        // Three payload shapes reach here, and none of them is the other two:
        //
        //   {message, errors}          Controller::sendError()
        //   {code, message, data}      a WP_Error, e.g. from a policy
        //   {field: {rule: message}}   a 422 from the framework's validator
        //
        // WP_Error::as_error() only understands the second and warns on the
        // others, so the payload is read directly.
        $message     = (string) Arr::get($data, 'message', '');
        $fieldErrors = self::flattenFieldErrors($data);

        if (!$message && $fieldErrors) {
            // Lead with the real reasons rather than "the request failed" —
            // an agent that is told "Event title field is required" fixes its
            // call in one step.
            $message = implode(' ', array_values($fieldErrors));
        }

        if (!$message) {
            $message = __('The request could not be completed.', 'fluent-booking');
        }

        if ($status === 401 || $status === 403) {
            return MCPHelper::error('permission_denied', $message);
        }

        if ($status === 404) {
            return MCPHelper::error('not_found', $message);
        }

        return MCPHelper::error(
            $status === 422 ? 'validation_failed' : 'request_failed',
            $message,
            $fieldErrors ? ['field_errors' => $fieldErrors] : []
        );
    }

    /**
     * Reduce whichever error shape arrived to `field => message`.
     *
     * @param array $data
     *
     * @return array
     */
    private static function flattenFieldErrors($data)
    {
        $errors = Arr::get($data, 'errors');

        if (!is_array($errors)) {
            // A bare validator payload: every key is a field, and `message` is
            // the only key that is not.
            $errors = $data;
            unset($errors['message'], $errors['code'], $errors['data']);
        }

        $flat = [];

        foreach ((array) $errors as $field => $messages) {
            if (is_string($messages)) {
                $flat[$field] = $messages;
                continue;
            }

            if (is_array($messages)) {
                $first = reset($messages);

                if (is_string($first)) {
                    $flat[$field] = $first;
                }
            }
        }

        return $flat;
    }
}
