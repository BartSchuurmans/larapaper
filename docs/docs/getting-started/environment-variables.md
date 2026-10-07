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
| `MQTT_HOST` | MQTT broker for [Home Assistant](/usage/home-assistant); unset turns it off | `null` |
| `MQTT_PORT` | MQTT broker port | `1883` |
| `MQTT_USERNAME` / `MQTT_PASSWORD` | MQTT broker login | `null` |
| `MQTT_TLS` | Connect to the broker with TLS | `false` |
| `MQTT_DISCOVERY_PREFIX` | Home Assistant's MQTT discovery prefix | `homeassistant` |
