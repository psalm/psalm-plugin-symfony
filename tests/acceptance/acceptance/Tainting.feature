@symfony-5 @symfony-6
Feature: Tainting

  Background:
    # Request::get() is internal since Symfony 6.4
    Given I have issue handlers "UnusedVariable,InternalMethod" suppressed
    And I have Symfony plugin enabled
    And I have the following code preamble
      """
      <?php

      use Symfony\Component\HttpFoundation\Request;
      use Symfony\Component\HttpFoundation\Response;
      """

  Scenario Outline: One parameter of the Request's request/query/cookies is printed in the body of a Response object
    And I have the following code
      """
      final class MyController
      {
        public function __invoke(Request $request): Response
        {
          return new Response((string) $request<property>->get('untrusted'));
        }
      }
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type         | Message               |
      | TaintedHtml  | Detected tainted HTML |
    And I see no other errors
    Examples:
      | property  |
      |           |
      | ->request |
      | ->query   |
      | ->cookies |

  Scenario Outline: All parameters of the Request's request/query/cookies are exported in the body of a Response object
    And I have the following code
      """
      final class MyController
      {
        public function __invoke(Request $request): Response
        {
          return new Response(var_export($request-><property>->all(), true));
        }
      }
      """
    When I run Psalm with taint analysis
    # some betas of Psalm 7 report the flow once, others twice
    Then I see these errors
      | Type         | Message               |
      | TaintedHtml  | Detected tainted HTML |
    Examples:
      | property |
      | request  |
      | query    |
      | cookies  |

#  todo: "@psalm-taint-source input" does not work on get() method
#  Scenario: The user-agent is used in the body of a Response object
#    Given I have the following code
#      """
#      final class MyController
#      {
#        public function __invoke(Request $request): Response
#        {
#          return new Response($request->headers->get('user-agent'));
#        }
#      }
#      """
#    When I run Psalm with taint analysis
#    Then I see these errors
#      | Type         | Message               |
#      | TaintedHtml  | Detected tainted HTML |
#    And I see no other errors

  Scenario: All headers are printed in the body of a Response object
    Given I have the following code
      """
      final class MyController
      {
        public function __invoke(Request $request): Response
        {
          return new Response((string) $request->headers);
        }
      }
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type         | Message               |
      | TaintedHtml  | Detected tainted HTML |
    And I see no other errors
