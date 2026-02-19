<?php

declare(strict_types=1);

namespace App\Rpc;

use App\Rpc\Section\Base;

/**
 * RPC Dispatcher
 *
 * Handles incoming REST-style requests and routes them to the appropriate
 * method handler.
 *
 * URL format: POST /rpc/{section}/{action}
 * Example: POST /rpc/transaction/getList
 *
 * Request body contains params directly:
 * { "vehicle_number": "JR-2222", "limit": 10 }
 *
 * Response format:
 * {
 *     "result": { ... },
 *     "error": null
 * }
 *
 * Or on error:
 * {
 *     "result": null,
 *     "error": "Error message"
 * }
 */
class RPC
{
    public function handle(): string
    {
        try {
            return $this->process();
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    private function process(): string
    {
        // Only accept POST requests
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $this->errorResponse('Method not allowed. Use POST.');
        }

        // Parse method from URL path
        $method = $this->parseMethodFromUrl();
        if ($method === null) {
            return $this->errorResponse('Invalid URL format. Use: /rpc/{section}/{action}');
        }

        // Parse JSON input (params directly in body)
        $input = file_get_contents('php://input');
        $params = new \stdClass();

        if (!empty(trim($input))) {
            $params = json_decode($input);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->errorResponse('Invalid JSON: ' . json_last_error_msg());
            }

            if (!is_object($params)) {
                return $this->errorResponse('Request body must be a JSON object');
            }
        }

        // Resolve method class
        $methodClass = $this->resolveMethodClass($method);
        if ($methodClass === null) {
            return $this->errorResponse("Unknown method: {$method}");
        }

        // Authenticate if required
        $authError = $this->authenticate($methodClass);
        if ($authError !== null) {
            return $this->errorResponse($authError);
        }

        // Instantiate and validate
        try {
            $methodInstance = new $methodClass($params);
            $methodInstance->validate();
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse('Validation error: ' . $e->getMessage());
        }

        // Execute
        try {
            $result = $methodInstance->process();
            return $this->successResponse($result);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Parse section and action from URL path.
     *
     * URL format: /rpc/{section}/{action}
     * Returns: "Section__Action" or null if invalid
     */
    private function parseMethodFromUrl(): ?string
    {
        $path = $_SERVER['PATH_INFO'] ?? $_SERVER['REQUEST_URI'] ?? '';

        // Remove query string if present
        $path = strtok($path, '?');

        // Remove /rpc prefix if present
        $path = preg_replace('#^/rpc/?#', '', $path);

        // Split into parts
        $parts = array_filter(explode('/', trim($path, '/')));

        if (count($parts) !== 2) {
            return null;
        }

        $section = ucfirst($parts[0]);
        $action = ucfirst($parts[1]);

        return "{$section}__{$action}";
    }

    /**
     * Resolve method name to fully qualified class name.
     *
     * Method format: "Section__Action" maps to \App\Rpc\Section\{Section}\{Action}
     * Example: "Transaction__GetList" -> \App\Rpc\Section\Transaction\GetList
     */
    private function resolveMethodClass(string $method): ?string
    {
        // Extract section and action (format: Section__Action)
        if (!str_contains($method, '__')) {
            return null;
        }

        $parts = explode('__', $method, 2);
        $section = $parts[0];
        $action = $parts[1];

        // Build full class name
        $className = "\\App\\Rpc\\Section\\{$section}\\{$action}";

        if (!class_exists($className)) {
            return null;
        }

        if (!is_subclass_of($className, Base::class)) {
            return null;
        }

        return $className;
    }

    /**
     * Check authentication if method requires it.
     */
    private function authenticate(string $methodClass): ?string
    {
        if (!$methodClass::AUTH) {
            return null; // No auth required
        }

        // Check for API key in header
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (empty($authHeader)) {
            return 'Authentication required. Provide Authorization header.';
        }

        // Simple API key auth: "Bearer <api-key>"
        if (!str_starts_with($authHeader, 'Bearer ')) {
            return 'Invalid authorization format. Use: Bearer <api-key>';
        }

        $providedKey = substr($authHeader, 7);
        $validKey = $_ENV['INTERNAL_API_KEY'] ?? '';

        if ($providedKey !== $validKey) {
            return 'Invalid API key';
        }

        return null;
    }

    private function successResponse(mixed $result): string
    {
        return json_encode([
            'result' => $result,
            'error' => null,
        ], JSON_PRETTY_PRINT);
    }

    private function errorResponse(string $message): string
    {
        http_response_code(400);
        return json_encode([
            'result' => null,
            'error' => $message,
        ], JSON_PRETTY_PRINT);
    }
}
