@symfony-common
Feature: ContainerDependency
  In order to follow best practices for Symfony
  As a Psalm user
  I need Psalm to check container is not injected as a dependency

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
      | Type                | Message                                                   |
      | ContainerDependency | Container must not inject into services as dependency! |
    And I see no other errors

  Scenario: Asserting container dependency issue can be suppressed inline
    Given I have the following code
      """
      <?php
      use Symfony\Component\DependencyInjection\ContainerInterface;
      class SomeService
      {
        /** @psalm-suppress ContainerDependency */
        public function __construct(ContainerInterface $container)
        {
        }
      }
      """
    When I run Psalm
    Then I see no errors
