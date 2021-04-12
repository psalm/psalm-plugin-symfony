@symfony-common
Feature: Annotation class

  Background:
    Given I have Symfony plugin enabled with the following config
      """
      <containerXml>../../tests/acceptance/container.xml</containerXml>
      """

  Scenario: PropertyNotSetInConstructor error is not raised when the @required annotation is present.
    Given I have the following code
      """
      <?php
      final class MyServiceA {
      }

      final class MyServiceB {
          private MyServiceA $a;
          public function __construct(){}

          /** @required */
          private function setMyServiceA(MyServiceA $a): void { $this->a = $a; }
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: PropertyNotSetInConstructor error is raised when the @required annotation is not present.
    Given I have the following code
      """
      <?php
      final class MyServiceA {
      }

      final class MyServiceB {
          private MyServiceA $a;
          public function __construct(){}

          private function setMyServiceA(MyServiceA $a): void { $this->a = $a; }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type                        | Message                                                                                                                           |
      | PropertyNotSetInConstructor | Property MyServiceB::$a is not defined in constructor of MyServiceB and in any private or final methods called in the constructor |
    And I see no other errors
