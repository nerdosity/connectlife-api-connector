<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use PhpMqtt\Client\Contracts\MqttClient;

class MqttService
{
    /** @var array<AcDevice> */
    private array $acDevices = [];

    /** @var array<string, array{value: int, time: float}> */
    private array $pendingTemperatures = [];

    /** @var array<string, array{attempts: int, time: float}> */
    private array $pendingPowerOffs = [];

    public function __construct(
        private MqttClient            $client,
        private ConnectlifeApiService $connectlifeApiService
    )
    {
        try {
            foreach ($this->connectlifeApiService->getOnlineAcDevices() as $device) {
                $this->acDevices[$device->id] = $device;
            }
        } catch (\Exception $e) {
            Log::warning('Failed to load devices at startup, will retry on next poll: ' . $e->getMessage());
        }
    }

    public function setupHaDiscovery()
    {
        foreach ($this->acDevices as $device) {
            /** @var AcDevice $device */
            $this->publishHaDiscovery($device);
        }
    }

    private function publishHaDiscovery(AcDevice $device): void
    {
        $haData = $device->toHomeAssistantDiscoveryArray();
        Log::info("Publishing discovery msg for device: $device->id", [$haData]);
        $this->client->publish(
            "homeassistant/climate/$device->id/config",
            json_encode($haData),
            0,
            true
        );

        foreach ($device->toHomeAssistantSensorDiscoveries() as $sensor) {
            $this->client->publish($sensor['topic'], json_encode($sensor['payload']), 0, true);
        }
    }

    public function getMqttClient(): MqttClient
    {
        return $this->client;
    }

    public function publishLoadedDevicesState(): void
    {
        foreach ($this->acDevices as $device) {
            $this->publishCurrentDeviceState($device);
        }
    }

    public function setupSubscribes(): void
    {
        foreach ($this->acDevices as $device) {
            $this->setupDeviceSubscribes($device->id);
        }
    }

    public function setupDeviceSubscribes(string $id): void
    {
        $options = ['mode', 'temperature', 'fan', 'swing', 'power', 'preset', 'beep'];

        foreach ($options as $option) {
            $topic = "$id/ac/$option/set";
            $this->client->subscribe($topic, function (string $topic, string $message, bool $retained) {
                Log::info("Mqtt: received a $retained on [$topic] {$message}");
                $this->client->publish(str_replace('/set', '/get', $topic), $message, 0, true);

                $this->reactToMessageOnTopic($topic, $message);
            });
        }
    }

    private function reactToMessageOnTopic(string $topic, string $message): void
    {
        $topicParts = explode('/', $topic);
        $acDevice = $this->getAcDevice($topicParts[0]);
        $case = $topicParts[2];

        if ($case === 'beep') {
            $acDevice->beep = ($message === '1');
            return;
        }

        if ($case === 'mode' && $this->isModeConflicting($acDevice->id, $message)) {
            Log::warning("Mode '$message' for '$acDevice->name' conflicts with other running devices, reverting.");
            $this->client->publish("$acDevice->id/ac/mode/get", $acDevice->mode, 0, true);
            return;
        }

        match ($case) {
            'power' => $message === '1' ?: $acDevice->mode = 'off',
            'mode' => $acDevice->mode = $message,
            'temperature' => $acDevice->temperature = (int)$message,
            'fan' => $acDevice->fanSpeed = $message,
            'swing' => $acDevice->swing = $message,
            'preset' => $acDevice->presetMode = $message,
        };

        $this->updateAcDevice($acDevice, $case);
    }

    private function isModeConflicting(string $deviceId, string $newMode): bool
    {
        $coolingModes = ['cool', 'dry'];
        $heatingModes = ['heat'];

        $newIsCooling = in_array($newMode, $coolingModes);
        $newIsHeating = in_array($newMode, $heatingModes);

        if (!$newIsCooling && !$newIsHeating) {
            return false;
        }

        foreach ($this->acDevices as $id => $device) {
            if ($id === $deviceId || $device->mode === 'off') {
                continue;
            }
            if ($newIsCooling && in_array($device->mode, $heatingModes)) return true;
            if ($newIsHeating && in_array($device->mode, $coolingModes)) return true;
        }

        return false;
    }

