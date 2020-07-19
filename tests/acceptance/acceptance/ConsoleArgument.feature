@symfony-common
Feature: ConsoleArgument

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
      </psalm>
      """
    And I have the following code preamble
      """
      <?php

      use Symfony\Component\Console\Command\Command;
      use Symfony\Component\Console\Input\InputArgument;
      use Symfony\Component\Console\Input\InputInterface;
      use Symfony\Component\Console\Output\OutputInterface;
      """

  Scenario: Using argument mode other than defined constants raises issue
    Given I have the following code
      """
      class MyCommand extends Command
      {
        public function configure(): void
        {
          $this->addArgument('required_string', 1, 'String required argument');
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type                        | Message                                                     |
      | InvalidConsoleArgumentValue | Use Symfony\Component\Console\Input\InputArgument constants |
    And I see no other errors

  Scenario: Asserting arguments return types have inferred (without error)
    Given I have the following code
      """
      class MyCommand extends Command
      {
        public function configure(): void
        {
          $this->addArgument('required_string', InputArgument::REQUIRED);
          $this->addArgument('required_array', InputArgument::REQUIRED | InputArgument::IS_ARRAY);
        }

        public function execute(InputInterface $input, OutputInterface $output): int
        {
          $string = $input->getArgument('required_string');
          $output->writeLn(sprintf('%s', $string));

          $array = $input->getArgument('required_array');
          shuffle($array);

          return 0;
        }
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: Asserting arguments return types have inferred (without error) using Definition array
    Given I have the following code
      """
      class MyCommand extends Command
      {
        public function configure(): void
        {
          $this->setDefinition([
            new InputArgument('required_string', InputArgument::REQUIRED),
            new InputArgument('required_array', InputArgument::REQUIRED | InputArgument::IS_ARRAY),
          ]);
        }

        public function execute(InputInterface $input, OutputInterface $output): int
        {
          $string = $input->getArgument('required_string');
          $output->writeLn(sprintf('%s', $string));

          $array = $input->getArgument('required_array');
          shuffle($array);

          return 0;
        }
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: Asserting arguments return types have inferred (without error) using Definition
    Given I have the following code
      """
      use Symfony\Component\Console\Input\InputDefinition;

      class MyCommand extends Command
      {
        public function configure(): void
        {
          $this->setDefinition(new InputDefinition([
            new InputArgument('required_string', InputArgument::REQUIRED),
            new InputArgument('required_array', InputArgument::REQUIRED | InputArgument::IS_ARRAY),
          ]));
        }

        public function execute(InputInterface $input, OutputInterface $output): int
        {
          $string = $input->getArgument('required_string');
          $output->writeLn(sprintf('%s', $string));

          $array = $input->getArgument('required_array');
          shuffle($array);

          return 0;
        }
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: Asserting arguments return types have inferred (without error) 2
    Given I have the following code
      """
      class MyCommand extends Command
      {
        const FOO_ARGUMENT_NAME = 'foo_argument_name';

        public function configure(): void
        {
          $this->addArgument(self::FOO_ARGUMENT_NAME, InputArgument::REQUIRED);
        }

        public function execute(InputInterface $input, OutputInterface $output): int
        {
          $string = $input->getArgument(self::FOO_ARGUMENT_NAME);
          $output->writeLn(sprintf('%s', $string));

          return 0;
        }
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: Asserting arguments return types have inferred (with errors)
    Given I have the following code
      """
      class MyCommand extends Command
      {
        public function configure(): void
        {
          $this->addArgument('optional_string1');
          $this->addArgument('optional_string2', InputArgument::OPTIONAL);
          $this->addArgument('optional_array1', InputArgument::OPTIONAL | InputArgument::IS_ARRAY);
          $this->addArgument('optional_array2', InputArgument::IS_ARRAY);
        }

        public function execute(InputInterface $input, OutputInterface $output): int
        {
          $string1 = $input->getArgument('optional_string1');
          $output->writeLn(sprintf('%s', $string1));
          $string2 = $input->getArgument('optional_string2');
          $output->writeLn(sprintf('%s', $string2));

          $array1 = $input->getArgument('optional_array1');
          shuffle($array1);
          $array2 = $input->getArgument('optional_array2');
          shuffle($array2);

          return 0;
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type                 | Message                                                            |
      | PossiblyNullArgument | Argument 2 of sprintf cannot be null, possibly null value provided |
      | PossiblyNullArgument | Argument 2 of sprintf cannot be null, possibly null value provided |
      | PossiblyNullArgument | Argument 1 of shuffle cannot be null, possibly null value provided |
      | PossiblyNullArgument | Argument 1 of shuffle cannot be null, possibly null value provided |
    And I see no other errors
