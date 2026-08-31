<?php

namespace App\Services;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

final class FarmService
{
    private readonly Marshaler $marshaler;

    public function __construct(
        private readonly PlayerService $players,
        private readonly DynamoDbClient $dynamoDb,
    ) {
        $this->marshaler = new Marshaler;
    }

    /**
     * @return array{
     *     playerId: string,
     *     map: array{version: string, seed: string},
     *     wallet: array{coins: int, resources: array<string, int>},
     *     buildings: list<array<string, mixed>>,
     *     productions: list<array<string, mixed>>,
     *     createdAt: string,
     *     updatedAt: string
     * }|null
     */
    public function getFarm(string $playerId): ?array
    {
        if ($playerId === '') {
            throw new InvalidArgumentException('Player ID cannot be empty.');
        }

        $profile = $this->players->getProfile($playerId);

        if ($profile === null) {
            return null;
        }

        return [
            ...$profile,
            'buildings' => $this->queryPlayerItems('buildings', $playerId),
            'productions' => $this->queryPlayerItems('productions', $playerId),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function queryPlayerItems(string $tableKey, string $playerId): array
    {
        $items = [];
        $lastEvaluatedKey = null;

        do {
            $request = [
                'TableName' => $this->tableName($tableKey),
                'KeyConditionExpression' => 'player_id = :player_id',
                'ExpressionAttributeValues' => [
                    ':player_id' => ['S' => $playerId],
                ],
            ];

            if (is_array($lastEvaluatedKey)) {
                $request['ExclusiveStartKey'] = $lastEvaluatedKey;
            }

            $result = $this->dynamoDb->query($request);
            $pageItems = $result->get('Items');

            if (is_array($pageItems)) {
                foreach ($pageItems as $pageItem) {
                    if (! is_array($pageItem)) {
                        continue;
                    }

                    /** @var array<string, mixed> $item */
                    $item = $this->marshaler->unmarshalItem($pageItem);
                    $items[] = $this->publicItem($item);
                }
            }

            $evaluatedKey = $result->get('LastEvaluatedKey');
            $lastEvaluatedKey = is_array($evaluatedKey) && $evaluatedKey !== []
                ? $evaluatedKey
                : null;
        } while ($lastEvaluatedKey !== null);

        return $items;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function publicItem(array $item): array
    {
        $publicItem = [];

        foreach ($item as $attribute => $value) {
            if (in_array($attribute, ['player_id', 'schema_version'], true)) {
                continue;
            }

            $publicItem[Str::camel($attribute)] = $value;
        }

        return $publicItem;
    }

    private function tableName(string $tableKey): string
    {
        $tableName = config("services.aws.dynamodb_tables.{$tableKey}");

        if (! is_string($tableName) || $tableName === '') {
            throw new LogicException("DynamoDB table [{$tableKey}] must be configured.");
        }

        return $tableName;
    }
}
