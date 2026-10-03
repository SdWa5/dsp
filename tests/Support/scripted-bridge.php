<?php

// A stand-in for trackdsp:bridge that answers from a script, see ScriptedBridge.
// Arguments: the socket path, a JSON list of status answers, the last one repeats, and optionally a JSON list
// of write answers, null for taken and an error code for rejected, after which every write is taken.

$socket = $argv[1] ?? '';
$statuses = json_decode($argv[2] ?? '[]', true, 16, \JSON_THROW_ON_ERROR);
$writes = json_decode($argv[3] ?? '[]', true, 16, \JSON_THROW_ON_ERROR);
if ('' === $socket || !is_array($statuses) || !is_array($writes)) {
    fwrite(\STDERR, "Usage: scripted-bridge.php <socket> <status answers as JSON> [<write answers as JSON>]\n");
    exit(1);
}
$server = stream_socket_server('unix://'.$socket, $errno, $errstr);
if (false === $server) {
    fwrite(\STDERR, "Cannot listen on $socket: $errstr\n");
    exit(1);
}
// Signal readiness after stream_socket_server has both bound and opened the listener.
fwrite(\STDOUT, "ready\n");
$log = [];
while (true) {
    // An hour without a request ends a stand-in that its test left behind
    $client = @stream_socket_accept($server, 3600);
    if (false === $client) {
        break;
    }
    $request = json_decode((string) fgets($client), true);
    if (!is_array($request)) {
        fclose($client);
        continue;
    }
    $op = $request['op'] ?? '';
    if ('log' !== $op) {
        $log[] = $request;
    }
    $code = 'write' === $op ? array_shift($writes) : null;
    if (is_string($code)) {
        fwrite($client, json_encode(['id' => $request['id'] ?? 0, 'ok' => false, 'error' => 'Scripted '.$code.'.', 'code' => $code])."\n");
        fclose($client);
        continue;
    }
    $result = match ($op) {
        'status' => array_replace(['connected' => true, 'queued' => 0, 'riders' => []], (array) (count($statuses) > 1 ? array_shift($statuses) : ($statuses[0] ?? []))),
        'log' => $log,
        default => ['queued' => 0],
    };
    fwrite($client, json_encode(['id' => $request['id'] ?? 0, 'ok' => true, 'result' => $result])."\n");
    fclose($client);
}
