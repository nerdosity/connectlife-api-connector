<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use PhpMqtt\Client\Contracts\MqttClient;

class MqttService
{
    /** @var array<AcDevice> */
    private array $acDevices = [];

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

    public function setupSubscribes(): void
    {
        foreach ($this->acDevices as $device) {
            $this->setupDeviceSubscribes($device->id);
        }
    }

    public function setupDeviceSubscribes(string $id): void
    {
        $options = ['mode', 'temperature', 'fan', 'swing', 'power', 'preset'];

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
            $this->connectlifeApiService->updateDevice($acDevice->id, $properties);
        }
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

            $this->client->publish("$device->id/ac/mode/get", $device->mode, 0, true);
            $this->client->publish("$device->id/ac/temperature/get", $device->temperature, 0, true);
            $this->client->publish("$device->id/ac/current-temperature/get", $device->currentTemperature, 0, true);
            $this->client->publish("$device->id/ac/attributes/get", json_encode($device->raw['statusList']), 0, true);

            if (isset($device->fanSpeed)) {
                $this->client->publish("$device->id/ac/fan/get", $device->fanSpeed, 0, true);
            }

            if (isset($device->swing)) {
                $this->client->publish("$device->id/ac/swing/get", $device->swing, 0, true);
            }

            if (count($device->presetOptions) > 1) {
                $this->client->publish("$device->id/ac/preset/get", $device->presetMode, 0, true);
            }

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
        }
    }
}
