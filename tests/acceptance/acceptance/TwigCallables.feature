@symfony-common @php-8
Feature: Twig callables
  Twig calls the methods of the filters, functions and tests that extensions declare

  Scenario: methods with a Twig attribute are marked as used
    Given I have Symfony plugin enabled
    And I have the following code
      """
      <?php
      namespace App\Twig;

      use Twig\Attribute\AsTwigFilter;
      use Twig\Attribute\AsTwigFunction;
      use Twig\Attribute\AsTwigTest;

      final class AttributeExtension
      {
        #[AsTwigFilter('shout')]
        public function shout(string $value): string { return $value; }

        #[AsTwigFunction('hello')]
        public function hello(): string { return 'hello'; }

        #[AsTwigTest('even')]
        public function even(int $value): bool { return 0 === $value % 2; }

        public function unused(): void {}
      }
      """
    When I run Psalm with dead code detection
    Then I see these errors
      | Type                 | Message                                                            |
      | PossiblyUnusedMethod | Cannot find any calls to method App\Twig\AttributeExtension::unused |
    And I see no other errors

  Scenario: methods of class callables of Twig filters, functions and tests are marked as used
    Given I have Symfony plugin enabled
    And I have the following code
      """
      <?php
      namespace App\Twig;

      use Twig\Extension\AbstractExtension;
      use Twig\TwigFilter;
      use Twig\TwigFunction;
      use Twig\TwigTest;

      final class AppExtension extends AbstractExtension
      {
        #[\Override]
        public function getFilters(): array
        {
          return [
            new TwigFilter('static', [self::class, 'staticFilter']),
            new TwigFilter('runtime', [AppRuntime::class, 'filter']),
          ];
        }

        #[\Override]
        public function getFunctions(): array
        {
          return [new TwigFunction('runtime', callable: [AppRuntime::class, 'function'])];
        }

        #[\Override]
        public function getTests(): array
        {
          return [new TwigTest('runtime', [AppRuntime::class, 'test'])];
        }

        public static function staticFilter(string $value): string { return $value; }
        public function unused(): void {}
      }

      final class AppRuntime
      {
        public function filter(string $value): string { return $value; }
        public function function(): string { return ''; }
        public function test(): bool { return true; }
        public function unused(): void {}
      }

      function test(\Twig\Environment $twig): void
      {
        $twig->addExtension(new AppExtension());
      }
      """
    When I run Psalm with dead code detection
    Then I see these errors
      | Type                 | Message                                                       |
      | PossiblyUnusedMethod | Cannot find any calls to method App\Twig\AppExtension::unused |
      | PossiblyUnusedMethod | Cannot find any calls to method App\Twig\AppRuntime::unused   |
    And I see no other errors

