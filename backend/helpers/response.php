<?php

class Response
{
    /**
     * Send a JSON response and terminate execution.
     *
     * @return never
     */
    public static function json($data = null, ?string $message = null, int $statusCode = 200): never
    {
        if (!headers_sent()) {
            http_response_code($statusCode);
            header('Content-Type: application/json; charset=UTF-8');
        }
        
        $payload = [
            'status' => $statusCode >= 200 && $statusCode < 300 ? 'success' : 'error',
            'code' => $statusCode,
        ];

        if ($message !== null) {
            $payload['message'] = $message;
        }

        if ($data !== null) {
            $payload['data'] = $data;
        }

        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Send a paginated JSON response and terminate execution.
     *
     * @return never
     */
    public static function paginated(array $items, int $totalCount, int $page, int $limit, ?string $message = null, int $statusCode = 200): never
    {
        if (!headers_sent()) {
            http_response_code($statusCode);
            header('Content-Type: application/json; charset=UTF-8');
        }
        
        $limit = max(1, $limit);
        $totalPages = (int) ceil($totalCount / $limit);
        $totalPages = max(1, $totalPages);
        $page = max(1, min($page, $totalPages));

        $payload = [
            'status' => 'success',
            'code' => $statusCode,
            'data' => $items,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $limit,
                'total_records' => $totalCount,
                'total_pages' => $totalPages,
                'has_next_page' => $page < $totalPages,
                'has_prev_page' => $page > 1
            ]
        ];

        if ($message !== null) {
            $payload['message'] = $message;
        }

        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Send an error JSON response and terminate execution.
     *
     * @return never
     */
    public static function error(string $message, int $statusCode = 400, $errors = null): never
    {
        if (!headers_sent()) {
            http_response_code($statusCode);
            header('Content-Type: application/json; charset=UTF-8');
        }
        
        $payload = [
            'status' => 'error',
            'code' => $statusCode,
            'message' => $message
        ];

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Send a 401 Unauthorized response and terminate execution.
     *
     * @return never
     */
    public static function unauthorized(string $message = 'Unauthorized access. Valid token required.'): never
    {
        self::error($message, 401);
    }

    /**
     * Send a 403 Forbidden response and terminate execution.
     *
     * @return never
     */
    public static function forbidden(string $message = 'Access forbidden. Insufficient permissions.'): never
    {
        self::error($message, 403);
    }

    /**
     * Send a 404 Not Found response and terminate execution.
     *
     * @return never
     */
    public static function notFound(string $message = 'Requested resource not found.'): never
    {
        self::error($message, 404);
    }

    /**
     * Send a 405 Method Not Allowed response and terminate execution.
     *
     * @return never
     */
    public static function methodNotAllowed(string $message = 'HTTP method not allowed.'): never
    {
        self::error($message, 405);
    }

    /**
     * Send a 500 Internal Server Error response and terminate execution.
     *
     * @return never
     */
    public static function serverError(string $message = 'Internal server error occurred.'): never
    {
        self::error($message, 500);
    }
}
