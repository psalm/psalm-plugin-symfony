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

  Scenario: CacheInterface::get accepts a callback taking the save flag by value, or not taking it
    Given I have the following code
      """
      <?php

      use Symfony\Contracts\Cache\CacheInterface;
      use Symfony\Contracts\Cache\ItemInterface;

      function test(CacheInterface $cache): string
      {
        $first = $cache->get('first', function (ItemInterface $item): string {
          $item->expiresAfter(3600);

          return 'first';
        });
        $second = $cache->get('second', function (ItemInterface $item, bool $save): string {
          return $save ? 'saved' : 'second';
        });

        return $first.$second;
      }

      """
    When I run Psalm
    Then I see no errors
