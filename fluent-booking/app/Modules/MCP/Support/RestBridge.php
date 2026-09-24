<?php

namespace FluentBooking\App\Modules\MCP\Support;

use FluentBooking\Framework\Support\Arr;

defined('ABSPATH') || exit;

/**
 * Dispatches an MCP config write (event types, availability schedules) through
 * the same fluent-booking/v2 routes the admin SPA calls, so the policies and
 * validation rules run unchanged instead of being duplicated here.
 *
 * Reads don't go through here: admin responses carry UI scaffolding an agent
 * doesn't need, so reads use hand-built projections.
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
        // Controller::validate() only re-throws its ValidationException when
        // REST_REQUEST is set. Without it the controller runs on with invalid
        // data, so refuse rather than write unvalidated input.
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

        // A JSON body keeps nested arrays (weekly schedules, booking fields)
        // intact; a form-encoded body would flatten them.
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
     * Turn a REST failure into an MCP error carrying the message the admin UI
     * would have shown.
     *
     * @return \WP_Error
     */
    private static function translateError($response)
    {
        $status = $response->get_status();
        $data   = (array) $response->get_data();

        // Three payload shapes arrive here:
        //   {message, errors}          Controller::sendError()
        //   {code, message, data}      a WP_Error, e.g. from a policy
        //   {field: {rule: message}}   a 422 from the framework's validator
        // WP_Error::as_error() only handles the second, so read the payload directly.
        $message     = (string) Arr::get($data, 'message', '');
        $fieldErrors = self::flattenFieldErrors($data);

        if (!$message && $fieldErrors) {
            // The field reasons let the agent fix its call in one step.
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
            // A bare validator payload: every key except these is a field.
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
