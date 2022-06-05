@symfony-common
Feature: Response

  Background:
    Given I have Symfony plugin enabled

  Scenario: MixedAssignment error about $statusTexts is not raised
    Given I have the following code
      """
      <?php

      use Symfony\Component\HttpFoundation\Response;

      $message = Response::$statusTexts[Response::HTTP_INTERNAL_SERVER_ERROR];
      """
    When I run Psalm
    Then I see no errors

