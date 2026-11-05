@symfony-common @php-8
Feature: AsController dynamic callers
  Mark invokable controllers as used

  Scenario: AsController invokable class is marked as used
    Given I have Symfony plugin enabled
    And I have the following code
      """
      <?php
      namespace App\Controller;

      use Symfony\Component\HttpKernel\Attribute\AsController;

      #[AsController]
      final class InvokableController
      {
        public function __invoke(): void
        {
        }
      }
      """
    When I run Psalm with dead code detection
    Then I see no errors
