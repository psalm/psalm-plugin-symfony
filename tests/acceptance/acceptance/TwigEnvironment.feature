@symfony-common
Feature: Twig Environment

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

  Scenario: getRuntime method return type is dynamic
    Given I have the following code
    """
    <?php

    use Symfony\Component\Form\FormRenderer;
    use Twig\Environment;

    class Foo
    {
    public function foo(Environment $environment): FormRenderer
    {
      return $environment->getRuntime(FormRenderer::class);
    }
    }
    """
    When I run Psalm
    Then I see no errors
