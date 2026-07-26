<?php

namespace EgyptDevRu\FilamentProjectPassport;

use EgyptDevRu\FilamentProjectPassport\Services\DocumentationScanner;
use EgyptDevRu\FilamentProjectPassport\Services\LicenseApiClient;

class FilamentProjectPassport
{
    public function license(): LicenseApiClient
    {
        return app(LicenseApiClient::class);
    }

    public function documentation(): DocumentationScanner
    {
        return app(DocumentationScanner::class);
    }
}
