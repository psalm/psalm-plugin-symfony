@symfony-common
Feature: RouteCollection

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
        <issueHandlers>
          <UnusedVariable errorLevel="info"/>
        </issueHandlers>
      </psalm>
      """
    And I have the following code preamble
      """
      <?php

      use Symfony\Component\Routing\RouterInterface;
      """

  Scenario: Assert router collection iterator return types
    Given I have the following code
      """
      class App
      {
        public function __invoke(RouterInterface $router): void
        {
          $routeCollection = $router->getRouteCollection();
          foreach ($routeCollection as $routeName => $route) {
              /** @psalm-trace $routeName */
              echo $routeName;
              /** @psalm-trace $route */
          }
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message                                 |
      | Trace | $routeName: string                      |
      | Trace | $route: Symfony\Component\Routing\Route |
    And I see no other errors

  Scenario: Assert RouteCollection::all() return types
    Given I have the following code
      """
      class App
      {
        public function __invoke(RouterInterface $router): void
        {
          foreach ($router->getRouteCollection()->all() as $routeName => $route) {
              /** @psalm-trace $routeName */
              echo $routeName;
              /** @psalm-trace $route */
          }
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message                                 |
      | Trace | $routeName: string                      |
      | Trace | $route: Symfony\Component\Routing\Route |
    And I see no other errors
