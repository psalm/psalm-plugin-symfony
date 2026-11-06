@symfony-common @php-8
Feature: Router

  Scenario: Controller action from default routes cache path is marked as used
    Given I have Symfony plugin enabled
    And I have the following file "var/cache/dev/url_generating_routes.php"
      """
      <?php
      return [
        'cached_route' => [
          [],
          ['_controller' => 'App\\Controller\\CachedController::fromCache'],
        ],
        'dummy_redirect' => [[], ['path' => 'http://example.com', 'permanent' => true, '_controller' => ['Symfony\\Bundle\\FrameworkBundle\\Controller\\RedirectController', 'dummy_redirect']], [], [['text', '/redirect']], [], [], []],
      ];
      """
    And I have the following code
      """
      <?php
      namespace App\Controller;

      final class CachedController
      {
        public function fromCache(): void
        {
        }
      }
      """
    When I run Psalm with dead code detection
    Then I see no errors

  Scenario: Controllers from the routes cache next to the container are marked as used
    Given I have the following code in "container.xml"
      """
      <?xml version="1.0" encoding="utf-8"?>
      <container xmlns="http://symfony.com/schema/dic/services" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://symfony.com/schema/dic/services https://symfony.com/schema/dic/services/services-1.0.xsd">
        <services>
          <service id="app.service_controller" class="App\Controller\ServiceController" public="true"/>
        </services>
      </container>
      """
    And I have the following code in "url_generating_routes.php"
      """
      <?php
      return [
        'by_service' => [[], ['_controller' => 'app.service_controller::show'], [], [], [], [], []],
        'by_class' => [[], ['_controller' => 'App\\Controller\\ClassController::show'], [], [], [], [], []],
        'invokable' => [[], ['_controller' => 'App\\Controller\\InvokableController'], [], [], [], [], []],
        'as_array' => [[], ['_controller' => ['App\\Controller\\ArrayController', 'show']], [], [], [], [], []],
      ];
      """
    And I have Symfony plugin enabled with the following config
      """
      <containerXml>container.xml</containerXml>
      """
    And I have the following code
      """
      <?php
      namespace App\Controller;

      final class ServiceController
      {
        public function show(): void {}
        public function unused(): void {}
      }

      final class ClassController
      {
        public function __construct() {}
        public function show(): void {}
      }

      final class InvokableController
      {
        public function __invoke(): void {}
      }

      final class ArrayController
      {
        public function show(): void {}
      }
      """
    When I run Psalm with dead code detection
    Then I see these errors
      | Type                 | Message                                                                    |
      | PossiblyUnusedMethod | Cannot find any calls to method App\Controller\ServiceController::unused   |
    And I see no other errors

  Scenario: Route attributes mark the routed methods as used
    Given I have Symfony plugin enabled
    And I have the following code
      """
      <?php
      namespace App\Controller;

      use Symfony\Component\Routing\Attribute\Route;

      final class MethodRoutes
      {
        #[Route('/a')]
        public function a(): void {}

        public function unused(): void {}
      }

      // a class route without method routes routes __invoke
      #[Route('/invokable')]
      final class InvokableRoute
      {
        public function __invoke(): void {}
      }

      #[\Symfony\Component\HttpKernel\Attribute\AsController]
      final class AsControllerInvokable
      {
        public function __invoke(): void {}
      }
      """
    When I run Psalm with dead code detection
    Then I see these errors
      | Type                 | Message                                                                 |
      | PossiblyUnusedMethod | Cannot find any calls to method App\Controller\MethodRoutes::unused     |
    And I see no other errors