    private function getAcDevice(string $deviceId): AcDevice
    {
        return $this->acDevices[$deviceId];
    }

    public function updateAcDevice(AcDevice $acDevice, string $changedProperty = 'mode')
    {
        $currentPower = $acDevice->raw['statusList']['t_power'] ?? '0';
        $properties = $acDevice->toMinimalApiProperties($changedProperty);

        // Turning on from off: send power-on alone first, then only the mode change without t_power
        if ($currentPower === '0' && ($properties['t_power'] ?? 0) === 1) {
            $this->connectlifeApiService->updateDevice($acDevice->id, ['t_power' => 1]);
            sleep(3);
            unset($properties['t_power']);
        }

        if (!empty($properties)) {
            $result = $this->connectlifeApiService->updateDevice($acDevice->id, $properties);

            // Some units ignore power-off while the compressor is running even though
            // the cloud accepts the command: track it and verify on the next poll.
            if (($properties['t_power'] ?? null) === 0) {
                $this->pendingPowerOffs[$acDevice->id] = ['attempts' => 0, 'time' => microtime(true)];
            } else {
                unset($this->pendingPowerOffs[$acDevice->id]);
            }

            if (($result['resultCode'] ?? 1) === 0) {
                if ($changedProperty === 'temperature' && $acDevice->mode !== 'dry') {
                    $this->pendingTemperatures[$acDevice->id] = [
                        'value' => $acDevice->temperature,
                        'time' => microtime(true),
                    ];
                }
                $this->publishCurrentDeviceState($acDevice);
            }
        }
    }

    private function publishCurrentDeviceState(AcDevice $device): void
    {
        $this->client->publish("$device->id/ac/mode/get", $device->mode, 0, true);
        $this->client->publish("$device->id/ac/temperature/get", $device->temperature, 0, true);
        $this->client->publish("$device->id/ac/current-temperature/get", $device->currentTemperature, 0, true);
        $this->client->publish("$device->id/ac/attributes/get", json_encode($device->raw['statusList']), 0, true);

        if (isset($device->fanSpeed)) {
            $this->client->publish("$device->id/ac/fan/get", $device->fanSpeed, 0, true);
        }
        if (isset($device->swing)) {
            $this->client->publish("$device->id/ac/swing/get", $device->swing, 0, true);
            $this->client->publish("$device->id/ac/swing/available", $device->swingAllowedInCurrentMode() ? 'online' : 'offline', 0, true);
        }
        if (count($device->presetOptions) > 1) {
            $this->client->publish("$device->id/ac/preset/get", $device->presetMode, 0, true);
        }
        $this->client->publish("$device->id/ac/beep/get", $device->beep ? '1' : '0', 0, true);
    }

