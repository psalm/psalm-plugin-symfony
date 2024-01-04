@symfony-common @php-8
Feature: ConsoleArgument named arguments with PHP8

  Background:
    Given I have Symfony plugin enabled
    And I have the following code preamble
      """
      <?php

      use Symfony\Component\Console\Command\Command;
      use Symfony\Component\Console\Input\InputArgument;
      use Symfony\Component\Console\Input\InputInterface;
      use Symfony\Component\Console\Output\OutputInterface;
      """

  Scenario: Assert adding console argument skipping default arguments with named arguments works as expected
    Given I have the following code
      """
      class MyCommand extends Command
      {
        protected function configure(): void
        {
          $this->addArgument('test', default: 'test');
        }

        protected function execute(InputInterface $input, OutputInterface $output): int
        {
          /** @psalm-trace $argument */
          $argument = $input->getArgument('test');

          return 0;
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message           |
      | Trace | $argument: string |
    And I see no other errors

  Scenario: Assert adding console argument with only named arguments works as expected
    Given I have the following code
      """
      class MyCommand extends Command
      {
        protected function configure(): void
        {
          $this->addArgument(name: 'test', description: 'foo', mode: InputArgument::OPTIONAL, default: 'test');
        }

        protected function execute(InputInterface $input, OutputInterface $output): int
        {
          /** @psalm-trace $argument */
          $argument = $input->getArgument('test');

          return 0;
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message           |
      | Trace | $argument: string |
    And I see no other errors
