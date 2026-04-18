<?php

namespace Ubxty\CoreAi\Exceptions;

class AiException extends \RuntimeException
{
    protected ?string $modelId;

    protected ?string $keyLabel;

    public function __construct(
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
        ?string $modelId = null,
        ?string $keyLabel = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->modelId = $modelId;
        $this->keyLabel = $keyLabel;
    }

    public function getModelId(): ?string
    {
        return $this->modelId;
    }

    public function getKeyLabel(): ?string
    {
        return $this->keyLabel;
    }
}
