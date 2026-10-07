---
title: Environment variables
description: LaraPaper environment configuration reference.
---

# Environment variables

| Variable | Description | Default |
| --- | --- | --- |
| `TRMNL_PROXY_BASE_URL` | Base URL of the native TRMNL service | `https://trmnl.app` |
| `TRMNL_PROXY_REFRESH_MINUTES` | How often to fetch new images from the cloud service | `15` |
| `REGISTRATION_ENABLED` | Allow registration via the web UI | `1` |
| `PASSKEYS_ENABLED` | Enable passkeys (requires HTTPS) | `0` |
| `SSL_MODE` | SSL mode when not behind a reverse proxy ([docs](https://serversideup.net/open-source/docker-php/docs/customizing-the-image/configuring-ssl)) | `off` |
| `FORCE_HTTPS` | Enforce HTTPS when the server terminates SSL | `0` |
| `TRUSTED_PROXIES` | Trusted proxy CIDRs, e.g. `"172.0.0.0/8"` or `*`; also needed to [serve under a sub-path](/getting-started/installation#serving-under-a-sub-path) | `null` |
| `PHP_OPCACHE_ENABLE` | Enable PHP OPcache | `0` |
| `TRMNL_IMAGE_URL_TIMEOUT` | Display endpoint response timeout (seconds) | `30` |
| `HTTP_CLIENT_TIMEOUT` | Outbound HTTP timeout when fetching recipe polling URLs (seconds) | `10` |
| `APP_TIMEZONE` | PHP timezone (UTC recommended) | `UTC` |

## Browser assets

Framework CSS and JavaScript for recipe screens can be loaded from a mirror or local server. Publish the framework’s `/css` and `/js` paths on that host; recipe `framework_version` still selects the version segment in the URL.

| Variable | Description | Default |
| --- | --- | --- |
| `TRMNL_BLADE_FRAMEWORK_BASE_URL` | Base URL for versioned framework assets (`plugins.css`, `plugins.js`) | `https://trmnl.com` |
| `TRMNL_BLADE_FRAMEWORK_CSS_URL` | Override the framework stylesheet URL | `null` |
| `TRMNL_BLADE_FRAMEWORK_JS_URL` | Override the framework JavaScript URL | `null` |

Explicit stylesheet and JavaScript URLs take precedence over the base URL and per-recipe framework version.
