@symfony-6 @symfony-7 @symfony-8
Feature: StreamedJsonResponse header tainting

  Background:
    Given I have issue handlers "UnusedVariable" suppressed
    And I have Symfony plugin enabled
    And I have the following code preamble
      """
      <?php

      use Symfony\Component\HttpFoundation\Request;
      use Symfony\Component\HttpFoundation\StreamedJsonResponse;
      """

  Scenario: The client chooses a header through the constructor of a StreamedJsonResponse
    Given I have the following code
      """
      final class ExportController
      {
        public function __invoke(Request $request): StreamedJsonResponse
        {
          return new StreamedJsonResponse(['items' => []], 200, ['Content-Type' => (string) $request->query->get('type')]);
        }
      }
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type          | Message                 |
      | TaintedHeader | Detected tainted header |
    And I see no other errors
