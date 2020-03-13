Feature: ContainerDependency
  In order to follow best practices for Symfony
  As a Psalm user
  I need Psalm to check container is not injected as a dependency

  Background:
    Given I have the following config
      """
      <?xml version="1.0"?>
      <psalm totallyTyped="true">
        <projectFiles>
          <directory name="."/>
          <ignoreFiles> <directory name="../../vendor"/> </ignoreFiles>
        </projectFiles>
        <plugins>
          <pluginClass class="Psalm\SymfonyPsalmPlugin\Plugin"/>
        </plugins>
      </psalm>
      """

  Scenario: Asserting container dependency raises issue
    Given I have the following code
      """
      <?php
      use Symfony\Component\DependencyInjection\ContainerInterface;
      class SomeService
      {
        public function __construct(ContainerInterface $container)
        {
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type                 | Message                                                   |
      | ContainerDependency | Container must not inject into services as dependency! |
    And I see no other errors
