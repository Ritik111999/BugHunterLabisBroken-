<?php

namespace App\Support;

/**
 * In-process relay metrics (one relay worker; use /metrics for health dashboards).
 */
class MeetingRelayMetrics
{
    private static int $activeConnections = 0;

    private static int $totalConnections = 0;

    private static int $rejectedConnections = 0;

    private static int $upstreamReconnects = 0;

    private static int $upstreamKeepalivesSent = 0;

    private static int $frontendSendFailures = 0;

    private static float $startedAt = 0.0;

    public static function boot(): void
    {
        if (self::$startedAt <= 0.0) {
            self::$startedAt = microtime(true);
        }
    }

    public static function tryAcquireConnection(): bool
    {
        self::boot();
        $max = max(1, (int) config('meeting_voice.relay.max_connections', 100));
        if (self::$activeConnections >= $max) {
            self::$rejectedConnections++;

            return false;
        }
        self::$activeConnections++;
        self::$totalConnections++;

        return true;
    }

    public static function releaseConnection(): void
    {
        self::$activeConnections = max(0, self::$activeConnections - 1);
    }

    public static function recordUpstreamReconnect(): void
    {
        self::$upstreamReconnects++;
    }

    public static function recordUpstreamKeepalive(): void
    {
        self::$upstreamKeepalivesSent++;
    }

    public static function recordFrontendSendFailure(): void
    {
        self::$frontendSendFailures++;
    }

    /**
     * @return array<string, int|float>
     */
    public static function snapshot(): array
    {
        self::boot();
        $uptime = max(0.0, microtime(true) - self::$startedAt);

        return [
            'uptime_seconds' => round($uptime, 1),
            'active_connections' => self::$activeConnections,
            'total_connections' => self::$totalConnections,
            'rejected_connections' => self::$rejectedConnections,
            'upstream_reconnects' => self::$upstreamReconnects,
            'upstream_keepalives_sent' => self::$upstreamKeepalivesSent,
            'frontend_send_failures' => self::$frontendSendFailures,
            'max_connections' => max(1, (int) config('meeting_voice.relay.max_connections', 100)),
        ];
    }

    public static function prometheusBody(): string
    {
        $m = self::snapshot();
        $lines = [];
        foreach ($m as $key => $value) {
            $metric = 'wechirp_relay_'.str_replace('.', '_', (string) $key);
            $lines[] = $metric.' '.$value;
        }

        return implode("\n", $lines)."\n";
    }
}
