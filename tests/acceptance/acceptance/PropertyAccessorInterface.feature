@symfony-common
Feature: PropertyAccessorInterface

  Background:
    Given I have issue handlers "UnusedVariable,UnusedFunctionCall" suppressed
    And I have Symfony plugin enabled
    And I have the following code preamble
      """
      <?php

      use Symfony\Component\PropertyAccess\PropertyAccess;

      $propertyAccessor = PropertyAccess::createPropertyAccessor();
      """

  Scenario: Set value keeps array type if array is passed
    Given I have the following code
      """
      $company = ['name' => 'Acme'];

      $propertyAccessor->setValue($company, 'name', 'Acme v2');

      array_key_exists('name', $company);
      """
    When I run Psalm
    Then I see no errors

  Scenario: Set value keeps object instance if an object is passed
    Given I have the following code
      """
      class Company
      {
          public string $name = 'Acme';
      }
      $company = new Company();

      $propertyAccessor->setValue($company, 'name', 'Acme v2');

      echo $company->name;
      """
    When I run Psalm
    Then I see no errors

  Scenario: Set value does not modify the propertyAccessor variable
    Given I have the following code
      """
      use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

      class Company
      {
          /**
           * @var PropertyAccessorInterface
           */
          private $propertyAccessor;

          public function __construct(PropertyAccessorInterface $propertyAccessor) {
              $this->propertyAccessor = $propertyAccessor;
          }

          public function doThings(Company $thing): void {
              $this->propertyAccessor->setValue($thing, 'foo', 'bar');
              $this->propertyAccessor->setValue($thing, 'foo', 'bar');
          }
      }
      """
    When I run Psalm
    Then I see no errors
