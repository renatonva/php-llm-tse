<?php

declare(strict_types=1);

namespace App\Agent\Tools;

interface Tool
{
    public function definition(): array;

    public function run(array $args): array;
}