Feature: Header get

  Background:
    Given I have the following config
      """
      <?xml version="1.0"?>
      <psalm totallyTyped="true">
        <projectFiles>
          <directory name="."/>
          <ignoreFiles> <directory name="../../vendor"/> </ignoreFiles>
        </projectFiles>

        <plugins>
          <pluginClass class="Psalm\SymfonyPsalmPlugin\Plugin"/>
        </plugins>
      </psalm>
      """

  Scenario: HeaderBag get method return type should return `?string` (unless third argument is provided for < Sf4.4)
    Given I have the following code
      """
      <?php

      use Symfony\Component\HttpFoundation\Request;

      class App
      {
        public function index(Request $request): void
        {
          $string = $request->headers->get('nullable_string');
          if (!$string) {
            return;
          }

          trim($string);
        }
      }
      """
    When I run Psalm
    Then I see no errors
