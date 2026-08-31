<?php

declare(strict_types=1);

namespace App\Lambda\Production;

use App\Services\ProductionService;
use Bref\Context\Context;
use UnexpectedValueException;

final readonly class CompleteProductionHandler
{
    public function __construct(private ProductionService $productions) {}

    /**
     * @param  array<string, mixed>  $event
     * @return array{playerId: string, productionId: string, status: string, completedAt: ?string}
     */
    public function __invoke(array $event, Context $context): array
    {
        $playerId = $this->requiredString($event, 'playerId');
        $productionId = $this->requiredString($event, 'productionId');
        $correlationId = $this->requiredString($event, 'correlationId');
        $completedProduction = $this->productions->complete(
            playerId: $playerId,
            productionId: $productionId,
            correlationId: $correlationId,
        );

        return [
            'playerId' => $playerId,
            'productionId' => $productionId,
            'status' => $completedProduction['status'] ?? 'ignored',
            'completedAt' => $completedProduction['completedAt'] ?? null,
        ];
    }

    /** @param array<string, mixed> $event */
    private function requiredString(array $event, string $key): string
    {
        $value = $event[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new UnexpectedValueException(
                "The Complete Production event must contain [{$key}].",
            );
        }

        return $value;
    }
}
