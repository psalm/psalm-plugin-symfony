@symfony-common
Feature: Response header tainting

  Background:
    Given I have issue handlers "UnusedVariable" suppressed
    And I have Symfony plugin enabled
    And I have the following code preamble
      """
      <?php

      use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
      use Symfony\Component\HttpFoundation\BinaryFileResponse;
      use Symfony\Component\HttpFoundation\HeaderBag;
      use Symfony\Component\HttpFoundation\JsonResponse;
      use Symfony\Component\HttpFoundation\RedirectResponse;
      use Symfony\Component\HttpFoundation\Request;
      use Symfony\Component\HttpFoundation\Response;
      use Symfony\Component\HttpFoundation\ResponseHeaderBag;
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
      | JsonResponse::fromJsonString('[]', 200, $headers)                  |
      | new StreamedResponse(null, 200, $headers)                          |
      | new BinaryFileResponse('/var/exports/report.pdf', 200, $headers)   |

  Scenario: The client chooses a header through the json() shortcut of a controller
    Given I have the following code
      """
      final class ExportController extends AbstractController
      {
        public function __invoke(Request $request): Response
        {
          return $this->json([], 200, ['Content-Type' => (string) $request->query->get('type')]);
        }
      }
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type          | Message                 |
      | TaintedHeader | Detected tainted header |
    And I see no other errors

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

  Scenario Outline: The client chooses a header written to a bag extending that of the response headers
    Given I have the following code
      """
      final class CorsHeaderBag extends ResponseHeaderBag {}

      final class CorsController
      {
        public function __invoke(Request $request, CorsHeaderBag $headers): void
        {
          $headers-><call>;
        }
      }
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type          | Message                 |
      | TaintedHeader | Detected tainted header |
    And I see no other errors
    Examples:
      | call                                                                              |
      | set('Access-Control-Allow-Origin', (string) $request->query->get('origin'))       |
      | add(['Access-Control-Allow-Origin' => (string) $request->query->get('origin')])   |
      | replace(['Access-Control-Allow-Origin' => (string) $request->query->get('origin')]) |

  Scenario Outline: The client chooses where a Location header set on the response sends it
    Given I have the following code
      """
      final class LoginController
      {
        public function __invoke(Request $request): Response
        {
          $response = new Response('', 302);
          $response->headers->set('<name>', (string) $request->query->get('next'));

          return $response;
        }
      }
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type            | Message                          |
      | TaintedRedirect | Detected tainted redirect target |
    And I see no other errors
    Examples:
      | name     |
      | Location |
      | location |

  Scenario: A Location header without a line break still sends the client where it chooses
    Given I have the following code
      """
      final class LoginController
      {
        public function __invoke(Request $request): Response
        {
          $response = new Response('', 302);
          $next = (string) $request->query->get('next');

          if (1 === preg_match('#^[a-z0-9:/.-]+$#D', $next)) {
            $response->headers->set('Location', $next);
          }

          return $response;
        }
      }
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type            | Message                          |
      | TaintedRedirect | Detected tainted redirect target |
    And I see no other errors

  Scenario: A Location header to a fixed path with an encoded query value is not reported
    Given I have the following code
      """
      final class AccountController
      {
        public function __invoke(Request $request): Response
        {
          $response = new Response('', 302);
          $response->headers->set('Location', '/account?tab=' . rawurlencode((string) $request->query->get('tab')));

          return $response;
        }
      }
      """
    When I run Psalm with taint analysis
    Then I see no errors

  Scenario Outline: A header written to the request is not sent to the client
    Given I have the following code
      """
      final class TokenListener
      {
        public function __invoke(Request $request): void
        {
          $request->headers-><call>;
        }
      }
      """
    When I run Psalm with taint analysis
    Then I see no errors
    Examples:
      | call                                                                            |
      | set('Authorization', 'Bearer ' . (string) $request->query->get('token'))        |
      | add(['Authorization' => 'Bearer ' . (string) $request->query->get('token')])    |
      | replace(['Authorization' => 'Bearer ' . (string) $request->query->get('token')]) |

  Scenario: A header written to a bag that is not known to be that of a response is not reported
    Given I have the following code
      """
      final class TokenListener
      {
        public function __invoke(Request $request, HeaderBag $headers): void
        {
          $headers->set('Authorization', 'Bearer ' . (string) $request->query->get('token'));
        }
      }
      """
    When I run Psalm with taint analysis
    Then I see no errors

  Scenario: Headers the server chooses are not reported
    Given I have the following code
      """
      final class ExportController
      {
        public function __invoke(Request $request): Response
        {
          $response = new Response('', 200, ['Content-Type' => 'text/csv']);
          $response->headers->set('Content-Language', 'en');
          $response->headers->set('Location', '/exports');
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
