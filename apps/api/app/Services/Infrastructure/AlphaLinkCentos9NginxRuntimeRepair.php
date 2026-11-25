<?php

namespace App\Services\Infrastructure;

/** Restores missing package-owned Nginx files while preserving AlphaLink and local proxy configuration. */
class AlphaLinkCentos9NginxRuntimeRepair
{
    public function command(): string
    {
        return 'if ! rpm -q nginx >/dev/null 2>&1; then dnf -y install nginx >/dev/null 2>&1 || printf ALPHALINK_NGINX_RUNTIME_MISSING; fi; '
            .'if test -s /etc/nginx/mime.types && test -s /etc/nginx/nginx.conf; then printf ALPHALINK_NGINX_RUNTIME_READY; '
            .'else repair_dir=$(mktemp -d /run/alphalink-nginx-repair.XXXXXX) || exit 1; '
            .'cp -a /etc/nginx/nginx.conf "$repair_dir/nginx.conf" 2>/dev/null || true; '
            .'cp -a /etc/nginx/conf.d "$repair_dir/conf.d" 2>/dev/null || true; '
            .'cp -a /etc/nginx/default.d "$repair_dir/default.d" 2>/dev/null || true; '
            .'restore_alphalink_nginx_config() { '
            .'if test -f "$repair_dir/nginx.conf"; then cp -a "$repair_dir/nginx.conf" /etc/nginx/nginx.conf; fi; '
            .'if test -d "$repair_dir/conf.d"; then mkdir -p /etc/nginx/conf.d && cp -a "$repair_dir/conf.d/." /etc/nginx/conf.d/; fi; '
            .'if test -d "$repair_dir/default.d"; then mkdir -p /etc/nginx/default.d && cp -a "$repair_dir/default.d/." /etc/nginx/default.d/; fi; '
            .'}; '
            .'if dnf -y reinstall nginx >/dev/null 2>&1 && test -s /etc/nginx/mime.types; then '
            .'restore_alphalink_nginx_config; command -v restorecon >/dev/null 2>&1 && restorecon -RF /etc/nginx >/dev/null 2>&1 || true; '
            .'rm -rf "$repair_dir"; if test -s /etc/nginx/nginx.conf && test -s /etc/nginx/mime.types; then printf ALPHALINK_NGINX_RUNTIME_READY; else printf ALPHALINK_NGINX_RUNTIME_MISSING; fi; '
            .'else restore_alphalink_nginx_config; rm -rf "$repair_dir"; printf ALPHALINK_NGINX_RUNTIME_MISSING; fi; fi';
    }
}
