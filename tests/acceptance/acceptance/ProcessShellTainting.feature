@symfony-common
Feature: Process shell tainting

  Background:
    Given I have issue handlers "UnusedVariable" suppressed
    And I have Symfony plugin enabled
    And I have the following code preamble
      """
      <?php

      use Symfony\Component\HttpFoundation\Request;
      use Symfony\Component\Process\Process;
      """

  Scenario: The client chooses the shell command line
    Given I have the following code
      """
      final class ArchiveController
      {
        public function __invoke(Request $request): void
        {
          Process::fromShellCommandline('tar -czf backup.tgz '.(string) $request->query->get('directory'))->mustRun();
        }
      }
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type         | Message                     |
      | TaintedShell | Detected tainted shell code   |
    And I see no other errors

  Scenario: A subclass inherits the shell command line sink
    Given I have the following code
      """
      final class ArchiveProcess extends Process
      {
      }

      final class ArchiveController
      {
        public function __invoke(Request $request): void
        {
          ArchiveProcess::fromShellCommandline('tar -czf backup.tgz '.(string) $request->query->get('directory'))->mustRun();
        }
      }
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type         | Message                     |
      | TaintedShell | Detected tainted shell code   |
    And I see no other errors

  Scenario: The client input is escaped before it joins the shell command line
    Given I have the following code
      """
      final class ArchiveController
      {
        public function __invoke(Request $request): void
        {
          Process::fromShellCommandline('tar -czf backup.tgz '.escapeshellarg((string) $request->query->get('directory')))->mustRun();
        }
      }
      """
    When I run Psalm with taint analysis
    Then I see no errors

  Scenario: The shell command line is a literal
    Given I have the following code
      """
      final class ArchiveController
      {
        public function __invoke(): void
        {
          Process::fromShellCommandline('tar -czf backup.tgz uploads')->mustRun();
        }
      }
      """
    When I run Psalm with taint analysis
    Then I see no errors

  Scenario: The client input reaches the shell only through a placeholder, which Process escapes
    Given I have the following code
      """
      final class ArchiveController
      {
        public function __invoke(Request $request): void
        {
          Process::fromShellCommandline('tar -czf backup.tgz "${:DIRECTORY}"')
            ->mustRun(null, ['DIRECTORY' => (string) $request->query->get('directory')]);
        }
      }
      """
    When I run Psalm with taint analysis
    Then I see no errors

  Scenario: The client input is an argument of the array form, which never reaches a shell unescaped
    Given I have the following code
      """
      final class ArchiveController
      {
        public function __invoke(Request $request): void
        {
          (new Process(['tar', '-czf', 'backup.tgz', (string) $request->query->get('directory')]))->mustRun();
        }
      }
      """
    When I run Psalm with taint analysis
    Then I see no errors

  Scenario: A process is still an iterator of its output
    Given I have the following code
      """
      /** @param \IteratorAggregate<string, string> $output */
      function printOutput(\IteratorAggregate $output): void
      {
      }

      printOutput(Process::fromShellCommandline('ls'));
      """
    When I run Psalm
    Then I see no errors
