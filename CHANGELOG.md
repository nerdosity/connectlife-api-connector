# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.3.7] - 2026-04-17

### Fixed

- Sync `t_fan_speed_s` (stepless fan speed) with `t_fan_speed` when sending fan commands

## [2.3.6] - 2026-04-17

### Fixed

- Temperature commands now correctly sent in dry mode (controls dehumidification intensity)
- Only `fan_only` mode ignores temperature setpoint

## [2.3.5] - 2026-04-17

### Added

- MQTT sensor discovery for power (`f_electricity`, W), voltage (`f_votage`, V) and daily energy (`daily_energy_kwh`, kWh)
- Sensors auto-detected from device statusList and linked to the same HA device as the climate entity

## [2.3.4] - 2026-04-17

### Fixed

- Exponential backoff on Gigya API rate limit: 5 → 10 → 20 → 40 → 60 min between retries
- Backoff counter resets on successful login

## [2.3.3] - 2026-04-17

### Fixed

- Stop retrying Gigya login every 60s when rate-limited (errorCode 403048): wait 5 minutes before retrying

## [2.3.2] - 2026-04-17

### Added

- Preset modes: `eco`, `sleep`, `boost`, `silent` — auto-detected from device statusList (`t_eco`, `t_sleep`, `t_super`, `t_fan_mute`)
- Vertical swing support via `t_up_down` — auto-detected from statusList when no explicit swing config provided
- MQTT sensor topics for preset state published with `retain=true`
- Late device discovery: devices that come online after startup are automatically subscribed and announced to HA
- All MQTT state publishes now use `retain=true` so new subscribers get current state immediately

### Fixed

- **Command status mutex (errorCode 16)**: each MQTT command now sends only the changed property instead of the full device state (e.g. mode change sends only `t_power`+`t_work_mode`)
- Power-on from off: sends `t_power=1` alone first, waits 3s, then sends the mode/property command — avoids mutex on device initialization
- Startup resilience: container survives API failures at boot, devices loaded on next poll
- Retry on mutex: automatic 2s retry if first command attempt gets errorCode 16
- Persist Laravel cache to `/data` (HA persistent storage) so Gigya access token survives addon restarts
- Broad exception handling in MQTT loop (`\Exception` instead of `TransferException` only)
- Sync `t_fan_speed_s` with `t_fan_speed` on fan speed changes

## [2.3.1] - 2026-03-29

### Fixed

- Handle missing deviceList in API response gracefully instead of crashing MQTT loop

## [2.3.0] - 2026-03-29

### Fixed

- Preserve ECO mode (t_eco) state when changing temperature or other settings via MQTT

## [2.2.0] - 2026-03-14

### Changed

- The app now uses an API obtained by reverse engineering an iPhone app.

## [2.1.9] - 2024-05-28

### Fixed

- Logging

## [2.1.7] - 2024-05-28

### Fixed

- Composer error

## [2.1.6] - 2024-05-22

### Added

- Support for deviceTypeCode 008 (window unit AC)

## [2.1.5] - 2024-05-08

### Fixed

- getAccessToken() exception message.
- README examples.

## [2.1.4] - 2024-03-20

### Added

- Support for deviceTypeCode 006 (Portable air conditioner)

## [2.1.3] - 2024-03-10

### Fixed

- Non-AC devices from the Connectlife app were causing crashes.

## [2.1.2] - 2024-03-08

### Changed

- README update.

### Fixed

- Retain flag should be set for MQTT discovery messages.

## [2.1.1] - 2024-03-03

### Fixed

- Crash if MQTT server not available.
- Wrong config.yaml path for version reading.

## [2.1.0] - 2024-02-25

### Fixed

- Crash when swing is not supported.
- "power" on/off command.
- Using "deviceFeatureCode" instead of "deviceFeatureCode" to differentiate AC devices model.

## [2.0.0] - 2024-02-24

### Added

-   BEEPING env - option to silence buzzer
-   DEVICES_CONFIG env - stores configuration for your devices (modes, swing and fan options)
-   swing modes support

### Changed

-   Due to the deactivation of the api.connectlife.io endpoints, I decompiled the Connectlife mobile app and based on this I prepared a new version.

### Removed

-   Some HTTP API endpoints.

## [1.1.0] - 2024-02-16
