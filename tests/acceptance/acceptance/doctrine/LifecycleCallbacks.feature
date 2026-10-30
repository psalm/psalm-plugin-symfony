@symfony-common @php-8
Feature: Doctrine lifecycle callbacks

  Scenario: PrePersist method is not reported as unused
    Given I have Symfony plugin enabled
    And I have the following code
      """
      <?php
      use Doctrine\ORM\Mapping as ORM;

      final class Product
      {
        #[ORM\PrePersist]
        public function onPrePersist(): void {}
      }
      """
    When I run Psalm with dead code detection
    Then I see no errors

  Scenario: All lifecycle callback attributes are recognized and flagged methods are still reported
    Given I have Symfony plugin enabled
    And I have the following code
      """
      <?php
      use Doctrine\ORM\Mapping as ORM;

      final class Order
      {
        #[ORM\PrePersist]
        public function onPrePersist(): void {}

        #[ORM\PostPersist]
        public function onPostPersist(): void {}

        #[ORM\PreUpdate]
        public function onPreUpdate(): void {}

        #[ORM\PostUpdate]
        public function onPostUpdate(): void {}

        #[ORM\PreRemove]
        public function onPreRemove(): void {}

        #[ORM\PostRemove]
        public function onPostRemove(): void {}

        #[ORM\PostLoad]
        public function onPostLoad(): void {}

        #[ORM\PreFlush]
        public function onPreFlush(): void {}

        public function unusedMethod(): void {}
      }
      """
    When I run Psalm with dead code detection
    Then I see these errors
      | Type                | Message                                                          |
      | PossiblyUnusedMethod | Cannot find any calls to method Order::unusedMethod |
    And I see no other errors