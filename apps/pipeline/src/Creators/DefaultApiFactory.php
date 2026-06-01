<?php

declare(strict_types=1);

namespace App\Creators;

use Amazon\CreatorsAPI\v1\com\amazon\creators\api\DefaultApi;
use Amazon\CreatorsAPI\v1\Configuration;

/**
 * Builds the SDK's DefaultApi from credentials supplied as plain env vars at
 * wiring time. The live HTTP call is a [manual] smoke, not exercised by tests;
 * tests inject a mocked DefaultApi directly into SdkCreatorsClient.
 */
final readonly class DefaultApiFactory
{
    public function __construct(
        private string $credentialId,
        private string $credentialSecret,
        private string $version,
    ) {
    }

    public function create(): DefaultApi
    {
        $config = new Configuration();
        $config->setCredentialId($this->credentialId);
        $config->setCredentialSecret($this->credentialSecret);
        $config->setVersion($this->version);

        return new DefaultApi(null, $config);
    }
}
