@symfony-5 @symfony-6
Feature: Request getSessions
  Symfony Request getSession method is returning a Session

  Background:
    Given I have Symfony plugin enabled
    And I have the following code preamble
      """
      <?php
      use Symfony\Component\HttpFoundation\Request;
      """

  Scenario: Asserting '$request->getSession()' has a Flashbag
    Given I have the following code
      """
      class App
      {
        public function index(Request $request): void
        {
          $request->getSession()->getFlashBag();
        }
      }
      """
    When I run Psalm
    Then I see no errors
