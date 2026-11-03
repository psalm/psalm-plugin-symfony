@symfony-8
Feature: Required property with asymmetric visibility

  Background:
    Given I have Symfony plugin enabled with the following config
      """
      <containerXml>../../tests/acceptance/container.xml</containerXml>
      """

  # PHP accepts "public(set) readonly", which Symfony can inject, but Psalm still reports it as a ParseError.
  Scenario: PropertyNotSetInConstructor error is not raised for a public(set) readonly property with the required attribute.
    Given I have the following code
      """
      <?php

      use Symfony\Contracts\Service\Attribute\Required;

      final class MyServiceA {
      }

      final class MyServiceB {
          #[Required]
          public public(set) readonly MyServiceA $a;

          public function __construct() {}
      }
      """
    When I run Psalm
    Then I see these errors
      | Type       | Message                                                       |
      | ParseError | Readonly property MyServiceB::$a cannot have public(set) visibility |
    And I see no other errors

  Scenario: PropertyNotSetInConstructor error is raised for a private(set) property with the required attribute, Symfony cannot inject it.
    Given I have the following code
      """
      <?php

      use Symfony\Contracts\Service\Attribute\Required;

      final class MyServiceA {
      }

      final class MyServiceB {
          #[Required]
          public private(set) MyServiceA $a;

          public function __construct() {}
      }
      """
    When I run Psalm
    Then I see these errors
      | Type                        | Message                                                                                                          |
      | PropertyNotSetInConstructor | Property MyServiceB::$a is not defined in constructor of MyServiceB or in any methods called in the constructor |
    And I see no other errors
