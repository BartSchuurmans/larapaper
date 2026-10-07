<?php

namespace App\Console\Commands;

use App\Services\HomeAssistantMqttService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\Contracts\MqttClient as MqttClientContract;
use PhpMqtt\Client\MqttClient;

/**
 * Publishes the devices to Home Assistant through MQTT discovery and applies the
 * controls it sent. Runs from the scheduler when MQTT_HOST is set; each run connects,
 * publishes what changed since the last run (retained) and disconnects, so nothing runs
 * inside a device's request and a slow broker never delays a device.
 *
 * Controls sent while no run is connected wait at the broker: the session is persistent
 * and Home Assistant sends them with QoS 1.
 */
class MqttPublishCommand extends Command
{
    private const string CACHE_KEY = 'mqtt:published';

    // How long to wait for the controls the broker kept for this session
    private const int WAIT_FOR_COMMANDS_SECONDS = 1;

    protected $signature = 'mqtt:publish';

    protected $description = 'Publish devices to Home Assistant via MQTT discovery';

    public function handle(HomeAssistantMqttService $service): int
    {
        $host = config('services.mqtt.host');
        if (blank($host)) {
            $this->error('MQTT_HOST is not set.');

            return self::FAILURE;
        }

        $prefix = config('services.mqtt.discovery_prefix');
        $base = $service->baseTopic();

        /** @var MqttClientContract $client */
        $client = $this->laravel->make(MqttClient::class, [
            'host' => $host,
            'port' => (int) config('services.mqtt.port'),
            'clientId' => 'larapaper-'.$service->instance(),
            'protocol' => MqttClient::MQTT_3_1_1,
            // Not the app's logger, which would get every packet at debug level
            'logger' => null,
        ]);

        // Controls the broker kept arrive right after connecting, before the subscription
        // below is made again, so they are taken from every received message
        $commands = [];
        $client->registerMessageReceivedEventHandler(function (MqttClientContract $client, string $topic, string $payload) use ($base, &$commands): void {
            if (preg_match('#^'.preg_quote($base, '#').'/([0-9a-f]{12})/set/([a-z_]+)$#', $topic, $match)) {
                $commands[] = [$match[1], $match[2], $payload];
            }
        });
        $client->connect((new ConnectionSettings)
            ->setUsername(config('services.mqtt.username'))
            ->setPassword(config('services.mqtt.password'))
            ->setUseTls((bool) config('services.mqtt.tls'))
            ->setConnectTimeout(10), false);
        $client->subscribe("$base/+/set/+", null, MqttClient::QOS_AT_LEAST_ONCE);
        $client->registerLoopEventHandler(function (MqttClientContract $client, float $elapsed): void {
            if ($elapsed >= self::WAIT_FOR_COMMANDS_SECONDS) {
                $client->interrupt();
            }
        });
        $client->loop();

        foreach ($commands as [$mac, $key, $payload]) {
            if ($service->applyCommand($mac, $key, $payload)) {
                $this->info("Applied $key=$payload to $mac");
            }
        }

        // What earlier runs published, by device id: config and state hashes, screen file
        // and time, and the topics to clear when the device is deleted
        $published = Cache::get(self::CACHE_KEY, []);
        $devices = $service->devices();
        foreach ($devices as $id => $device) {
            $config = json_encode($device['config'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $state = json_encode($device['state'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $screen = $device['screen'] !== null ? $device['screen'].'@'.filemtime($device['screen']) : null;
            $previous = $published[$id] ?? [];

            if (($previous['config'] ?? null) !== md5($config)) {
                $client->publish("$prefix/device/$id/config", $config, MqttClient::QOS_AT_MOST_ONCE, true);
            }
            if (($previous['state'] ?? null) !== md5($state)) {
                $client->publish($device['state_topic'], $state, MqttClient::QOS_AT_MOST_ONCE, true);
            }
            if ($screen !== null && ($previous['screen'] ?? null) !== $screen) {
                $client->publish($device['screen_topic'], (string) file_get_contents($device['screen']), MqttClient::QOS_AT_MOST_ONCE, true);
            }

            $published[$id] = ['config' => md5($config), 'state' => md5($state), 'screen' => $screen,
                'topics' => [$device['state_topic'], $device['screen_topic']]];
        }

        // Devices deleted in LaraPaper leave Home Assistant
        foreach (array_diff_key($published, $devices) as $id => $previous) {
            $client->publish("$prefix/device/$id/config", '', MqttClient::QOS_AT_MOST_ONCE, true);
            foreach ($previous['topics'] ?? [] as $topic) {
                $client->publish($topic, '', MqttClient::QOS_AT_MOST_ONCE, true);
            }
            unset($published[$id]);
        }

        $client->disconnect();
        Cache::forever(self::CACHE_KEY, $published);

        return self::SUCCESS;
    }
}
