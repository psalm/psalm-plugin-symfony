@symfony-common
Feature: Response header tainting

  Background:
    Given I have issue handlers "UnusedVariable" suppressed
    And I have Symfony plugin enabled
    And I have the following code preamble
      """
      <?php

      use Symfony\Component\HttpFoundation\BinaryFileResponse;
      use Symfony\Component\HttpFoundation\JsonResponse;
      use Symfony\Component\HttpFoundation\RedirectResponse;
      use Symfony\Component\HttpFoundation\Request;
      use Symfony\Component\HttpFoundation\Response;
      use Symfony\Component\HttpFoundation\StreamedResponse;
      """

  Scenario Outline: The client chooses a header through the constructor of a response
    Given I have the following code
      """
      final class ExportController
      {
        public function __invoke(Request $request): Response
        {
          $headers = ['Content-Type' => (string) $request->query->get('type')];

          return <response>;
        }
      }
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type          | Message                 |
      | TaintedHeader | Detected tainted header |
    And I see no other errors
    Examples:
      | response                                                           |
      | new Response('', 200, $headers)                                    |
      | new RedirectResponse('/dashboard', 302, $headers)                  |
      | new JsonResponse([], 200, $headers)                                |
      | new StreamedResponse(null, 200, $headers)                          |
      | new BinaryFileResponse('/var/exports/report.pdf', 200, $headers)   |

  Scenario: The client chooses the value of a header set on the response
    Given I have the following code
      """
      final class CorsController
      {
        public function __invoke(Request $request): Response
        {
          $response = new Response('');
          $response->headers->set('Access-Control-Allow-Origin', (string) $request->query->get('origin'));

          return $response;
        }
      }
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type          | Message                 |
      | TaintedHeader | Detected tainted header |
    And I see no other errors

  Scenario: The client chooses the name of a header set on the response
    Given I have the following code
      """
      final class CorsController
      {
        public function __invoke(Request $request): Response
        {
          $response = new Response('');
          $response->headers->set((string) $request->query->get('header'), 'yes');

          return $response;
        }
      }
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type          | Message                 |
      | TaintedHeader | Detected tainted header |
    And I see no other errors

  Scenario Outline: The client chooses headers added to or replaced on the response
    Given I have the following code
      """
      final class ExportController
      {
        public function __invoke(Request $request): Response
        {
          $response = new Response('');
          $response->headers-><call>(['Content-Type' => (string) $request->query->get('type')]);

          return $response;
        }
      }
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type          | Message                 |
      | TaintedHeader | Detected tainted header |
    And I see no other errors
    Examples:
      | call    |
      | add     |
      | replace |

  Scenario: The client chooses a header of a request that is passed on
    Given I have the following code
      """
      final class ProxyController
      {
        public function __invoke(Request $request): void
        {
          $request->headers->set('X-Forwarded-Host', (string) $request->query->get('host'));
        }
      }
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type          | Message                 |
      | TaintedHeader | Detected tainted header |
    And I see no other errors

  Scenario: Headers the server chooses are not reported
    Given I have the following code
      """
      final class ExportController
      {
        public function __invoke(Request $request): Response
        {
          $response = new Response('', 200, ['Content-Type' => 'text/csv']);
          $response->headers->set('Content-Language', 'en');
          $response->headers->add(['Cache-Control' => 'no-store']);

          return new JsonResponse([], 200, ['X-Rate-Limit' => 'none']);
        }
      }
      """
    When I run Psalm with taint analysis
    Then I see no errors

  Scenario: Input cast to an integer is not reported as a header
    Given I have the following code
      """
      final class ExportController
      {
        public function __invoke(Request $request): Response
        {
          $page = (int) $request->query->get('page');
          $response = new Response('', 200, ['X-Page' => $page]);
          $response->headers->set('X-Next-Page', (string) ($page + 1));

          return $response;
        }
      }
      """
    When I run Psalm with taint analysis
    Then I see no errors
