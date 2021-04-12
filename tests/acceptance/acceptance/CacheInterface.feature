@symfony-common
Feature: CacheInterface

  Background:
    Given I have issue handler "UnusedClosureParam" suppressed
    And I have Symfony plugin enabled

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