    public function updateDevicesState()
    {
        foreach ($this->connectlifeApiService->getOnlineAcDevices() as $device) {
            $isNew = !isset($this->acDevices[$device->id]);
            $this->acDevices[$device->id] = $device;

            if ($isNew) {
                $this->setupDeviceSubscribes($device->id);
                $this->publishHaDiscovery($device);
            }

            Log::info("Updating HA device state", [$device->id]);

            $this->client->publish("$device->id/ac/mode/get", $this->resolveModeToPublish($device), 0, true);

            $pending = $this->pendingTemperatures[$device->id] ?? null;
            $tempToPublish = $device->temperature;
            if ($pending) {
                $elapsed = microtime(true) - $pending['time'];
                $confirmed = abs($device->temperature - $pending['value']) <= 1;
                $expired = $elapsed > 300;
                if ($confirmed || $expired) {
                    unset($this->pendingTemperatures[$device->id]);
                } else {
                    $tempToPublish = $pending['value'];
                }
            }
            $this->client->publish("$device->id/ac/temperature/get", $tempToPublish, 0, true);
            $this->client->publish("$device->id/ac/current-temperature/get", $device->currentTemperature, 0, true);
            $this->client->publish("$device->id/ac/attributes/get", json_encode($device->raw['statusList']), 0, true);

            if (isset($device->fanSpeed)) {
                $this->client->publish("$device->id/ac/fan/get", $device->fanSpeed, 0, true);
            }

            if (isset($device->swing)) {
                $this->client->publish("$device->id/ac/swing/get", $device->swing, 0, true);
                $this->client->publish("$device->id/ac/swing/available", $device->swingAllowedInCurrentMode() ? 'online' : 'offline', 0, true);
            }

            if (count($device->presetOptions) > 1) {
                $this->client->publish("$device->id/ac/preset/get", $device->presetMode, 0, true);
            }
            $this->client->publish("$device->id/ac/beep/get", $device->beep ? '1' : '0', 0, true);

            $statusList = $device->raw['statusList'];
            if (array_key_exists('f_electricity', $statusList)) {
                $this->client->publish("$device->id/ac/electricity/get", $statusList['f_electricity'], 0, true);
            }
            if (array_key_exists('f_votage', $statusList)) {
                $this->client->publish("$device->id/ac/voltage/get", $statusList['f_votage'], 0, true);
            }
            if (array_key_exists('daily_energy_kwh', $statusList)) {
                $this->client->publish("$device->id/ac/energy_daily/get", $statusList['daily_energy_kwh'], 0, true);
            }
            if (array_key_exists('daily_runtime_minutes', $statusList)) {
                $this->client->publish("$device->id/ac/runtime_daily/get", $statusList['daily_runtime_minutes'], 0, true);
            }

            $hasFault = 0;
            foreach ($statusList as $key => $value) {
                if (str_starts_with($key, 'f_e_') && $value !== '0') {
                    $hasFault = 1;
                    break;
                }
            }
            $this->client->publish("$device->id/ac/fault/get", $hasFault, 0, true);
        }
    }

    /**
     * While a power-off is pending verification, keep publishing 'off' to HA and
     * re-send the command if the unit is still reporting power on.
     */
    private function resolveModeToPublish(AcDevice $device): string
    {
        $pending = $this->pendingPowerOffs[$device->id] ?? null;
        if ($pending === null) {
            return $device->mode;
        }

        if (($device->raw['statusList']['t_power'] ?? '0') === '0') {
            unset($this->pendingPowerOffs[$device->id]);
            return 'off';
        }

        $elapsed = microtime(true) - $pending['time'];

        // Cloud state can lag the command briefly: don't burn a retry right away
        if ($elapsed < 15) {
            return 'off';
        }

        if ($pending['attempts'] >= 2 || $elapsed > 300) {
            Log::warning("Power off failed for '$device->name' after retries, giving up and reporting real state.");
            unset($this->pendingPowerOffs[$device->id]);
            return $device->mode;
        }

        $this->retryPowerOff($device, $pending['attempts']);
        $this->pendingPowerOffs[$device->id] = [
            'attempts' => $pending['attempts'] + 1,
            'time' => microtime(true),
        ];

        return 'off';
    }

    private function retryPowerOff(AcDevice $device, int $attempt): void
    {
        $beep = ['t_beep' => $device->beep ? 1 : 0];

        if ($attempt === 0) {
            Log::warning("Device '$device->name' still on after power off, retrying.");
            $this->connectlifeApiService->updateDevice($device->id, ['t_power' => 0] + $beep);
            return;
        }

        // Last resort: some firmwares only accept power-off once out of a compressor
        // mode — switch to fan first (the manual workaround), then power off.
        Log::warning("Device '$device->name' ignored power off retry, escalating via fan mode.");
        $fanMode = $device->modeOptions['fan_only'] ?? null;
        if ($fanMode !== null) {
            $this->connectlifeApiService->updateDevice($device->id, ['t_work_mode' => (int)$fanMode] + $beep);
            sleep(3);
        }
        $this->connectlifeApiService->updateDevice($device->id, ['t_power' => 0] + $beep);
    }
}
