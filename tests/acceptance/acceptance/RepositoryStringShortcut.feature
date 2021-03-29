@symfony-common
Feature: RepositoryStringShortcut
  In order to follow best practices for Symfony
  As a Psalm user
  I need Psalm to check preferred repository syntax

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
          <UndefinedClass errorLevel="info" />
        </issueHandlers>
      </psalm>
      """
    And I have the following code preamble
      """
      <?php
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

  Scenario: Asserting using 'AppBundle:Entity' syntax raises issue
    Given I have the following code
      """
      use Doctrine\ORM\EntityManagerInterface;
      class SomeService
      {
        public function __construct(EntityManagerInterface $entityManager)
        {
          $entityManager->getRepository('AppBundle:Entity');
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type                       | Message                            |
      | RepositoryStringShortcut  | Use Entity::class syntax instead |
    And I see no other errors

  Scenario: Asserting using 'Entity::class' notation does not raise issue
    Given I have the following code
      """
      class SomeService
      {
        public function __construct(EntityManagerInterface $entityManager)
        {
          $entityManager->getRepository(Entity::class);
        }
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: Dynamic repository calls should not be complained
    Given I have the following code
      """
      class SomeService
      {
        public function __construct(EntityManagerInterface $entityManager)
        {
          $className = 'App\Entity\EntityA';
          $entityManager->getRepository($className);
        }
      }
      """
    When I run Psalm
    Then I see no errors
