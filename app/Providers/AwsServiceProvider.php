<?php

namespace App\Providers;

use Aws\DynamoDb\DynamoDbClient;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class AwsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            DynamoDbClient::class,
            function (Application $application): DynamoDbClient {
                $configuration = $application->make(Repository::class);
                $endpoint = $configuration->get('services.aws.dynamodb_endpoint');

                /** @var array<string, mixed> $clientConfiguration */
                $clientConfiguration = [
                    'version' => 'latest',
                    'region' => (string) $configuration->get('services.aws.region'),
                ];

                if (is_string($endpoint) && $endpoint !== '') {
                    $clientConfiguration['endpoint'] = $endpoint;
                }

                return new DynamoDbClient($clientConfiguration);
            },
        );
    }
}
