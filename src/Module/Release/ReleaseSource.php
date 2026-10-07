<?php

declare(strict_types=1);

namespace Opmin\Module\Release;

/**
 * Where releases are listed and downloaded from.
 *
 * @internal
 */
interface ReleaseSource
{
    /**
     * @throws ReleaseException
     */
    public function latest(): Release;

    /**
     * @param non-empty-string $version Without the `v` prefix.
     * @throws ReleaseException
     */
    public function get(string $version): Release;

    /**
     * @param non-empty-string $url
     * @throws ReleaseException
     */
    public function download(string $url): string;
}
