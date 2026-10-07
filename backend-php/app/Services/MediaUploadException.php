<?php

namespace App\Services;

final class MediaUploadException extends \InvalidArgumentException
{
    public function __construct(string $message, public readonly int $httpStatus = 422)
    {
        parent::__construct($message);
    }
}
