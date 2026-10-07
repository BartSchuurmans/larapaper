---
title: Home Assistant
description: See and control your devices in Home Assistant through MQTT discovery.
---

# Home Assistant

LaraPaper can publish each device to [Home Assistant](https://www.home-assistant.io/) through [MQTT discovery](https://www.home-assistant.io/integrations/mqtt/#mqtt-discovery). Every device with a MAC address becomes a Home Assistant device with:

- **Sensors:** battery, charging, USB connected, Wi-Fi signal, last seen, online (seen within twice its refresh interval), battery voltage (disabled by default), and the latest reading of each sensor the device reports (temperature, humidity, ...).
- **Screen:** an image entity with the screen the device was last given.
- **Firmware:** an update entity with the installed and the latest firmware LaraPaper knows of; installing it updates the device at its next request.
- **Controls:** sleep mode with its times, and the refresh interval. Changes reach the device at its next request.

## Set up

1. Have an MQTT broker that Home Assistant uses, e.g. the Mosquitto broker app, with the MQTT integration set up.
2. Set `MQTT_HOST` (and `MQTT_PORT`, `MQTT_USERNAME`, `MQTT_PASSWORD`, `MQTT_TLS` as needed). See [Environment variables](/getting-started/environment-variables).
3. Make sure the scheduler is running (included in the Docker image). It runs `php artisan mqtt:publish` every 10 seconds, which publishes what changed and applies the controls Home Assistant sent.

Set `APP_URL` to link each Home Assistant device to its page in LaraPaper. Several LaraPaper installs can share a broker: each keeps its own topics (`larapaper/<instance>/...`, from the app key). Deleting a device in LaraPaper removes it from Home Assistant.
