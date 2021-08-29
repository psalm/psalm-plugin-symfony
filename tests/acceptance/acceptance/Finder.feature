@symfony-common
Feature: Finder

  Background:
    Given I have Symfony plugin enabled

  Scenario: Finder should be considered as an IteratorAggregate of SplFileInfo
    Given I have the following code
      """
      <?php

      use Symfony\Component\Finder\Finder;

      class Test
      {
          /**
           * @param iterable<SplFileInfo> $files
           */
          public static function run(iterable $files): void
          {
          }
      }

      $finder = new Finder();
      Test::run($finder);
      """
    When I run Psalm
    Then I see no errors
