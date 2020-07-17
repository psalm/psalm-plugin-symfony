<?php

/**
 * @psalm-pure
 * @psalm-flow ($string) -> return
 *
 * @psalm-taint-sink html $string
 */
function twig_raw_filter(string $string): string {}
