<?php

declare(strict_types=1);

namespace Opmin\Module\Release;

use Opmin\Info;

/**
 * Releases of opmin on GitHub. `GITHUB_TOKEN`/`GH_TOKEN` is sent to the API when set: anonymous calls share
 * 60 requests per hour of the machine's IP, which CI runners exhaust.
 *
 * @internal
 */
final readonly class GitHubReleases implements ReleaseSource
{
    /**
     * @param non-empty-string $api Base URL of the repository in the API.
     */
    public function __construct(
        private string $api = 'https://api.github.com/repos/' . Info::REPOSITORY,
        private ?string $token = null,
    ) {}

    public static function fromEnvironment(): self
    {
        foreach (['GITHUB_TOKEN', 'GH_TOKEN'] as $name) {
            $token = \getenv($name);
            if (\is_string($token) && $token !== '') {
                return new self(token: $token);
            }
        }

        return new self();
    }

    public function latest(): Release
    {
        return $this->release($this->api . '/releases/latest');
    }

    public function get(string $version): Release
    {
        return $this->release($this->api . '/releases/tags/v' . \ltrim($version, 'v'));
    }

    public function download(string $url): string
    {
        return $this->fetch($url, 'application/octet-stream', withToken: false);
    }

    /**
     * Status of the last response: redirects add their own status lines before it.
     *
     * @param list<string> $headers
     */
    private static function status(array $headers): int
    {
        $status = 200;
        foreach ($headers as $header) {
            \preg_match('~^HTTP/\S+\s+(\d{3})~', $header, $m) === 1 and $status = (int) $m[1];
        }

        return $status;
    }

    /**
     * @param non-empty-string $url
     */
    private function release(string $url): Release
    {
        /** @var mixed $data */
        $data = \json_decode($this->fetch($url, 'application/vnd.github+json', withToken: true), true);
        $tag = \is_array($data) ? ($data['tag_name'] ?? null) : null;
        $list = \is_array($data) ? ($data['assets'] ?? null) : null;
        \is_string($tag) && \is_array($list) or throw new ReleaseException("Unexpected answer of the GitHub API: {$url}");

        $assets = [];
        /** @var mixed $asset */
        foreach ($list as $asset) {
            /** @var mixed $name */
            $name = \is_array($asset) ? ($asset['name'] ?? null) : null;
            /** @var mixed $download */
            $download = \is_array($asset) ? ($asset['browser_download_url'] ?? null) : null;
            if (\is_string($name) && \is_string($download) && $name !== '' && $download !== '') {
                $assets[$name] = $download;
            }
        }

        $version = \ltrim($tag, 'v');
        $version === '' and throw new ReleaseException("The release at {$url} has an empty tag.");

        return new Release($version, $assets);
    }

    /**
     * @param non-empty-string $url
     */
    private function fetch(string $url, string $accept, bool $withToken): string
    {
        $headers = ['User-Agent: opmin/' . Info::version(), 'Accept: ' . $accept];
        $withToken && $this->token !== null and $headers[] = 'Authorization: Bearer ' . $this->token;
        $context = \stream_context_create(['http' => [
            'header' => $headers,
            'follow_location' => 1,
            'timeout' => 60,
            'ignore_errors' => true,
        ]]);

        $content = @\file_get_contents($url, false, $context);
        /** @var list<string> $http_response_header Filled by the http wrapper. */
        $status = isset($http_response_header) ? self::status($http_response_header) : 200;
        if ($content === false || $status >= 400) {
            $reason = $content === false ? (\error_get_last()['message'] ?? 'no connection') : "HTTP {$status}";
            throw new ReleaseException(match (true) {
                $status === 404 => "Not found: {$url}",
                $status === 403 => "GitHub refused the request ({$reason}): set GITHUB_TOKEN to lift the rate limit.",
                default => "Cannot download {$url}: {$reason}",
            });
        }

        return $content;
    }
}
