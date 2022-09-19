@symfony-common
Feature: RequiredAttribute

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
          <pluginClass class="Psalm\SymfonyPsalmPlugin\Plugin">
            <containerXml>../../tests/acceptance/container.xml</containerXml>
          </pluginClass>
        </plugins>
      </psalm>
      """

  Scenario: PropertyNotSetInConstructor error is not raised when the @required annotation is present.
    Given I have the following code
      """
      <?php

      class MyServiceA {
          /**
           * @required
           * @var string
           */
          public $a;
          public function __construct(){}
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: PropertyNotSetInConstructor error is raised when the @required annotation is not present.
    Given I have the following code
      """
      <?php

      class MyServiceC {
          /**
           * @var string
           */
          public $a;
          public function __construct(){}

      }
      """
    When I run Psalm
    Then I see these errors
      | Type                        | Message                                                                                                          |
      | PropertyNotSetInConstructor | Property MyServiceC::$a is not defined in constructor of MyServiceC and in any methods called in the constructor |
    And I see no other errors
