Feature: ConsoleOption

  Background:
    Given I have the following config
      """
      <?xml version="1.0"?>
      <psalm totallyTyped="true">
        <projectFiles>
          <directory name="."/>
          <ignoreFiles> <directory name="../../vendor"/> </ignoreFiles>
        </projectFiles>
        <plugins>
          <pluginClass class="Psalm\SymfonyPsalmPlugin\Plugin"/>
        </plugins>
      </psalm>
      """

  Scenario: Using option mode other than defined constants raises issue
    Given I have the following code
      """
      <?php

      use Symfony\Component\Console\Command\Command;

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
      <?php

      use Symfony\Component\Console\Command\Command;
      use Symfony\Component\Console\Input\InputOption;
      use Symfony\Component\Console\Input\InputInterface;
      use Symfony\Component\Console\Output\OutputInterface;

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
          $output->writeLn(sprintf('%s', $string));

          $array = $input->getOption('required_array');
          shuffle($array);

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
      <?php

      use Symfony\Component\Console\Command\Command;
      use Symfony\Component\Console\Input\InputOption;
      use Symfony\Component\Console\Input\InputInterface;
      use Symfony\Component\Console\Output\OutputInterface;

      class MyCommand extends Command
      {
        public function configure(): void
        {
          $this->addOption('optional_string1');
          $this->addOption('optional_string2', null, InputOption::VALUE_OPTIONAL);
        }

        public function execute(InputInterface $input, OutputInterface $output): int
        {
          $string1 = $input->getOption('optional_string1');
          $output->writeLn(sprintf('%s', $string1));
          $string2 = $input->getOption('optional_string2');
          $output->writeLn(sprintf('%s', $string2));

          return 0;
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type                 | Message                                                            |
      | PossiblyNullArgument | Argument 2 of sprintf cannot be null, possibly null value provided |
      | PossiblyNullArgument | Argument 2 of sprintf cannot be null, possibly null value provided |
    And I see no other errors

  Scenario: Cannot evaluate dynamic option names
    Given I have the following code
      """
      <?php

      use Symfony\Component\Console\Command\Command;
      use Symfony\Component\Console\Input\InputOption;
      use Symfony\Component\Console\Input\InputInterface;
      use Symfony\Component\Console\Output\OutputInterface;

      class MyCommand extends Command
      {
        public function configure(): void
        {
          $this->addOption('foo');
        }

        public function execute(InputInterface $input, OutputInterface $output): int
        {
          $optionName = 'foo';
          $string1 = $input->getOption($optionName);
          $output->writeLn(sprintf('%s', $string1));

          return 0;
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type                    | Message                                                                                                                         |
      | PossiblyInvalidArgument | Argument 2 of sprintf expects float\|int\|string, possibly different type array<array-key, string>\|bool\|null\|string provided |
    And I see no other errors
