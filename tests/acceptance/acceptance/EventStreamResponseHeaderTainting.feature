@symfony-7 @symfony-8
Feature: EventStreamResponse header tainting

  Background:
    Given I have issue handlers "UnusedVariable" suppressed
    And I have Symfony plugin enabled
    And I have the following code preamble
      """
      <?php

      use Symfony\Component\HttpFoundation\EventStreamResponse;
      use Symfony\Component\HttpFoundation\Request;
      """

  Scenario: The client chooses a header through the constructor of an EventStreamResponse
    Given I have the following code
      """
      final class NotificationController
      {
        public function __invoke(Request $request): EventStreamResponse
        {
          return new EventStreamResponse(null, 200, ['X-Channel' => (string) $request->query->get('channel')]);
        }
      }
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type          | Message                 |
      | TaintedHeader | Detected tainted header |
    And I see no other errors
