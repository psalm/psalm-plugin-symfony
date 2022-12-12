@symfony-5 @symfony-6
Feature: ConsoleOption

  Background:
    Given I have issue handlers "UnusedParam,UnusedVariable" suppressed
    And I have Symfony plugin enabled
    And I have the following code preamble
      """
      <?php

      use Symfony\Component\Console\Command\Command;
      use Symfony\Component\Console\Input\InputOption;
      use Symfony\Component\Console\Input\InputInterface;
      use Symfony\Component\Console\Output\OutputInterface;
      """

  Scenario: Using option mode other than defined constants raises issue
    Given I have the following code
      """
      class MyCommand extends Command
      {
        public function configure(): void
        {
          $this->addOption('option', null, 1);
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type                        | Message                                                     |
      | InvalidConsoleOptionValue   | Use Symfony\Component\Console\Input\InputOption constants   |
    And I see no other errors

  Scenario: Asserting options return types have inferred (without error)
    Given I have the following code
      """
      class MyCommand extends Command
      {
        public function configure(): void
        {
          $this->addOption('required_array', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY);
          $this->addOption('required_string', null, InputOption::VALUE_REQUIRED);
          $this->addOption('boolean', null, InputOption::VALUE_NONE);
        }

        public function execute(InputInterface $input, OutputInterface $output): int
        {
          $string = $input->getOption('required_string');
          $output->writeLn(sprintf('%s', $string ?? 'default'));

          $array = $input->getOption('required_array');
          foreach ($array as $value) {
            $output->writeLn(sprintf('%s', $value));
          };

          $this->boolean($input->getOption('boolean'));

          return 0;
        }

        private function boolean(bool $input): void
        {
        }
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: Asserting options return types have inferred (without error), with a default value
    Given I have the following code
      """
      class MyCommand extends Command
      {
        public function configure(): void
        {
          $this->addOption('option1', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, '', []);
          $this->addOption('option2', null, InputOption::VALUE_REQUIRED, '', 'default');
          $this->addOption('option3', null, InputOption::VALUE_NONE, '', true);
          $this->addOption('option4', null, InputOption::VALUE_OPTIONAL, '', false);
          $this->addOption('option5', null, InputOption::VALUE_OPTIONAL, '', null);
          $this->addOption('option6', null, InputOption::VALUE_OPTIONAL, '', 'default');
        }

        public function execute(InputInterface $input, OutputInterface $output): int
        {
          /** @psalm-trace $option1 */
          $option1 = $input->getOption('option1');

          /** @psalm-trace $option2 */
          $option2 = $input->getOption('option2');

          /** @psalm-trace $option3 */
          $option3 = $input->getOption('option3');

          /** @psalm-trace $option4 */
          $option4 = $input->getOption('option4');

          /** @psalm-trace $option5 */
          $option5 = $input->getOption('option5');

          /** @psalm-trace $option6 */
          $option6 = $input->getOption('option6');

          return 0;
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message                      |
      | Trace | $option1: array<int, string> |
      | Trace | $option2: string             |
      | Trace | $option3: bool               |
      | Trace | $option4: bool               |
      | Trace | $option5: null\|string       |
      | Trace | $option6: null\|string       |
    And I see no other errors

  Scenario: Asserting options return types have inferred with -- prefix in names
    Given I have the following code
      """
      class MyCommand extends Command
      {
        public function configure(): void
        {
          $this->addOption('--option1', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, '', []);
          $this->addOption('--option2', null, InputOption::VALUE_REQUIRED, '', 'default');
          $this->addOption('--option3', null, InputOption::VALUE_NONE, '', true);
        }

        public function execute(InputInterface $input, OutputInterface $output): int
        {
          /** @psalm-trace $option1 */
          $option1 = $input->getOption('option1');

          /** @psalm-trace $option2 */
          $option2 = $input->getOption('option2');

          /** @psalm-trace $option3 */
          $option3 = $input->getOption('option3');

          return 0;
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message                      |
      | Trace | $option1: array<int, string> |
      | Trace | $option2: string             |
      | Trace | $option3: bool               |
    And I see no other errors

  Scenario: Asserting options return types have inferred (without error) using Definition
    Given I have the following code
      """
      use Symfony\Component\Console\Input\InputDefinition;

      class MyCommand extends Command
      {
        public function configure(): void
        {
          $this->setDefinition(new InputDefinition([
            new InputOption('required_array', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
            new InputOption('required_string', null, InputOption::VALUE_REQUIRED),
            new InputOption('boolean', null, InputOption::VALUE_NONE),
          ]));
        }

        public function execute(InputInterface $input, OutputInterface $output): int
        {
          $string = $input->getOption('required_string');
          $output->writeLn(sprintf('%s', $string ?? 'default'));

          $array = $input->getOption('required_array');
          foreach ($array as $value) {
            $output->writeLn(sprintf('%s', $value));
          };

          $this->boolean($input->getOption('boolean'));

          return 0;
        }

        private function boolean(bool $input): void
        {
        }
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: Asserting options return types have inferred (without error) using Definition array
    Given I have the following code
      """
      use Symfony\Component\Console\Input\InputDefinition;

      class MyCommand extends Command
      {
        public function configure(): void
        {
          $this->setDefinition([
            new InputOption('required_array', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
            new InputOption('required_string', null, InputOption::VALUE_REQUIRED),
            new InputOption('boolean', null, InputOption::VALUE_NONE),
          ]);
        }

        public function execute(InputInterface $input, OutputInterface $output): int
        {
          $string = $input->getOption('required_string');
          $output->writeLn(sprintf('%s', $string ?? 'default'));

          $array = $input->getOption('required_array');
          foreach ($array as $value) {
            $output->writeLn(sprintf('%s', $value));
          };

          $this->boolean($input->getOption('boolean'));

          return 0;
        }

        private function boolean(bool $input): void
        {
        }
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: Asserting options return types have inferred (with errors)
    Given I have the following code
      """
      class MyCommand extends Command
      {
        public function configure(): void
        {
          $this->addOption('optional_string1');
          $this->addOption('optional_string2', null, InputOption::VALUE_OPTIONAL);
          $this->addOption('required_string3', null, InputOption::VALUE_REQUIRED);
          $this->addOption('optional_array', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY);
        }

        public function execute(InputInterface $input, OutputInterface $output): int
        {
          $string1 = $input->getOption('optional_string1');
          $output->writeLn(sprintf('%s', $string1));

          $string2 = $input->getOption('optional_string2');
          $output->writeLn(sprintf('%s', $string2));

          $string3 = $input->getOption('required_string3');
          $output->writeLn(sprintf('%s', $string3));

          $array = $input->getOption('optional_array');
          foreach ($array as $value) {
            $output->writeLn(sprintf('%s', $value));
          };

          return 0;
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type                 | Message                                                            |
      | PossiblyNullArgument | Argument 2 of sprintf cannot be null, possibly null value provided |
      | PossiblyNullArgument | Argument 2 of sprintf cannot be null, possibly null value provided |
      | PossiblyNullArgument | Argument 2 of sprintf cannot be null, possibly null value provided |
      | PossiblyNullArgument | Argument 2 of sprintf cannot be null, possibly null value provided |
    And I see no other errors

  Scenario: Cannot evaluate dynamic option names
    Given I have the following code
      """
      class MyCommand extends Command
      {
        public function configure(): void
        {
          $this->addOption('foo');
        }

        public function execute(InputInterface $input, OutputInterface $output): int
        {
          $optionName = 'foo';
          /** @psalm-trace $option */
          $option = $input->getOption($optionName);

          return 0;
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type            | Message                                                        |
      | MixedAssignment | Unable to determine the type that $option is being assigned to |
      | Trace           | $option: mixed                                                 |
    And I see no other errors
