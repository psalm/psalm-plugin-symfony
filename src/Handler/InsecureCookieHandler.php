<?php

namespace Psalm\SymfonyPsalmPlugin\Handler;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\New_;
use Psalm\CodeLocation;
use Psalm\Issue\InsecureCookie;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\AfterMethodCallAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\AfterMethodCallAnalysisEvent;
use Psalm\StatementsSource;
use Psalm\Type\Atomic\TNamedObject;

/**
 * Reports a cookie made with the secure flag explicitly off (`secure: false`), which the browser also sends over plain
 * HTTP. Without the flag (null) Symfony sets it for a request made over HTTPS, so only a literal false is reported.
 */
final class InsecureCookieHandler implements AfterExpressionAnalysisInterface, AfterMethodCallAnalysisInterface
{
    private const COOKIE_CLASS = 'symfony\component\httpfoundation\cookie';

    /**
     * The offset and the name of the parameter setting the secure flag of the methods making a cookie.
     */
    private const SECURE_PARAMS = [
        self::COOKIE_CLASS.'::create' => [5, 'secure'],
        self::COOKIE_CLASS.'::withsecure' => [0, 'secure'],
    ];

    #[\Override]
    public static function afterMethodCallAnalysis(AfterMethodCallAnalysisEvent $event): void
    {
        $param = self::SECURE_PARAMS[strtolower($event->getDeclaringMethodId())] ?? null;

        if (null !== $param) {
            self::checkSecureFlag($event->getStatementsSource(), $event->getExpr()->getArgs(), ...$param);
        }
    }

    #[\Override]
    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        $expr = $event->getExpr();

        if (!$expr instanceof New_) {
            return null;
        }

        $source = $event->getStatementsSource();
        $type = $source->getNodeTypeProvider()->getType($expr);

        if (null === $type || !$type->isSingle()) {
            return null;
        }

        $object = $type->getSingleAtomic();

        if ($object instanceof TNamedObject && self::COOKIE_CLASS === strtolower($object->value)) {
            self::checkSecureFlag($source, $expr->getArgs(), 5, 'secure');
        }

        return null;
    }

    /**
     * @param array<array-key, Arg> $args
     */
    private static function checkSecureFlag(StatementsSource $source, array $args, int $offset, string $name): void
    {
        $secure = null;

        foreach ($args as $position => $arg) {
            if ($arg->unpack) {
                return;
            }

            if (null !== $arg->name ? $arg->name->name === $name : $position === $offset) {
                $secure = $arg;
            }
        }

        if (null === $secure) {
            return;
        }

        $type = $source->getNodeTypeProvider()->getType($secure->value);

        if (null === $type || !$type->isFalse()) {
            return;
        }

        IssueBuffer::maybeAdd(
            new InsecureCookie(
                'The cookie is made without the secure flag, so it is also sent over plain HTTP',
                new CodeLocation($source, $secure->value),
            ),
            $source->getSuppressedIssues(),
        );
    }
}
