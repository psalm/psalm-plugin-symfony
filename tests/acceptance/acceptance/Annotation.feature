@symfony-common
Feature: Annotation class

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
        <issueHandlers>
          <UnusedVariable errorLevel="info"/>
        </issueHandlers>
      </psalm>
      """

  Scenario: PropertyNotSetInConstructor error is not raised when class is an Annotation class.
    Given I have the following code
      """
      <?php

      /**
       * @Annotation
       */
      class Foo
      {
        /**
         * @var string
         */
        public $foo;

        public function __construct() {}
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: PropertyNotSetInConstructor error is raised when it is not an Annotation class.
    Given I have the following code
      """
      <?php

      class Foo
      {
        /**
         * @var string
         */
        public $foo;

        public function __construct() {}
      }
      """
    When I run Psalm
    Then I see these errors
      | Type                        | Message                                                                                              |
      | PropertyNotSetInConstructor | Property Foo::$foo is not defined in constructor of Foo and in any methods called in the constructor |
    And I see no other errors
