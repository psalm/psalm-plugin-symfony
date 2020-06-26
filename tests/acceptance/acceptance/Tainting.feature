Feature: Tainting

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
    And I have the following code preamble
      """
      <?php

      use Symfony\Component\HttpFoundation\Request;
      use Symfony\Component\HttpFoundation\Response;
      """

    Scenario: One parameter of the Request is used in the body of a Response object
      Given I have the following code
        """
        class MyController
        {
          public function __invoke(Request $request): Response
          {
            return new Response($request->get('untrusted'));
          }
        }
        """
      When I run Psalm with taint analysis
      Then I see these errors
        | Type                        | Message                                                     |
        | TaintedInput | Detected tainted html |
      And I see no other errors

