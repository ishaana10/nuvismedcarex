<?php
declare(strict_types=1);

namespace ClinicFlow\Services\Cloud;

/**
 * Small curl wrapper for cloud-storage calls. No dependencies; streams files
 * instead of loading them into memory.
 *
 * Ported from Nuvis StudioX so both products speak to the same providers the
 * same way.
 */
final class CloudHttp
{
    /**
     * @param array<int,string> $headers  "Name: value" lines
     * @param array{timeout?:int,infile?:string,body_from?:array{0:string,1:int,2:int},sink?:callable,follow?:bool} $o
     * @return array{status:int,headers:array<string,string>,body:string,error:string}
     */
    public static function request(string $method, string $url, array $headers = [], $body = null, array $o = []): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'headers' => [], 'body' => '', 'error' => 'The PHP curl extension is required for cloud storage.'];
        }
        if (!preg_match('#^https?://#i', $url)) {
            return ['status' => 0, 'headers' => [], 'body' => '', 'error' => 'Invalid URL'];
        }
        $ch = curl_init($url);
        $status = 0; $rh = []; $buf = ''; $fh = null;
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_FOLLOWLOCATION => $o['follow'] ?? true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => (int)($o['timeout'] ?? 120),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERAGENT      => 'NuvisMedcareX-CloudStorage/1.0',
            CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$status, &$rh) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) { $status = (int)$m[1]; $rh = []; }
                elseif (strpos($line, ':') !== false) { [$k, $v] = explode(':', $line, 2); $rh[strtolower(trim($k))] = trim($v); }
                return strlen($line);
            },
        ]);
        $sink = $o['sink'] ?? null;
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($c, $chunk) use (&$buf, &$status, $sink) {
            if ($sink && $status >= 200 && $status < 300) { $sink($chunk); }
            elseif (strlen($buf) < 1048576) { $buf .= $chunk; }
            return strlen($chunk);
        });
        if (isset($o['infile'])) {
            $fh = fopen($o['infile'], 'rb');
            if (!$fh) return ['status' => 0, 'headers' => [], 'body' => '', 'error' => 'Cannot read the file to upload.'];
            curl_setopt_array($ch, [CURLOPT_UPLOAD => true, CURLOPT_INFILE => $fh, CURLOPT_INFILESIZE => filesize($o['infile'])]);
        } elseif (isset($o['body_from'])) {
            [$path, $off, $len] = $o['body_from'];
            $f = fopen($path, 'rb'); fseek($f, $off); $chunk = (string)fread($f, $len); fclose($f);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $chunk);
        } elseif ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? http_build_query($body) : $body);
        }
        curl_exec($ch);
        $err = curl_errno($ch) ? curl_error($ch) : '';
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($fh) fclose($fh);
        return ['status' => $code ?: $status, 'headers' => $rh, 'body' => $buf, 'error' => $err];
    }

    /** Decode a JSON response body; returns [] when it is not JSON. */
    public static function json(array $r): array
    {
        $d = json_decode($r['body'], true);
        return is_array($d) ? $d : [];
    }

    /** Human-readable reason for a failed response. */
    public static function reason(array $r): string
    {
        if ($r['error'] !== '') return $r['error'];
        $d = self::json($r);
        $msg = $d['error_description'] ?? ($d['error']['message'] ?? ($d['error_summary'] ?? (is_string($d['error'] ?? null) ? $d['error'] : '')));
        if (is_array($msg)) $msg = json_encode($msg);
        return 'HTTP ' . $r['status'] . ($msg ? ': ' . mb_substr((string)$msg, 0, 300) : ($r['body'] !== '' ? ': ' . mb_substr(strip_tags($r['body']), 0, 200) : ''));
    }
}
