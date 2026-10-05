<?php

declare(strict_types=1);

use App\Models\Plugin;
use App\Models\User;
use Livewire\Livewire;

test('recipe editor renders the author bio field as rich text', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'plugin_type' => 'recipe',
        'configuration_template' => [
            'custom_fields' => [[
                'keyname' => 'author_bio',
                'field_type' => 'author_bio',
                'name' => 'About This Plugin',
                'description' => 'Made by <strong>Jane</strong>, see <a href="https://example.com">her site</a>',
            ]],
        ],
    ]);

    Livewire::test('plugins.recipe', ['plugin' => $plugin])
        ->assertSeeHtml('Made by <strong>Jane</strong>, see <a href="https://example.com" target="_blank" rel="noreferrer noopener">her site</a>');
});
