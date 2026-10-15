# RevoAp — Admin Security

Since v1.5.0 RevoAp ships an in-app Security tab that exposes the
runtime IP firewall and a quick-look hardening checklist. Every change
is persisted to `var/config/runtime.json` (the same store used by the
Settings tab) and takes effect on the next request without touching
`config.php` or restarting PHP-FPM.

## Visibility flag

The tab is **off by default**. To turn it on, add `SecurityTab => true`
to `config.php`:

```php
return [
    'app' => [
        // ...
        'SettingsTab' => true,
        'LogsTab'     => true,
        'SecurityTab' => true,
    ],
    // Top-level alias; same precedence rules as SettingsTab/LogsTab.
    'SecurityTab' => true,
];
```

The flag is read with the strict `Config::tabFlag()` helper — only the
literal boolean `true` enables the tab. Any other value (including
`'true'`, `1`, `'yes'`) is treated as off.

When `SecurityTab=false`:

- The Security button never appears in the top nav.
- `/api/security/save`, `/api/security/test-ip`, `/api/security/reset`
  return `404 security_disabled`.
- `/api/security/status` returns the public probe (no IP lists).

## What the tab does

1. **IP whitelist** — when enabled, only requests whose client IP
   matches one of the listed rules can reach the application. Loopback
   (`127.0.0.0/8`, `::1/128`) is always allowed and re-asserted on
   every save. The current admin's IP is auto-added to the whitelist on
   every visit to the Security tab AND when whitelist enforcement is
   being turned on, so a misclick cannot lock the operator out.
2. **IP blacklist** — matched IPs are denied with HTTP 403 before any
   controller resolves. Blacklist takes precedence over whitelist. Any
   loopback rule pasted into the blacklist is silently stripped — you
   cannot deny localhost.
3. **Trusted proxies** — a list of IPs / CIDRs whose
   `X-Forwarded-For` / `X-Real-IP` headers may be honoured. Until both
   `trust_proxy_headers=true` and `REMOTE_ADDR` matches one of these
   rules, only `REMOTE_ADDR` is trusted. **Never enable this without
   filling in trusted_proxies** — XFF is otherwise spoofable.
4. **Admin session TTL** — overrides the cookie lifetime (in minutes)
   set when the operator unlocks the Settings/Logs/Security tabs.
5. **Test an IP** — dry-run any IP/CIDR against the current rules
   without modifying state. Useful before turning on a whitelist on a
   remote host.
6. **Runtime checklist** — read-only flags surfaced from the running
   configuration: HTTPS detected, debug mode, var/ writable, security
   headers, etc. Helpful for first-time operators verifying the
   hardening surface.
7. **Reset to safe defaults** — turns enforcement off and replaces the
   whitelist with loopback only.

## Decision order

`IpFirewall::decide($ip, $state)` returns `allow` / `whitelist` /
`blacklist` in this order:

1. If `$ip` is loopback → `allow` (always).
2. Else if blacklist enforcement is ON and `$ip` matches → `blacklist`.
3. Else if whitelist enforcement is ON and `$ip` does NOT match
   → `whitelist`.
4. Else → `allow`.

Denials are logged as `ip_firewall.denied` with the offending IP, the
reason, the request method and path. The application logger is
sanitized through `Logger::sanitizeScalar`, so secrets in the request
URI never leak.

## Client IP detection

Default: `REMOTE_ADDR`. **X-Forwarded-For is never blindly trusted.**

When `trust_proxy_headers=true` AND `REMOTE_ADDR` matches one of the
configured `trusted_proxies` rules, the leftmost public IP from
`X-Forwarded-For` is used. If neither condition holds, `REMOTE_ADDR`
wins. This is what stops an attacker from spoofing their IP via the
header.

If your deployment sits behind nginx / Cloudflare / a load balancer:

- Add the proxy's egress IP / CIDR to **Trusted proxies**.
- Toggle **Trust X-Forwarded-For from listed proxies** ON.

If you don't have a proxy, leave the toggle off.

## Storage shape

```json
{
  "security": {
    "ip_whitelist_enabled":      false,
    "ip_whitelist":              ["127.0.0.0/8", "::1/128"],
    "ip_blacklist_enabled":      false,
    "ip_blacklist":              [],
    "trusted_proxies":           [],
    "trust_proxy_headers":       false,
    "admin_session_ttl_minutes": 120
  }
}
```

`var/config/runtime.json` is written with `0600` permissions and is
git-ignored.

## API surface

- `GET  /api/security/status` — public probe. When admin-authenticated
  also returns the live state and the detected client IP, and
  auto-adds that IP to the whitelist (idempotent).
- `POST /api/security/save` — replace state. Re-asserts loopback rules
  and the current admin IP whenever whitelist enforcement is on.
- `POST /api/security/test-ip` — dry-run match against the current
  rules. Body: `{"ip":"203.0.113.5"}`.
- `POST /api/security/reset` — reset to safe defaults (enforcement
  OFF, loopback-only whitelist, blacklist empty).

All four require the admin token (header `X-Admin-Token` /
`Authorization: Bearer …` / cookie `oc_admin` set after `/api/settings/unlock`).

## Operator warnings

- **Whitelist lockout** — if you enable the whitelist from a public
  network without your IP listed, you will be locked out from the
  outside (loopback still works for SSH-tunneled recovery). The tab
  protects you by auto-adding your IP, but if you save state via the
  API directly, it is your responsibility.
- **NAT / dynamic IP** — a residential IP that changes daily makes the
  whitelist a footgun. Prefer a CIDR for your office gateway, or use
  the blacklist for known-bad IPs and leave the whitelist off.
- **Reverse proxies** — without `trust_proxy_headers + trusted_proxies`,
  `REMOTE_ADDR` will be the proxy's IP for every request, so neither
  your whitelist nor your blacklist can target real users. Configure
  the proxy fields first.
- **Recovery** — if you do lock yourself out, SSH to the host and edit
  `var/config/runtime.json` directly: set `ip_whitelist_enabled` to
  `false` and reload. Loopback is always allowed, so `curl --unix-socket`
  / `127.0.0.1` access works no matter how badly the lists are set.
