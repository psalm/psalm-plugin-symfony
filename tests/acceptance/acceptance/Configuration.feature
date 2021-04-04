@symfony-common
Feature: Configuration

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

  Scenario: ArrayNodeDefinition correctly resolves prototype($foo) return type
    Given I have the following code
      """
      <?php

      use Symfony\Component\Config\Definition\Builder\TreeBuilder;
      use Symfony\Component\Config\Definition\ConfigurationInterface;

      class Configuration implements ConfigurationInterface
      {
          public function getConfigTreeBuilder(): TreeBuilder
          {
              $treeBuilder = new TreeBuilder('connections');

              /** @psalm-trace $arrayNode */
              $arrayNode = $treeBuilder->getRootNode()
                  ->requiresAtLeastOneElement()
                  ->useAttributeAsKey('name')
                  ->prototype('array');

              /** @psalm-trace $enumNode */
              $enumNode = $treeBuilder->getRootNode()->prototype('enum');

              return $treeBuilder;
          }
      }

      """
    When I run Psalm
    Then I see these errors
      | Type  | Message                                                                              |
      | Trace          | $arrayNode: Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition |
      | UnusedVariable | $arrayNode is never referenced or the value is not used                     |
      | Trace          | $enumNode: Symfony\Component\Config\Definition\Builder\EnumNodeDefinition   |
      | UnusedVariable | $enumNode is never referenced or the value is not used                      |

    And I see no other errors
