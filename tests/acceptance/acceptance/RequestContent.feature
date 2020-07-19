Feature: Request getContent
  Symfony Request has getContent method on which return type changes based on argument

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
    And I have the following code preamble
      """
      <?php
      use Symfony\Component\HttpFoundation\Request;
      """

  Scenario: Asserting '$request->getContent()' without any argument returns string
    Given I have the following code
      """
      class App
      {
        public function index(Request $request): void
        {
          json_decode($request->getContent());
        }
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: Asserting '$request->getContent(false)' returns string
    Given I have the following code
      """
      class App
      {
        public function index(Request $request): void
        {
          json_decode($request->getContent(false));
        }
      }
      """
    When I run Psalm
    Then I see no errors

  Scenario: Asserting '$request->getContent(true)' returns resource
    Given I have the following code
      """
      class App
      {
        public function index(Request $request): void
        {
          json_decode($request->getContent(true));
        }
      }
      """
    When I run Psalm
    Then I see these errors
      | Type            | Message                                                     |
      | InvalidArgument | Argument 1 of json_decode expects string, resource provided |
    And I see no other errors
