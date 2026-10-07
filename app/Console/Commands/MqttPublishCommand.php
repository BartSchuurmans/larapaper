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
    /**
     * What earlier runs published, by Home Assistant device id.
     */
    private const string CACHE_KEY = 'mqtt:published';

    /**
     * How long to wait for the controls the broker kept for this session.
     */
    private const int COMMAND_WAIT_SECONDS = 1;

    protected $signature = 'mqtt:publish';

    protected $description = 'Publish devices to Home Assistant via MQTT discovery';

    public function handle(HomeAssistantMqttService $service): int
    {
        if (blank(config('services.mqtt.host'))) {
            $this->error('MQTT_HOST is not set.');

            return self::FAILURE;
        }

        $client = $this->client($service->instance());
        $commands = $this->receiveCommands($client, $service->baseTopic());

        foreach ($commands as [$mac, $key, $payload]) {
            if ($service->applyCommand($mac, $key, $payload)) {
                $this->info("Applied $key=$payload to $mac");
            }
        }

        $this->publishChanges($client, $service->devices());
        $client->disconnect();

        return self::SUCCESS;
    }

    /**
     * A client for the broker. It gets no logger: the app's would receive every packet.
     */
    private function client(string $instance): MqttClientContract
    {
        return $this->laravel->make(MqttClient::class, [
            'host' => config('services.mqtt.host'),
            'port' => (int) config('services.mqtt.port'),
            'clientId' => "larapaper-$instance",
            'protocol' => MqttClient::MQTT_3_1_1,
            'logger' => null,
        ]);
    }

    /**
     * Connects with a persistent session and collects the controls the broker kept. They
     * arrive right after connecting, before the subscription is made again, so they are
     * taken from every received message rather than from the subscription's callback.
     *
     * @return list<array{0: string, 1: string, 2: string}> MAC address, key and payload of each control
     */
    private function receiveCommands(MqttClientContract $client, string $baseTopic): array
    {
        $commands = [];
        $client->registerMessageReceivedEventHandler(function (MqttClientContract $client, string $topic, string $payload) use ($baseTopic, &$commands): void {
            if (preg_match('#^'.preg_quote($baseTopic, '#').'/([0-9a-f]{12})/set/([a-z_]+)$#', $topic, $match)) {
                $commands[] = [$match[1], $match[2], $payload];
            }
        });

        $client->connect(new ConnectionSettings()
            ->setUsername(config('services.mqtt.username'))
            ->setPassword(config('services.mqtt.password'))
            ->setUseTls((bool) config('services.mqtt.tls'))
            ->setConnectTimeout(10), false);
        $client->subscribe("$baseTopic/+/set/+", null, MqttClient::QOS_AT_LEAST_ONCE);

        $client->registerLoopEventHandler(function (MqttClientContract $client, float $elapsed): void {
            if ($elapsed >= self::COMMAND_WAIT_SECONDS) {
                $client->interrupt();
            }
        });
        $client->loop();

        return $commands;
    }

    /**
     * Publishes each device's config, state and screen that changed since the last run,
     * and empties the topics of devices that were deleted since.
     *
     * @param  array<string, array{config: array<string, mixed>, state: array<string, mixed>, state_topic: string, screen_topic: string, screen: ?string}>  $devices
     */
    private function publishChanges(MqttClientContract $client, array $devices): void
    {
        $prefix = config('services.mqtt.discovery_prefix');
        /** @var array<string, array{config: string, state: string, screen: ?string, topics: list<string>}> $published */
        $published = Cache::get(self::CACHE_KEY, []);

        foreach ($devices as $id => $device) {
            $config = (string) json_encode($device['config'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $state = (string) json_encode($device['state'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $screen = $device['screen'] !== null ? $device['screen'].'@'.filemtime($device['screen']) : null;
            $previous = $published[$id] ?? null;

            if ($previous === null || $previous['config'] !== md5($config)) {
                $client->publish("$prefix/device/$id/config", $config, MqttClient::QOS_AT_MOST_ONCE, true);
            }
            if ($previous === null || $previous['state'] !== md5($state)) {
                $client->publish($device['state_topic'], $state, MqttClient::QOS_AT_MOST_ONCE, true);
            }
            if ($screen !== null && $screen !== ($previous['screen'] ?? null)) {
                $client->publish($device['screen_topic'], (string) file_get_contents($device['screen']), MqttClient::QOS_AT_MOST_ONCE, true);
            }

            $published[$id] = [
                'config' => md5($config),
                'state' => md5($state),
                'screen' => $screen,
                'topics' => [$device['state_topic'], $device['screen_topic']],
            ];
        }

        foreach (array_diff_key($published, $devices) as $id => $previous) {
            $client->publish("$prefix/device/$id/config", '', MqttClient::QOS_AT_MOST_ONCE, true);
            foreach ($previous['topics'] as $topic) {
                $client->publish($topic, '', MqttClient::QOS_AT_MOST_ONCE, true);
            }
            unset($published[$id]);
        }

        Cache::forever(self::CACHE_KEY, $published);
    }
}
