<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Twig;

class CachedTemplateNotFoundException extends \Exception
{
    public function __construct()
    {
        parent::__construct('No cache found for template with name(s) :');
    }

    public function addTriedName(string $possibleName): void
    {
        $this->message .= ' '.$possibleName;
    }
}
