<?php

namespace Database\Factories;

use App\Enums\FirmwareModel;
use App\Models\Firmware;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

class FirmwareFactory extends Factory
{
    protected $model = Firmware::class;

    public function definition(): array
    {
        return [
            'version_tag' => $this->faker->word(),
            'model' => FirmwareModel::Trmnl,
            'url' => $this->faker->url(),
            'latest' => $this->faker->boolean(),
            'storage_location' => $this->faker->word(),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ];
    }

    public function trmnl(): static
    {
        return $this->state(fn (): array => [
            'model' => FirmwareModel::Trmnl,
        ]);
    }

    public function trmnlX(): static
    {
        return $this->state(fn (): array => [
            'model' => FirmwareModel::TrmnlX,
        ]);
    }

    public function trmnlBwry(): static
    {
        return $this->state(fn (): array => [
            'model' => FirmwareModel::TrmnlBwry,
        ]);
    }

    public function latest(): static
    {
        return $this->state(fn (): array => [
            'latest' => true,
        ]);
    }
}
