<?php

// A stand-in for trackdsp:bridge that answers from a script, see ScriptedBridge.
// Arguments: the socket path and a JSON list of status answers, the last one repeats.

$socket = $argv[1] ?? '';
$statuses = json_decode($argv[2] ?? '[]', true, 16, \JSON_THROW_ON_ERROR);
if ('' === $socket || !is_array($statuses)) {
    fwrite(\STDERR, "Usage: scripted-bridge.php <socket> <status answers as JSON>\n");
    exit(1);
}
$server = stream_socket_server('unix://'.$socket, $errno, $errstr);
if (false === $server) {
    fwrite(\STDERR, "Cannot listen on $socket: $errstr\n");
    exit(1);
}
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
    $result = match ($op) {
        'status' => array_replace(['connected' => true, 'queued' => 0, 'riders' => []], (array) (count($statuses) > 1 ? array_shift($statuses) : ($statuses[0] ?? []))),
        'log' => $log,
        default => ['queued' => 0],
    };
    fwrite($client, json_encode(['id' => $request['id'] ?? 0, 'ok' => true, 'result' => $result])."\n");
    fclose($client);
}
