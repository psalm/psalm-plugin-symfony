@symfony-common
Feature: LockableTrait

  Background:
    Given I have Symfony plugin enabled

  Scenario: PropertyNotSetInConstructor error about $lock is not raised
    Given I have the following code
      """
      <?php

      use Symfony\Component\Console\Command\Command;
      use Symfony\Component\Console\Command\LockableTrait;
      use Symfony\Component\Console\Input\InputInterface;
      use Symfony\Component\Console\Input\InputOption;
      use Symfony\Component\Console\Output\OutputInterface;

      class MyCommand extends Command
      {
        use LockableTrait;
      }
      """
    When I run Psalm
    Then I see no errors
