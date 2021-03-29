@symfony-common
Feature: Form test

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
  Scenario: Test factory methods
    Given I have the following code
          """
      <?php

      class User {}

      use Symfony\Component\Form\FormFactoryInterface;
      use Symfony\Component\Form\Extension\Core\Type\CollectionType;

      /** @var FormFactoryInterface $factory */

      $factory->create(CollectionType::class);
      $factory->createNamed('random', CollectionType::class);
      $factory->createBuilder(CollectionType::class);
      $factory->createNamedBuilder('random', CollectionType::class);

      // fail if incorrect class-string is used
      $factory->create(\stdClass::class);

      """
    When I run Psalm
    Then I see these errors
      | Type  | Message                       |
      | InvalidArgument | Argument 1 of Symfony\Component\Form\FormFactoryInterface::create expects class-string<Symfony\Component\Form\FormTypeInterface>, stdClass::class provided |
    And I see no other errors
