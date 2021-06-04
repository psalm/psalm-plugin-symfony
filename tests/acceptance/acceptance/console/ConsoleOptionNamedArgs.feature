@symfony-common @php-8
Feature: ConsoleOption named arguments with PHP8

  Background:
    Given I have Symfony plugin enabled
    And I have the following code preamble
      """
      <?php

      use Symfony\Component\Console\Command\Command;
      use Symfony\Component\Console\Input\InputOption;
      use Symfony\Component\Console\Input\InputInterface;
      use Symfony\Component\Console\Output\OutputInterface;
      """

  Scenario: Assert adding options skipping default arguments with named arguments works as expected
    Given I have the following code
      """
      class MyCommand extends Command
      {
        public function configure(): void
        {
          $this->addOption('test', mode: InputOption::VALUE_REQUIRED, default: 'test');
        }

        public function execute(InputInterface $input, OutputInterface $output): int
        {
          /** @psalm-trace $string */
          $string = $input->getOption('test');

          return 0;
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message         |
      | Trace | $string: string |
    And I see no other errors
