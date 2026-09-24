<?php

namespace App\Services\Google;

class GoogleServiceAccountClient
{
    public function getKeyFilePath(): string
    {
        return storage_path('app/google/service-account.json');
    }

    public function isConfigured(): bool
    {
        return file_exists($this->getKeyFilePath());
    }
}
