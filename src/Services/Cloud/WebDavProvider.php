<?php
declare(strict_types=1);

namespace ClinicFlow\Services\Cloud;

/** WebDAV: Nextcloud, ownCloud, Synology, Box, any DAV server. Basic auth — use an app password. */
/** WebDAV: Nextcloud, ownCloud, Synology, Box, any DAV server. Basic auth (use an app password). */
final class WebDavProvider extends CloudProvider
{
    public function id(): string { return 'webdav'; }
    public function label(): string { return 'WebDAV'; }
    public function usesOAuth(): bool { return false; }
    private function auth(): array { return ['Authorization: Basic ' . base64_encode($this->cfg['username'] . ':' . $this->cfg['password'])]; }
    private function url(array $segs): string { return rtrim((string)$this->cfg['url'], '/') . ($segs ? '/' . implode('/', array_map('rawurlencode', $segs)) : ''); }

    public function whoami(): string
    {
        if (empty($this->cfg['url'])) throw new CloudException('WebDAV address is missing.');
        $r = CloudHttp::request('PROPFIND', $this->url([]) . '/', array_merge($this->auth(), ['Depth: 0']), null, ['timeout' => 30]);
        if ($r['status'] === 401) throw new CloudException('WebDAV: the username or password was rejected.');
        if ($r['status'] !== 207 && $r['status'] !== 200) $this->fail($r, 'connection check');
        return $this->cfg['username'] . '@' . (parse_url((string)$this->cfg['url'], PHP_URL_HOST) ?: 'server');
    }

    private function mkdirs(array $segments): void
    {
        $acc = [];
        foreach ($segments as $seg) {
            $acc[] = $seg;
            $r = CloudHttp::request('MKCOL', $this->url($acc) . '/', $this->auth(), null, ['timeout' => 30]);
            if (!in_array($r['status'], [201, 405, 301, 200], true)) $this->fail($r, 'folder creation');   // 405 = already exists
        }
    }

    public function upload(string $localPath, array $segments, string $name, string $mime): array
    {
        $this->mkdirs($segments);
        $segs = array_merge($segments, [$name]);
        $r = CloudHttp::request('PUT', $this->url($segs), array_merge($this->auth(), ['Content-Type: ' . $mime]), null, ['infile' => $localPath, 'timeout' => 300]);
        if (!in_array($r['status'], [200, 201, 204], true)) $this->fail($r, 'upload');
        return ['id' => implode('/', $segs), 'url' => ''];
    }

    public function download(string $remoteId, callable $sink): void
    {
        $r = CloudHttp::request('GET', $this->url(explode('/', $remoteId)), $this->auth(), null, ['sink' => $sink, 'timeout' => 300]);
        if ($r['status'] !== 200) $this->fail($r, 'download');
    }

    public function delete(string $remoteId): void
    {
        $r = CloudHttp::request('DELETE', $this->url(explode('/', $remoteId)), $this->auth(), null, ['timeout' => 30]);
        if (!in_array($r['status'], [200, 204, 404], true)) $this->fail($r, 'delete');
    }
}
