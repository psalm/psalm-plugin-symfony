@symfony-common
Feature: Redirects, the session and cookies

  Background:
    Given I have issue handlers "UnusedVariable" suppressed
    And I have Symfony plugin enabled
    And I have the following code preamble
      """
      <?php

      use Symfony\Component\HttpFoundation\Cookie;
      use Symfony\Component\HttpFoundation\RedirectResponse;
      use Symfony\Component\HttpFoundation\Request;
      """

  Scenario: The client chooses where a RedirectResponse sends it
    Given I have the following code
      """
      final class MyController
      {
        public function __invoke(Request $request): RedirectResponse
        {
          return new RedirectResponse((string) $request->query->get('next'));
        }
      }
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type            | Message                          |
      | TaintedRedirect | Detected tainted redirect target |
    And I see no other errors

  Scenario: Input is stored in the session
    Given I have the following code
      """
      final class MyController
      {
        public function __invoke(Request $request): void
        {
          $request->getSession()->set('role', (string) $request->query->get('role'));
        }
      }
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type           | Message                       |
      | TaintedSession | Detected tainted session data |
    And I see no other errors

  Scenario Outline: A cookie is made with the secure flag off
    Given I have the following code
      """
      function remember(string $token): Cookie
      {
        return <code>;
      }
      """
    When I run Psalm
    Then I see these errors
      | Type           | Message                                                                         |
      | InsecureCookie | The cookie is made without the secure flag, so it is also sent over plain HTTP |
    And I see no other errors
    Examples:
      | code                                                                      |
      | Cookie::create('session', $token, 0, '/', null, false, true, false, 'lax') |
      | new Cookie('session', $token, 0, '/', null, false)                        |
      | Cookie::create('session', $token)->withSecure(false)                      |

  Scenario Outline: A cookie is made with the secure flag on, or set for the requests made over HTTPS
    Given I have the following code
      """
      function remember(string $token): Cookie
      {
        return <code>;
      }
      """
    When I run Psalm
    Then I see no errors
    Examples:
      | code                                                   |
      | Cookie::create('session', $token, 0, '/', null, true)  |
      | Cookie::create('session', $token)                      |
      | new Cookie('session', $token, 0, '/', null, null)      |
