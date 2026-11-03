@symfony-6 @symfony-7
Feature: AbstractController

  Background:
    Given I have Symfony plugin enabled

  Scenario: PropertyNotSetInConstructor error about $container is not raised
    Given I have the following code
      """
      <?php

      use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

      final class MyController extends AbstractController
      {
          public function __construct() {}
      }
      """
    When I run Psalm
    Then I see no errors
