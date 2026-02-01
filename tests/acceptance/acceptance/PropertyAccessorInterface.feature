@symfony-7
Feature: PropertyAccessorInterface

  Background:
    Given I have issue handlers "UnusedVariable,UnusedFunctionCall" suppressed
    And I have Symfony plugin enabled
    And I have the following code preamble
      """
      <?php

      use Symfony\Component\PropertyAccess\PropertyAccess;
      use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

      $propertyAccessor = PropertyAccess::createPropertyAccessor();
      """

  Scenario: Set value keeps array type if array is passed
    Given I have the following code
      """
      $company = ['name' => 'Acme'];

      $propertyAccessor->setValue($company, 'name', 'Acme v2');

      /** @psalm-trace $company */
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message         |
      | Trace | $company: array |
    And I see no other errors

  Scenario: Set value keeps object instance if an object is passed
    Given I have the following code
      """
      final class Company
      {
          public string $name = 'Acme';
      }
      $company = new Company();

      $propertyAccessor->setValue($company, 'name', 'Acme v2');

      /** @psalm-trace $company */
      """
    When I run Psalm
    Then I see these errors
      | Type  | Message           |
      | Trace | $company: Company |
    And I see no other errors

  Scenario: Set value does not modify the propertyAccessor variable
    Given I have the following code
      """
      final class Company
      {
          public function __construct(
            private PropertyAccessorInterface $propertyAccessor,
          ) {
          }

          public function doThings(Company $thing): void
          {
              $this->propertyAccessor->setValue($thing, 'foo', 'bar');
              $this->propertyAccessor->setValue($thing, 'foo', 'bar');
          }
      }
      """
    When I run Psalm
    Then I see no errors
