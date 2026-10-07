<?php

declare(strict_types=1);

use App\Models\Device;
use App\Services\HomeAssistantMqttService;
use PhpMqtt\Client\MqttClient;

beforeEach(function (): void {
    config(['services.mqtt.host' => 'broker.test', 'services.mqtt.discovery_prefix' => 'homeassistant']);
});

/**
 * A client that receives $commands and records what is published.
 *
 * @param  array<int, array{0: string, 1: string}>  $commands
 * @param  array<int, array{0: string, 1: string}>  $published
 */
function fakeMqttClient(array $commands, array &$published): MqttClient
{
    $client = Mockery::mock(MqttClient::class);
    $client->shouldReceive('connect')->once();
    $handlers = [];
    $client->shouldReceive('registerMessageReceivedEventHandler')->once()->andReturnUsing(function (Closure $handler) use ($client, &$handlers): MqttClient {
        $handlers[] = $handler;

        return $client;
    });
    // The broker hands out the kept controls while the client waits for them
    $client->shouldReceive('loop')->once()->andReturnUsing(function () use ($client, $commands, &$handlers): void {
        foreach ($commands as [$topic, $payload]) {
            foreach ($handlers as $handler) {
                $handler($client, $topic, $payload, MqttClient::QOS_AT_LEAST_ONCE, false);
            }
        }
    });
    $client->shouldReceive('subscribe', 'registerLoopEventHandler', 'disconnect');
    $client->shouldReceive('publish')->andReturnUsing(function (string $topic, string $payload) use (&$published): void {
        $published[] = [$topic, $payload];
    });

    return $client;
}

test('it fails without a broker', function (): void {
    config(['services.mqtt.host' => null]);

    $this->artisan('mqtt:publish')->assertExitCode(1);
});

test('it publishes what changed and removes deleted devices', function (): void {
    $device = Device::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:FF']);
    $id = 'larapaper_'.app(HomeAssistantMqttService::class)->instance().'_aabbccddeeff';

    $published = [];
    $this->app->bind(MqttClient::class, function () use (&$published): MqttClient {
        return fakeMqttClient([], $published);
    });
    $this->artisan('mqtt:publish')->assertExitCode(0);
    $topics = array_column($published, 0);
    expect($topics)->toContain("homeassistant/device/$id/config")
        ->and(json_decode($published[0][1], true)['dev']['ids'])->toBe([$id]);

    // Nothing changed
    $published = [];
    $this->artisan('mqtt:publish')->assertExitCode(0);
    expect($published)->toBeEmpty();

    $device->delete();
    $this->artisan('mqtt:publish')->assertExitCode(0);
    expect($published)->toContain(["homeassistant/device/$id/config", '']);
});

test('it applies controls before publishing', function (): void {
    $device = Device::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:FF', 'sleep_mode_enabled' => false]);
    $base = app(HomeAssistantMqttService::class)->baseTopic();

    $published = [];
    $this->app->bind(MqttClient::class, function () use ($base, &$published): MqttClient {
        return fakeMqttClient([["$base/aabbccddeeff/set/sleep_mode", 'ON']], $published);
    });
    $this->artisan('mqtt:publish')->assertExitCode(0);

    expect($device->refresh()->sleep_mode_enabled)->toBeTrue();
    $state = collect($published)->firstWhere(0, "$base/aabbccddeeff/state");
    expect(json_decode($state[1], true)['sleep_mode'])->toBe('ON');
});
