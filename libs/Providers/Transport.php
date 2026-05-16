<?php

declare(strict_types=1);

require_once __DIR__ . '/Messages.php';

/**
 * Thin cURL wrapper that normalizes errors into LLMException.
 */
class Transport
{
    public static function postJson(string $url, array $headers, array $body, int $timeoutSec = 60): array
    {
        $ch = curl_init();
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            throw new LLMException(LLMException::KIND_BAD_REQUEST, 'Unable to encode request body as JSON');
        }

        $hdrs = ['Content-Type: application/json', 'Accept: application/json'];
        foreach ($headers as $k => $v) {
            $hdrs[] = $k . ': ' . $v;
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $hdrs,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSec,
            CURLOPT_CONNECTTIMEOUT => min(15, $timeoutSec),
        ]);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errno === CURLE_OPERATION_TIMEDOUT) {
            throw new LLMException(LLMException::KIND_TIMEOUT, "Request to $url timed out after {$timeoutSec}s");
        }
        if ($raw === false || $errno !== 0) {
            throw new LLMException(LLMException::KIND_TRANSPORT, "Transport error: $err ($errno)");
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new LLMException(LLMException::KIND_SERVER, "Non-JSON response (HTTP $code): " . substr($raw, 0, 300));
        }

        if ($code === 401 || $code === 403) {
            throw new LLMException(LLMException::KIND_AUTH, self::extractError($decoded, "HTTP $code"));
        }
        if ($code === 429) {
            throw new LLMException(LLMException::KIND_RATE, self::extractError($decoded, "HTTP 429"));
        }
        if ($code >= 400 && $code < 500) {
            throw new LLMException(LLMException::KIND_BAD_REQUEST, self::extractError($decoded, "HTTP $code"));
        }
        if ($code >= 500) {
            throw new LLMException(LLMException::KIND_SERVER, self::extractError($decoded, "HTTP $code"));
        }

        return $decoded;
    }

    public static function getJson(string $url, array $headers, int $timeoutSec = 30): array
    {
        $ch = curl_init();
        $hdrs = ['Accept: application/json'];
        foreach ($headers as $k => $v) {
            $hdrs[] = $k . ': ' . $v;
        }
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $hdrs,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSec,
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($raw === false || $errno !== 0) {
            throw new LLMException(LLMException::KIND_TRANSPORT, "GET $url failed: " . curl_strerror($errno));
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new LLMException(LLMException::KIND_SERVER, "Non-JSON response (HTTP $code) from $url");
        }
        return $decoded;
    }

    private static function extractError(array $body, string $fallback): string
    {
        if (isset($body['error']['message'])) {
            return (string) $body['error']['message'];
        }
        if (isset($body['error']) && is_string($body['error'])) {
            return $body['error'];
        }
        if (isset($body['message'])) {
            return (string) $body['message'];
        }
        return $fallback;
    }
}
