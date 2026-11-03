@symfony-common
Feature: Required property

  Background:
    Given I have Symfony plugin enabled with the following config
      """
      <containerXml>../../tests/acceptance/container.xml</containerXml>
      """

  Scenario: PropertyNotSetInConstructor error is not raised for a public typed property with the required attribute (with use).
    Given I have the following code
      """
      <?php

      use Symfony\Contracts\Service\Attribute\Required;

      final class MyServiceA {
      }

      final class MyServiceB {
          #[Required]
          public MyServiceA $a;

          public function __construct() {}
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: PropertyNotSetInConstructor error is not raised for a public typed property with the required attribute (without use).
    Given I have the following code
      """
      <?php

      final class MyServiceA {
      }

      final class MyServiceB {
          #[\Symfony\Contracts\Service\Attribute\Required]
          public MyServiceA $a;

          public function __construct() {}
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: PropertyNotSetInConstructor error is not raised for a nullable typed property with the required attribute.
    Given I have the following code
      """
      <?php

      use Symfony\Contracts\Service\Attribute\Required;

      final class MyServiceA {
      }

      final class MyServiceB {
          #[Required]
          public ?MyServiceA $a;

          public function __construct() {}
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: PropertyNotSetInConstructor error is not raised for a required property declared in a trait.
    Given I have the following code
      """
      <?php

      use Symfony\Contracts\Service\Attribute\Required;

      final class MyServiceA {
      }

      trait MyTrait {
          #[Required]
          public MyServiceA $a;
      }

      final class MyServiceB {
          use MyTrait;

          public function __construct() {}
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: PropertyNotSetInConstructor error is not raised for a required property declared in a parent class.
    Given I have the following code
      """
      <?php

      use Symfony\Contracts\Service\Attribute\Required;

      final class MyServiceA {
      }

      abstract class MyBaseService {
          #[Required]
          public MyServiceA $a;
      }

      final class MyServiceB extends MyBaseService {
          public function __construct() {}
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: PropertyNotSetInConstructor error is raised when the required attribute is not present.
    Given I have the following code
      """
      <?php

      final class MyServiceA {
      }

      final class MyServiceB {
          public MyServiceA $a;

          public function __construct() {}
      }
      """
    When I run Psalm
    Then I see these errors
      | Type                        | Message                                                                                                          |
      | PropertyNotSetInConstructor | Property MyServiceB::$a is not defined in constructor of MyServiceB or in any methods called in the constructor |
    And I see no other errors

  Scenario: PropertyNotSetInConstructor error is raised for a private property with the required attribute, Symfony cannot inject it.
    Given I have the following code
      """
      <?php

      use Symfony\Contracts\Service\Attribute\Required;

      final class MyServiceA {
      }

      final class MyServiceB {
          #[Required]
          private MyServiceA $a;

          public function __construct() {}
      }
      """
    When I run Psalm
    Then I see these errors
      | Type                        | Message                                                                                                                           |
      | PropertyNotSetInConstructor | Property MyServiceB::$a is not defined in constructor of MyServiceB or in any private or final methods called in the constructor |
    And I see no other errors

  Scenario: PropertyNotSetInConstructor error is raised for a protected property with the required attribute, Symfony cannot inject it.
    Given I have the following code
      """
      <?php

      use Symfony\Contracts\Service\Attribute\Required;

      final class MyServiceA {
      }

      final class MyServiceB {
          #[Required]
          protected MyServiceA $a;

          public function __construct() {}
      }
      """
    When I run Psalm
    Then I see these errors
      | Type                        | Message                                                                                                          |
      | PropertyNotSetInConstructor | Property MyServiceB::$a is not defined in constructor of MyServiceB or in any methods called in the constructor |
    And I see no other errors

  Scenario: PropertyNotSetInConstructor error is raised for an untyped property with the required attribute, Symfony skips it.
    Given I have the following code
      """
      <?php

      use Symfony\Contracts\Service\Attribute\Required;

      final class MyServiceA {
      }

      final class MyServiceB {
          /** @var MyServiceA */
          #[Required]
          public $a;

          public function __construct() {}
      }
      """
    When I run Psalm
    Then I see these errors
      | Type                        | Message                                                                                                          |
      | PropertyNotSetInConstructor | Property MyServiceB::$a is not defined in constructor of MyServiceB or in any methods called in the constructor |
    And I see no other errors

  Scenario: PropertyNotSetInConstructor error is raised for a union typed property with the required attribute, Symfony skips it.
    Given I have the following code
      """
      <?php

      use Symfony\Contracts\Service\Attribute\Required;

      final class MyServiceA {
      }

      final class MyServiceB {
          #[Required]
          public MyServiceA|\stdClass $a;

          public function __construct() {}
      }
      """
    When I run Psalm
    Then I see these errors
      | Type                        | Message                                                                                                          |
      | PropertyNotSetInConstructor | Property MyServiceB::$a is not defined in constructor of MyServiceB or in any methods called in the constructor |
    And I see no other errors

  Scenario: PropertyNotSetInConstructor error is raised for an intersection typed property with the required attribute, Symfony skips it.
    Given I have the following code
      """
      <?php

      use Symfony\Contracts\Service\Attribute\Required;

      interface MyInterfaceA {
      }

      interface MyInterfaceB {
      }

      final class MyServiceB {
          #[Required]
          public MyInterfaceA&MyInterfaceB $a;

          public function __construct() {}
      }
      """
    When I run Psalm
    Then I see these errors
      | Type                        | Message                                                                                                          |
      | PropertyNotSetInConstructor | Property MyServiceB::$a is not defined in constructor of MyServiceB or in any methods called in the constructor |
    And I see no other errors

  Scenario: Readonly property with the required attribute is not considered initialized, Symfony cannot inject it.
    Given I have the following code
      """
      <?php

      use Symfony\Contracts\Service\Attribute\Required;

      final class MyServiceA {
      }

      final class MyServiceB {
          #[Required]
          public readonly MyServiceA $a;

          public function __construct() {}
      }
      """
    When I run Psalm
    Then I see these errors
      | Type | Message |
      | PropertyNotSetInConstructor | Property MyServiceB::$a is not defined in constructor of MyServiceB or in any methods called in the constructor |
    And I see no other errors

  Scenario: PropertyNotSetInConstructor error is raised for a property of a readonly class with the required attribute.
    Given I have the following code
      """
      <?php

      use Symfony\Contracts\Service\Attribute\Required;

      final class MyServiceA {
      }

      final readonly class MyServiceB {
          #[Required]
          public MyServiceA $a;

          public function __construct() {}
      }
      """
    When I run Psalm
    Then I see these errors
      | Type                        | Message                                                                                                          |
      | PropertyNotSetInConstructor | Property MyServiceB::$a is not defined in constructor of MyServiceB or in any methods called in the constructor |
    And I see no other errors

  Scenario: PropertyNotSetInConstructor error is not raised for a property that is readonly only by the docblock tag.
    Given I have the following code
      """
      <?php

      use Symfony\Contracts\Service\Attribute\Required;

      final class MyServiceA {
      }

      final class MyServiceB {
          /** @readonly */
          #[Required]
          public MyServiceA $a;

          public function __construct() {}
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: PropertyNotSetInConstructor error is not raised for an inherited promoted property when the child constructor skips the parent one.
    Given I have the following code
      """
      <?php

      use Symfony\Contracts\Service\Attribute\Required;

      final class MyServiceA {
      }

      abstract class MyBaseService {
          public function __construct(
              #[Required]
              public MyServiceA $a,
          ) {}
      }

      final class MyServiceB extends MyBaseService {
          public function __construct() {}
      }
      """
    When I run Psalm
    Then I see no errors
