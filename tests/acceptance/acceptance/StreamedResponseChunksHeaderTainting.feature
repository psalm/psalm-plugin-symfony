@symfony-7 @symfony-8
Feature: StreamedResponse header tainting, with the callback or the chunks of Symfony 7.3 and later

  Background:
    Given I have issue handlers "UnusedVariable" suppressed
    And I have Symfony plugin enabled
    And I have the following code preamble
      """
      <?php

      use Symfony\Component\HttpFoundation\Request;
      use Symfony\Component\HttpFoundation\StreamedResponse;
      """

  Scenario: The client chooses a header of a StreamedResponse given its chunks by name
    Given I have the following code
      """
      final class ExportController
      {
        public function __invoke(Request $request): StreamedResponse
        {
          return new StreamedResponse(
            callbackOrChunks: ['id;name', "\n", '1;Ada'],
            headers: ['Content-Type' => (string) $request->query->get('type')],
          );
        }
      }
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type          | Message                 |
      | TaintedHeader | Detected tainted header |
    And I see no other errors
