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
        <issueHandlers>
          <UnusedVariable errorLevel="info"/>
        </issueHandlers>
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
          /** @psalm-trace $arg1 */
          $arg1 = $input->getArgument('required_string');

          /** @psalm-trace $arg2 */
          $arg2 = $input->getArgument('required_array');

          return 0;
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message                   |
      | Trace | $arg1: string             |
      | Trace | $arg2: array<int, string> |
    And I see no other errors

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
          /** @psalm-trace $arg1 */
          $arg1 = $input->getArgument('required_string');

          /** @psalm-trace $arg2 */
          $arg2 = $input->getArgument('required_array');

          return 0;
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message                   |
      | Trace | $arg1: string             |
      | Trace | $arg2: array<int, string> |
    And I see no other errors

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
          /** @psalm-trace $arg1 */
          $arg1 = $input->getArgument('required_string');

          /** @psalm-trace $arg2 */
          $arg2 = $input->getArgument('required_array');

          return 0;
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message                   |
      | Trace | $arg1: string             |
      | Trace | $arg2: array<int, string> |
    And I see no other errors

  Scenario: Asserting arguments return types have inferred with const name
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
          /** @psalm-trace $arg1 */
          $arg1 = $input->getArgument(self::FOO_ARGUMENT_NAME);

          return 0;
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message             |
      | Trace | $arg1: string       |
    And I see no other errors

  Scenario: Asserting string arguments return types have inferred
    Given I have the following code
      """
      class MyCommand extends Command
      {
        public function configure(): void
        {
          $this
            ->addArgument('arg1', InputArgument::REQUIRED)
            ->addArgument('arg2')
            ->addArgument('arg3', InputArgument::OPTIONAL)
            ->addArgument('arg4', InputArgument::OPTIONAL)
            ->addArgument('arg5', InputArgument::OPTIONAL, '', 'default value')
            ->addArgument('arg6', InputArgument::OPTIONAL)
            ->addArgument('arg7', InputArgument::OPTIONAL, '', null)
          ;
        }

        public function execute(InputInterface $input, OutputInterface $output): int
        {
          /** @psalm-trace $arg1 */
          $arg1 = $input->getArgument('arg1');

          /** @psalm-trace $arg2 */
          $arg2 = $input->getArgument('arg2');

          /** @psalm-trace $arg3 */
          $arg3 = $input->getArgument('arg3');

          /** @psalm-trace $arg4 */
          $arg4 = $input->getArgument('arg4');

          /** @psalm-trace $arg5 */
          $arg5 = $input->getArgument('arg5');

          /** @psalm-trace $arg6 */
          $arg6 = $input->getArgument('arg6');

          /** @psalm-trace $arg7 */
          $arg7 = $input->getArgument('arg7');

          return 0;
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message             |
      | Trace | $arg1: string       |
      | Trace | $arg2: null\|string |
      | Trace | $arg3: null\|string |
      | Trace | $arg4: null\|string |
      | Trace | $arg5: string       |
      | Trace | $arg6: null\|string |
      | Trace | $arg7: null\|string |
    And I see no other errors

  Scenario Outline: Asserting array arguments return types have inferred
    Given I have the following code
      """
      class MyCommand extends Command
      {
        public function configure(): void
        {
          $this
            <arg>
          ;
        }

        public function execute(InputInterface $input, OutputInterface $output): int
        {
          /** @psalm-trace $arg1 */
          $arg1 = $input->getArgument('arg1');

          return 0;
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message                   |
      | Trace | $arg1: array<int, string> |
    And I see no other errors
    Examples:
      | arg                                                                       |
      | ->addArgument('arg1', InputArgument::IS_ARRAY)                            |
      | ->addArgument('arg1', InputArgument::IS_ARRAY \| InputArgument::REQUIRED) |
      | ->addArgument('arg1', InputArgument::IS_ARRAY \| InputArgument::OPTIONAL) |
