<?php

namespace Opmin\Tests\Fixture\PropertyFetch;

final class Request27
{
    public function __construct(public readonly string $target, public readonly float $receivedAt) {}
}

function serverParams27(Request27 $request, string $query): array
{
    return [
        'REQUEST_URI' => $request->target,
        'DOCUMENT_URI' => \explode('?', $request->target, 2)[0],
        'QUERY_STRING' => $query,
        'REQUEST_TIME' => (int) $request->receivedAt,
        'REQUEST_TIME_FLOAT' => $request->receivedAt,
    ];
}

?>
-----
<?php

namespace Opmin\Tests\Fixture\PropertyFetch;

final class Request27
{
    public function __construct(public readonly string $target, public readonly float $receivedAt) {}
}

function serverParams27(Request27 $request, string $query): array
{
    $target = $request->target;
    $receivedAt = $request->receivedAt;
    return [
        'REQUEST_URI' => $target,
        'DOCUMENT_URI' => \explode('?', $target, 2)[0],
        'QUERY_STRING' => $query,
        'REQUEST_TIME' => (int) $receivedAt,
        'REQUEST_TIME_FLOAT' => $receivedAt,
    ];
}

?>
