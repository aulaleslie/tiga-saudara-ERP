<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     *
     * Set to '*' to trust all proxies (safe in controlled environments like Cloudflare tunnel).
     * In production with direct internet access, use specific IP ranges from Cloudflare:
     * https://www.cloudflare.com/ips/
     *
     * @var array|string|null
     */
    protected $proxies = '*';

    /**
     * The headers that should be used to detect proxies.
     *
     * Includes X-Forwarded-Proto to detect HTTPS from Cloudflare tunnel.
     * This ensures DataTables generate correct HTTPS AJAX URLs when accessed
     * via Cloudflare tunnel (https://app.tiga-saudara.my.id).
     *
     * @var int
     */
    protected $headers = Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_AWS_ELB;
}
