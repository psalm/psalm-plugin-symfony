<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Tests\Twig;

use PHPUnit\Framework\TestCase;
use Psalm\SymfonyPsalmPlugin\Twig\CachedTemplateNotFoundException;
use Psalm\SymfonyPsalmPlugin\Twig\CachedTemplatesRegistry;

class CachedTemplatesRegistryTest extends TestCase
{
    /**
     * @dataProvider provideNotationsMatchingTemplateName
     */
    public function testNotationMatchingTemplateName(string $templateName, string $searchedName)
    {
        $registry = new CachedTemplatesRegistry();
        $registry->addTemplate('expected_cache_class', $templateName);

        self::assertSame('expected_cache_class', $registry->getCacheClassName($searchedName));
    }

    public function provideNotationsMatchingTemplateName(): array
    {
        return [
            ['index.html.twig', 'index.html.twig'],
            ['AcmeBundle::index.html.twig', '@Acme/index.html.twig'],
            ['@Acme/index.html.twig', 'AcmeBundle::index.html.twig'],
            ['AppBundle:DataProvider/GraduateJobs:job.xml.twig', '@App/DataProvider/GraduateJobs/job.xml.twig'],
            ['AppBundle:Emails/workflow_status:default_email.html.twig', 'AppBundle:Emails:workflow_status/default_email.html.twig'],
            ['AppBundle:Emails/workflow_status/foobar:default_email.html.twig', 'AppBundle:Emails:workflow_status/foobar/default_email.html.twig'],
            ['AppBundle:Emails/workflow_status:foobar/default_email.html.twig', 'AppBundle:Emails:workflow_status/foobar/default_email.html.twig'],
            ['AppBundle:Emails:workflow_status/foobar/default_email.html.twig', 'AppBundle:Emails/workflow_status:foobar/default_email.html.twig'],
            ['AppBundle:Emails/workflow_status/foobar:default_email.html.twig', 'AppBundle:Emails/workflow_status:foobar/default_email.html.twig'],
            ['AppBundle:Emails:workflow_status/foobar/default_email.html.twig', 'AppBundle:Emails/workflow_status/foobar:default_email.html.twig'],
            ['AppBundle:Emails/workflow_status:foobar/default_email.html.twig', 'AppBundle:Emails/workflow_status/foobar:default_email.html.twig'],
        ];
    }

    public function testNotationNotMatchingTemplateName()
    {
        $registry = new CachedTemplatesRegistry();
        $registry->addTemplate('index.html.twig', 'not_expected_cache_class');

        self::expectException(CachedTemplateNotFoundException::class);
        $registry->getCacheClassName('');
    }
}
