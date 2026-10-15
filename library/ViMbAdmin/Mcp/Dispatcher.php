<?php

/** Execute one MCP request after applying every transport and authorization guard. */
final class ViMbAdmin_Mcp_Dispatcher
{
    /**
     * @param array<string,array{
     *     handler:\Closure(array<string,mixed>):array<string,mixed>,
     *     scope:'read'|'write',
     *     destructive:bool
     * }> $methods
     * @param \Closure(array<array-key,mixed>, string):\Entities\McpToken $authenticate
     * @param \Closure(\Entities\McpToken):void $rateLimit
     */
    public function __construct(
        private readonly array $methods,
        private readonly \Closure $authenticate,
        private readonly \Closure $rateLimit,
    ) {
    }

    /**
     * @param \Closure():(string|false) $body
     * @param array<array-key,mixed> $server
     */
    public function dispatch(
        bool $enabled,
        mixed $requestMethod,
        \Closure $body,
        array $server,
    ): \ViMbAdmin\Kernel\Http\Response {
        $request = $this->request($enabled, $requestMethod, $body);
        if ($request instanceof \ViMbAdmin\Kernel\Http\Response) {
            return $request;
        }

        $id = $request['id'];
        $method = $request['method'];
        $params = $request['params'];
        $definition = $request['definition'];

        try {
            $token = ($this->authenticate)($server, $definition['scope']);
            if ($definition['destructive']) {
                ($this->rateLimit)($token);
            }
        } catch (ViMbAdmin_Mcp_Exception $error) {
            return self::http((int) $error->getCode() ?: 403, $error->getMessage(), $id);
        }

        try {
            $result = ($definition['handler'])($params);
        } catch (ViMbAdmin_Mcp_DomainException $error) {
            return self::applicationError($id, $error);
        } catch (ViMbAdmin_Mcp_Exception $error) {
            return self::applicationError($id, $error);
        } catch (\Throwable $error) {
            error_log('MCP ' . $method . ': ' . $error->getMessage());
            return self::rpcError($id, -32603, 'internal error');
        }

        return self::rpcResult($id, $result);
    }

    /**
     * @param \Closure():(string|false) $body
     * @return array{
     *     id:mixed,
     *     method:string,
     *     params:array<string,mixed>,
     *     definition:array{
     *         handler:\Closure(array<string,mixed>):array<string,mixed>,
     *         scope:'read'|'write',
     *         destructive:bool
     *     }
     * }|\ViMbAdmin\Kernel\Http\Response
     */
    private function request(
        bool $enabled,
        mixed $requestMethod,
        \Closure $body,
    ): array|\ViMbAdmin\Kernel\Http\Response {
        if (!$enabled) {
            return self::http(404, 'mcp disabled');
        }

        if (!is_string($requestMethod) || strtoupper($requestMethod) !== 'POST') {
            return self::http(405, 'POST required');
        }

        $requestBody = $body();
        if (!is_string($requestBody)) {
            return self::rpcError(null, -32700, 'parse error');
        }

        try {
            $request = ViMbAdmin_Mcp_Request::parse($requestBody);
            $request['definition'] = self::definition(
                $this->methods,
                $request['method'],
                $request['id'],
            );
        } catch (ViMbAdmin_Mcp_ProtocolException $error) {
            return self::protocolError($error);
        }

        return $request;
    }

    /**
     * @param array<string,array{
     *     handler:\Closure(array<string,mixed>):array<string,mixed>,
     *     scope:'read'|'write',
     *     destructive:bool
     * }> $methods
     * @return array{
     *     handler:\Closure(array<string,mixed>):array<string,mixed>,
     *     scope:'read'|'write',
     *     destructive:bool
     * }
     */
    public static function definition(array $methods, string $method, mixed $id): array
    {
        if (!array_key_exists($method, $methods)) {
            throw new ViMbAdmin_Mcp_ProtocolException("unknown method '{$method}'", -32601, $id);
        }

        return $methods[$method];
    }

    public static function rpcResult(mixed $id, mixed $result): \ViMbAdmin\Kernel\Http\Response
    {
        return self::json([ 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result ]);
    }

    public static function rpcError(
        mixed $id,
        int $code,
        string $message,
        int $httpStatus = 200,
    ): \ViMbAdmin\Kernel\Http\Response {
        return self::json(
            [ 'jsonrpc' => '2.0', 'id' => $id, 'error' => [ 'code' => $code, 'message' => $message ] ],
            $httpStatus,
        );
    }

    public static function protocolError(
        ViMbAdmin_Mcp_ProtocolException $error,
    ): \ViMbAdmin\Kernel\Http\Response {
        if (!$error->shouldRespond()) {
            return new \ViMbAdmin\Kernel\Http\Response('', 400, 'text/plain; charset=utf-8');
        }

        return self::rpcError($error->rpcId(), $error->rpcCode(), $error->getMessage());
    }

    public static function applicationError(
        mixed $id,
        ViMbAdmin_Mcp_Exception $error,
    ): \ViMbAdmin\Kernel\Http\Response {
        $code = $error instanceof ViMbAdmin_Mcp_DomainException ? -32010 : -32602;
        return self::rpcError($id, $code, $error->getMessage());
    }

    /** Auth/transport-level failure: HTTP status + JSON-RPC error envelope. */
    public static function http(
        int $status,
        string $message,
        mixed $id = null,
    ): \ViMbAdmin\Kernel\Http\Response {
        $response = self::rpcError($id, -32000, $message, $status);
        if ($status !== 401) {
            return $response;
        }

        return new \ViMbAdmin\Kernel\Http\Response(
            $response->body,
            $response->status,
            $response->contentType,
            [ 'WWW-Authenticate' => 'Bearer' ],
        );
    }

    private static function json(
        mixed $payload,
        int $httpStatus = 200,
    ): \ViMbAdmin\Kernel\Http\Response {
        return new \ViMbAdmin\Kernel\Http\Response(
            (string) json_encode($payload),
            $httpStatus,
            'application/json; charset=utf-8',
        );
    }
}
