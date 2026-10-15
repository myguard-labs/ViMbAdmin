<?php

/** Strict JSON-RPC 2.0 request-object parser for the MCP endpoint. */
final class ViMbAdmin_Mcp_Request
{
    /**
     * @return array{jsonrpc:'2.0',id:mixed,method:string,params:array<string,mixed>}
     * @throws ViMbAdmin_Mcp_ProtocolException
     */
    public static function parse(string $body): array
    {
        try {
            $decoded = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ViMbAdmin_Mcp_ProtocolException('parse error', -32700);
        }
        if (self::hasDuplicateObjectMembers($body)) {
            throw new ViMbAdmin_Mcp_ProtocolException('parse error', -32700);
        }

        // This endpoint intentionally does not implement JSON-RPC batching.
        // Scalars, lists and null are valid JSON but not Request objects.
        if (!$decoded instanceof stdClass) {
            throw new ViMbAdmin_Mcp_ProtocolException('invalid request', -32600);
        }

        $request = get_object_vars($decoded);
        $hasId = array_key_exists('id', $request);
        $id = $hasId ? $request['id'] : null;
        $responseId = self::validId($id) ? $id : null;
        if (($request['jsonrpc'] ?? null) !== '2.0') {
            throw new ViMbAdmin_Mcp_ProtocolException('invalid request', -32600, $responseId);
        }
        if (!array_key_exists('method', $request) || !is_string($request['method'])) {
            throw new ViMbAdmin_Mcp_ProtocolException('invalid request', -32600, $responseId);
        }
        if ($hasId && !self::validId($id)) {
            throw new ViMbAdmin_Mcp_ProtocolException('invalid request', -32600);
        }
        // Once version and method make this a notification, JSON-RPC forbids a
        // response even when its params would be invalid. Reject it at the HTTP
        // request-only boundary before authentication or dispatch.
        if (!$hasId) {
            throw new ViMbAdmin_Mcp_ProtocolException('request id required', -32600, null, false);
        }

        $params = array_key_exists('params', $request) ? $request['params'] : new stdClass();
        if ($params instanceof stdClass) {
            $params = get_object_vars($params);
        } elseif ($params === []) {
            // Preserve compatibility with clients that send an empty positional
            // parameter list for a no-argument method.
            $params = [];
        } else {
            throw new ViMbAdmin_Mcp_ProtocolException('params must be an object', -32602, $responseId);
        }
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $request['method'],
            'params' => ViMbAdmin_Mcp_Input::map($params, 'params'),
        ];
    }

    /**
     * PHP's JSON decoder keeps the last value for a repeated object member.
     * Scan the already-validated document so decoded-equivalent names cannot
     * silently replace an earlier value. Member strings are decoded by the
     * same JSON implementation used for the complete request.
     */
    private static function hasDuplicateObjectMembers(string $body): bool
    {
        /** @var list<array{object:bool,expectingKey:bool,keys:array<string,true>}> $stack */
        $stack = [];
        $length = strlen($body);

        for ($offset = 0; $offset < $length; $offset++) {
            $character = $body[$offset];
            if ($character === '"') {
                $end = $offset + 1;
                while ($end < $length && $body[$end] !== '"') {
                    $end += $body[$end] === '\\' ? 2 : 1;
                }

                $last = array_key_last($stack);
                if ($last !== null && $stack[$last]['object'] && $stack[$last]['expectingKey']) {
                    /** @var string $member The complete document has already passed JSON validation. */
                    $member = json_decode(substr($body, $offset, $end - $offset + 1), false, 2, JSON_THROW_ON_ERROR);

                    // Prefix the decoded name so PHP cannot cast numeric-looking
                    // string keys while tracking exact JSON member identity.
                    $identity = "\0{$member}";
                    if (array_key_exists($identity, $stack[$last]['keys'])) {
                        return true;
                    }
                    $stack[$last]['keys'][$identity] = true;
                }
                $offset = $end;
                continue;
            }

            if ($character === '{') {
                $stack[] = ['object' => true, 'expectingKey' => true, 'keys' => []];
                continue;
            }
            if ($character === '[') {
                $stack[] = ['object' => false, 'expectingKey' => false, 'keys' => []];
                continue;
            }
            if ($character === '}' || $character === ']') {
                array_pop($stack);
                continue;
            }

            $last = array_key_last($stack);
            if ($last !== null && $stack[$last]['object']) {
                if ($character === ',') {
                    $stack[$last]['expectingKey'] = true;
                } elseif ($character === ':') {
                    $stack[$last]['expectingKey'] = false;
                }
            }
        }

        return false;
    }

    private static function validId(mixed $id): bool
    {
        return is_string($id) || is_int($id);
    }
}
