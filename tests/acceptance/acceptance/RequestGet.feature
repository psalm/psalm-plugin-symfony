@symfony-5 @symfony-6 @symfony-7
Feature: Request get() before Symfony 8

  Background:
    # Request::get() is internal since Symfony 6.4 and deprecated since Symfony 7.4
    Given I have issue handlers "UnusedVariable,InternalMethod,DeprecatedMethod" suppressed
    And I have Symfony plugin enabled
    And I have the following code preamble
      """
      <?php

      use Symfony\Component\HttpFoundation\Request;
      use Symfony\Component\HttpFoundation\Response;
      """

  Scenario: A parameter read with get() is used in the body of a Response object
    Given I have the following code
      """
      final class SearchController
      {
        public function __invoke(Request $request): Response
        {
          return new Response((string) $request->get('q'));
        }
      }
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type        | Message               |
      | TaintedHtml | Detected tainted HTML |
    And I see no other errors
