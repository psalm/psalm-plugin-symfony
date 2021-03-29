@symfony-common
Feature: CacheInterface

  Background:
    Given I have the following config
      """
      <?xml version="1.0"?>
      <psalm errorLevel="1">
        <projectFiles>
          <directory name="."/>
          <ignoreFiles> <directory name="../../vendor"/> </ignoreFiles>
        </projectFiles>

        <plugins>
          <pluginClass class="Psalm\SymfonyPsalmPlugin\Plugin"/>
        </plugins>
        <issueHandlers>
          <UnusedVariable errorLevel="info"/>
        </issueHandlers>
      </psalm>
      """

  Scenario: CacheInterface::get has the same return type as the passed callback
    Given I have the following code
      """
      <?php

      use Psr\Cache\CacheItemInterface;
      use Symfony\Contracts\Cache\CacheInterface;

      function test(CacheInterface $cache): stdClass
      {
        return $cache->get('key', function (CacheItemInterface $item, bool &$save): stdClass {
          return new stdClass();
        });
      }

      """
    When I run Psalm
    Then I see no errors
