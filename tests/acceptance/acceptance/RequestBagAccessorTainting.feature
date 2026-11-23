@symfony-6 @symfony-7 @symfony-8
Feature: Tainting through the typed accessors of the request bags

  Background:
    Given I have issue handlers "UnusedVariable,ForbiddenCode" suppressed
    And I have Symfony plugin enabled
    And I have the following code preamble
      """
      <?php

      use Symfony\Component\HttpFoundation\Request;
      use Symfony\Component\HttpFoundation\Response;

      enum Status: string
      {
          case Draft = 'draft';
      }
      """

  Scenario Outline: A string read from a request bag is printed in the body of a Response object
    Given I have the following code
      """
      final class MyController
      {
        public function __invoke(Request $request): Response
        {
          return new Response(<read>);
        }
      }
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type        | Message               |
      | TaintedHtml | Detected tainted HTML |
    And I see no other errors
    Examples:
      | read                                                        |
      | $request->query->getString('untrusted')                     |
      | $request->request->getString('untrusted')                   |
      | $request->cookies->getString('untrusted')                   |
      | $request->attributes->getString('untrusted')                |
      | $request->getPayload()->getString('untrusted')              |
      | (string) $request->query->filter('untrusted')               |
      | implode(',', $request->query->keys())                       |
      | (string) $request->query->getIterator()->current()          |

  Scenario Outline: A value read with an accessor keeping only letters, digits or a known type is printed in the body of a Response object
    Given I have the following code
      """
      final class MyController
      {
        public function __invoke(Request $request): Response
        {
          return new Response(<read>);
        }
      }
      """
    When I run Psalm with taint analysis
    Then I see no errors
    Examples:
      | read                                                                 |
      | $request->query->getAlpha('untrusted')                               |
      | $request->query->getAlnum('untrusted')                               |
      | $request->query->getDigits('untrusted')                              |
      | (string) $request->query->getInt('untrusted')                        |
      | $request->query->getBoolean('untrusted') ? 'yes' : 'no'              |
      | $request->query->getEnum('untrusted', Status::class)?->value ?? ''   |

  Scenario Outline: A word read from a request bag is run as a shell command
    Given I have the following code
      """
      final class MyController
      {
        public function __invoke(Request $request): Response
        {
          return new Response((string) shell_exec($request->query-><accessor>('untrusted')));
        }
      }
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type          | Message                     |
      | TaintedShell  | Detected tainted shell code |
    And I see no other errors
    Examples:
      | accessor |
      | getAlpha |
      | getAlnum |

  Scenario: A word read from a request bag is a column of an SQL query, outside of its quotes
    Given I have the following code
      """
      final class MyController
      {
        public function __invoke(Request $request, \mysqli $db): Response
        {
          $db->query('SELECT name FROM users ORDER BY '.$request->query->getAlpha('sort'));

          return new Response();
        }
      }
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type       | Message                                |
      | TaintedSql | Detected tainted SQL outside of quotes |
    And I see no other errors

  Scenario: A word read from a request bag is quoted in an SQL query
    Given I have the following code
      """
      final class MyController
      {
        public function __invoke(Request $request, \mysqli $db): Response
        {
          $db->query("SELECT name FROM users WHERE name = '".$request->query->getAlpha('name')."'");

          return new Response();
        }
      }
      """
    When I run Psalm with taint analysis
    Then I see no errors

  Scenario Outline: A number read from a request bag is a duration
    Given I have the following code
      """
      final class MyController
      {
        public function __invoke(Request $request): Response
        {
          sleep(<read>);

          return new Response();
        }
      }
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type         | Message                |
      | TaintedSleep | Detected tainted sleep |
    And I see no other errors
    Examples:
      | read                                           |
      | $request->query->getInt('untrusted')           |
      | (int) $request->query->getDigits('untrusted')  |
      | (int) $request->query->getAlnum('untrusted')   |
