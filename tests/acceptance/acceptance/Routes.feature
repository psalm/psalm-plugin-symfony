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
