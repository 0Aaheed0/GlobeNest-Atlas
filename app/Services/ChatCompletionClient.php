<?php

namespace App\Services;

interface ChatCompletionClient
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function complete(array $payload): array;
}
