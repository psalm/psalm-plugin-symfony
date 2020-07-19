@symfony-4 @symfony-5
Feature: AbstractController

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
      </psalm>
      """

  Scenario: PropertyNotSetInConstructor error about $container is not raised
    Given I have the following code
      """
      <?php

      use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

      class MyController extends AbstractController
      {
          public function __construct() {}
      }
      """
    When I run Psalm
    Then I see no errors
