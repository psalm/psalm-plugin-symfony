<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Twig;

final class CachedTemplateNotFoundException extends \Exception
{
    /**
     * @psalm-capabilities read-props
     */
    public function __construct()
    {
        parent::__construct('No cache found for template with name(s) :');
    }

    /**
     * @psalm-capabilities read-props|write-this-props|write-refs
     */
    public function addTriedName(string $possibleName): void
    {
        $this->message .= ' '.$possibleName;
    }
}
