@symfony-common
Feature: Doctrine QueryBuilder

  Background:
    Given I have the following config
      """
      <?xml version="1.0"?>
      <psalm>
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

      use Doctrine\ORM\QueryBuilder;

      /**
       * @psalm-info InvalidReturnType
       * @return QueryBuilder
       */
      function qb() {}
      """

  Scenario: No complaint about explicit type for non-objects
    Given I have the following code
      """
      $qb = qb();
      $qb->setParameter('string', 'string');
      $qb->setParameter('integer', 1);
      $qb->setParameter('bool', true);

      $string = 'string';
      $int = 1;
      $bool = false;
      $qb->setParameter('string', $string);
      $qb->setParameter('integer', $int);
      $qb->setParameter('bool', $bool);
      """
    When I run Psalm
    Then I see no errors

  Scenario: Complaint about not setting explicit type for objects
    Given I have the following code
      """
      $qb = qb();

      $qb->setParameter('date', new \DateTimeImmutable());

      $date = new \DateTimeImmutable();
      $qb->setParameter('date', $date);

      $qb->setParameter('qb', $qb);
      """
    When I run Psalm
    Then I see these errors
      | Type                       | Message                                                |
      | QueryBuilderSetParameter   | To improve performance set explicit type for objects   |
      | QueryBuilderSetParameter   | To improve performance set explicit type for objects   |
      | QueryBuilderSetParameter   | To improve performance set explicit type for objects   |
    And I see no other errors
