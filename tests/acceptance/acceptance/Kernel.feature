@symfony-5 @symfony-6
Feature: Kernel

  Background:
    Given I have Symfony plugin enabled

  Scenario: MixedOperand error about $environment is not raised
    Given I have the following code
      """
      <?php

      use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
      use Symfony\Component\HttpKernel\Kernel as BaseKernel;
      use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

      abstract class Kernel extends BaseKernel
      {
          use MicroKernelTrait;

          protected function configureContainer(ContainerConfigurator $container): void
          {
              $container->import('../config/{packages}/' . $this->environment . '/*.yaml');
          }
      }
      """
    When I run Psalm
    Then I see no errors
