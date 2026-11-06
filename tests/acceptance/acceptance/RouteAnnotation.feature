@symfony-6 @symfony-7
Feature: Route annotation class
  Symfony\Component\Routing\Annotation\Route, removed in Symfony 8.0, is still a route attribute before

  Scenario: Route annotation attributes mark the routed methods as used
    # deprecated in Symfony 7.4
    Given I have issue handlers "DeprecatedClass" suppressed
    And I have Symfony plugin enabled
    And I have the following code
      """
      <?php
      namespace App\Controller;

      use Symfony\Component\Routing\Annotation\Route;

      final class MethodRoutes
      {
        #[Route('/a')]
        public function a(): void {}

        public function unused(): void {}
      }

      #[Route('/invokable')]
      final class InvokableRoute
      {
        public function __invoke(): void {}
      }
      """
    When I run Psalm with dead code detection
    Then I see these errors
      | Type                 | Message                                                             |
      | PossiblyUnusedMethod | Cannot find any calls to method App\Controller\MethodRoutes::unused |
    And I see no other errors
