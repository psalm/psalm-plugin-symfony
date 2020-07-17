@symfony-common
Feature: Tainting

  Background:
    Given I have the following config
      """
      <?xml version="1.0"?>
      <psalm errorLevel="1">
        <projectFiles>
          <directory name="."/>
          <ignoreFiles> <directory name="../../vendor"/> </ignoreFiles>
        </projectFiles>
        <plugins>
          <pluginClass class="Psalm\SymfonyPsalmPlugin\Plugin"/>
        </plugins>
      </psalm>
      """
    And I have the following code preamble
      """
      <?php

      use Symfony\Component\HttpFoundation\Request;
      use Symfony\Component\HttpFoundation\Response;
      """

  Scenario Outline: One parameter of the Request's request/query/cookies is printed in the body of a Response object
    Given I have the "symfony/framework-bundle" package satisfying the "^5.1"
    And I have the following code
      """
      class MyController
      {
        public function __invoke(Request $request): Response
        {
          return new Response($request<property>->get('untrusted'));
        }
      }
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type         | Message               |
      | TaintedInput | Detected tainted html |
    And I see no other errors
    Examples:
      | property  |
      |           |
      | ->request |
      | ->query   |
      | ->cookies |

  Scenario Outline: All parameters of the Request's request/query/cookies are exported in the body of a Response object
    Given I have the "symfony/framework-bundle" package satisfying the "^5.1"
    And I have the following code
      """
      class MyController
      {
        public function __invoke(Request $request): Response
        {
          return new Response(var_export($request-><property>->all(), true));
        }
      }
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type         | Message               |
      | TaintedInput | Detected tainted html |
    And I see no other errors
    Examples:
      | property |
      | request  |
      | query    |
      | cookies  |

  Scenario: The user-agent is used in the body of a Response object
    Given I have the following code
      """
      class MyController
      {
        public function __invoke(Request $request): Response
        {
          return new Response($request->headers->get('user-agent'));
        }
      }
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type         | Message               |
      | TaintedInput | Detected tainted html |
    And I see no other errors

  Scenario: All headers are printed in the body of a Response object
    Given I have the following code
      """
      class MyController
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
      | TaintedInput | Detected tainted html |
    And I see no other errors

  Scenario: One parameter of the Request is used in a twig template without escaping
    Given I have the following code
      """
      use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

      class MyController extends AbstractController
      {
        public function __invoke(Request $request): Response
        {
          return $this->render('index.html.twig', [
            'untrusted' => $request->get('untrusted')
          ]);
        }
      }
      """
    And I have the following "index.html.twig" template
      """
      <h1>
        {{ untrusted|raw }}
      </h1>
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type         | Message                 |
      | TaintedInput | Detected tainted header |
    And I see no other errors
