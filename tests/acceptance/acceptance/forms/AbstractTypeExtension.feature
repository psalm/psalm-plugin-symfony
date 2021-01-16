@symfony-form
Feature: FormType templates

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
  Scenario: Validate FormExtension class
    Given I have the following code
          """
      <?php

      use Symfony\Component\Form\AbstractTypeExtension;
      use Symfony\Component\Form\Extension\Core\Type\FormType;

      class TestExtension extends AbstractTypeExtension
      {
          public static function getExtendedTypes()
          {
              yield FormType::class;
          }
      }
      """
    When I run Psalm
    Then I see no errors

