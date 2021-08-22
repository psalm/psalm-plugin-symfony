@symfony-common
Feature: Configuration

  Background:
    Given I have Symfony plugin enabled

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
      | Trace          | $enumNode: Symfony\Component\Config\Definition\Builder\EnumNodeDefinition   |

    And I see no other errors
