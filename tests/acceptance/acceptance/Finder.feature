@symfony-common
Feature: Finder

  Background:
    Given I have Symfony plugin enabled

  Scenario: Finder is an IteratorAggregate with non-empty-string key and SplFileInfo value
    Given I have the following code
      """
      <?php

      use Symfony\Component\Finder\Finder;
      use Symfony\Component\Finder\SplFileInfo;

      function run(Finder $finder): void
      {
          foreach ($finder as $key => $file) {
              /** @psalm-trace $key */
              echo $key;
              /** @psalm-trace $file */
          }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message                                     |
      | Trace | $key: non-empty-string                      |
      | Trace | $file: Symfony\Component\Finder\SplFileInfo |
    And I see no other errors
