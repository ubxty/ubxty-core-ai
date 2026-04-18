<?php

namespace Ubxty\CoreAi\Exceptions;

class CostLimitExceededException extends AiException
{
    protected string $limitType;

    protected float $limit;

    protected float $current;

    public function __construct(string $limitType, float $limit, float $current)
    {
        $this->limitType = $limitType;
        $this->limit = $limit;
        $this->current = $current;

        $message = ucfirst($limitType)." cost limit (\${$limit}) exceeded. Current spend: \$".round($current, 4);
        parent::__construct($message);
    }

    public function getLimitType(): string
    {
        return $this->limitType;
    }

    public function getLimit(): float
    {
        return $this->limit;
    }

    public function getCurrentSpend(): float
    {
        return $this->current;
    }
}
