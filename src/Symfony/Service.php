<?php

namespace Seferov\SymfonyPsalmPlugin\Symfony;

class Service
{
    /**
     * @var string
     */
    private $id;
    /**
     * @var string
     */
    private $className;
    /**
     * @var bool
     */
    private $isPublic = false;
    /**
     * @var string|null
     */
    private $alias = null;

    public function __construct(string $id, string $className)
    {
        $this->id = $id;
        $this->className = $className;
    }

    public function isPublic(): bool
    {
        return $this->isPublic;
    }

    public function setIsPublic(bool $isPublic): void
    {
        $this->isPublic = $isPublic;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getClassName(): string
    {
        return $this->className;
    }

    public function getAlias(): ?string
    {
        return $this->alias;
    }

    public function setAlias(string $alias): void
    {
        $this->alias = $alias;
    }

    public function setClassName(string $className): void
    {
        $this->className = $className;
    }
}
