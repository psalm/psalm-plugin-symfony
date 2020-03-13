Feature: RepositoryStringShortcut
  In order to follow best practices for Symfony
  As a Psalm user
  I need Psalm to check preferred repository syntax

  Background:
    Given I have the following config
      """
      <?xml version="1.0"?>
      <psalm totallyTyped="true">
        <projectFiles>
          <directory name="."/>
          <ignoreFiles> <directory name="../../vendor"/> </ignoreFiles>
        </projectFiles>

        <issueHandlers>
          <UndefinedClass errorLevel="info" />
        </issueHandlers>

        <plugins>
          <pluginClass class="Psalm\SymfonyPsalmPlugin\Plugin"/>
        </plugins>
      </psalm>
      """

  Scenario: Asserting using 'AppBundle:Entity' syntax raises issue
    Given I have the following code
      """
      <?php
      use Doctrine\ORM\EntityManagerInterface;
      class SomeService
      {
        public function __construct(EntityManagerInterface $entityManager)
        {
          $entityManager->getRepository('AppBundle:Entity');
        }
      }

      namespace Doctrine\ORM;
      interface EntityManagerInterface
      {
        /**
         * @param string $className
         *
         * @return void
         */
        public function getRepository($className);
      }
      """
    When I run Psalm
    Then I see these errors
      | Type                       | Message                            |
      | RepositoryStringShortcut  | Use Entity::class syntax instead |
    And I see no other errors
