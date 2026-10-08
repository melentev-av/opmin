<?php

/**
 * Opcodes after the optimizer (ops_opt) of the fixtures in this directory, per PHP minor version.
 *
 * Checked by hand against the dumps in tests/Fixtures/Dumps/<version>/ (the `lines=N` of every
 * `(after optimizer)` block, read with awk, not with opmin's parser). `<main>` stands for
 * `<file>::<main>`. Abstract and interface methods are absent: PHP 8.1 dumps them (2 opcodes each),
 * 8.2+ does not, and opmin never reports them.
 *
 * @return array<non-empty-string, array<non-empty-string, array<non-empty-string, int>>> version => file => key => ops_opt
 */

declare(strict_types=1);

$basic = [
    '<main>' => 13,
    '<main>::{closure:1}' => 1,
    'Fixture\Count\polyfill' => 1,
    'Fixture\Count\top' => 5,
    'Fixture\Count\generate' => 3,
    'Fixture\Count\byRef' => 3,
    'Fixture\Count\Base::describe' => 1,
    'Fixture\Count\Greets::greet' => 6,
    'Fixture\Count\Suit::label' => 5,
    'Fixture\Count\Service::anonymous::{class:1}::name' => 5,
    'Fixture\Count\Service::anonymous::{class:1}::name::{closure:1}' => 1,
    'Fixture\Count\Service::anonymousTwo::{class:1}::area' => 1,
    'Fixture\Count\Service::total' => 5,
    'Fixture\Count\Service::name' => 1,
    'Fixture\Count\Service::map' => 14,
    'Fixture\Count\Service::map::{closure:1}' => 4,
    'Fixture\Count\Service::map::{closure:2}' => 4,
    'Fixture\Count\Service::nested' => 2,
    'Fixture\Count\Service::nested::{closure:1}' => 2,
    'Fixture\Count\Service::nested::{closure:1}::{closure:1}' => 2,
    'Fixture\Count\Service::nested::{closure:1}::{closure:1}::{closure:1}' => 1,
    'Fixture\Count\Service::twoOnOneLine' => 5,
    'Fixture\Count\Service::twoOnOneLine::{closure:1}' => 1,
    'Fixture\Count\Service::twoOnOneLine::{closure:2}' => 1,
    'Fixture\Count\Service::anonymous' => 5,
    'Fixture\Count\Service::anonymous::{closure:1}' => 1,
    'Fixture\Count\Service::anonymousTwo' => 5,
    'Fixture\Count\Service::declaresFunction' => 2,
    'Fixture\Count\declaredInside' => 1,
];
# Before 8.4 the optimizer keeps VERIFY_RETURN_TYPE for a closure returned as \Closure.
$basicBefore84 = ['Fixture\Count\Service::nested' => 3] + $basic;
$readonly = [
    '<main>' => 2,
    'Fixture\Count\Money::__construct' => 4,
    'Fixture\Count\Money::add' => 8,
];
$strings = [
    '<main>' => 1,
    'Fixture\Count\newlines' => 4,
    'Fixture\Count\imitation' => 7,
    'Fixture\Count\after' => 1,
];
# 8.2+ evaluates strtoupper() of a constant string at compile time.
$strings81 = ['Fixture\Count\newlines' => 7] + $strings;
$hooks = [
    '<main>' => 2,
    'Fixture\Count\Person::__construct' => 4,
    'Fixture\Count\Person::$name::get' => 5,
    'Fixture\Count\Person::$name::set' => 5,
    'Fixture\Count\Person::$age::get' => 4,
];
# 8.4+ compiles trim()/implode() of a namespace twice (frameless call and fallback): the methods grow,
# the closure copies are not counted again.
$frameless = [
    '<main>' => 2,
    'Fixture\Count\Frameless::camel' => 33,
    'Fixture\Count\Frameless::camel::{closure:1}' => 5,
    'Fixture\Count\Frameless::twoOnOneLine' => 47,
    'Fixture\Count\Frameless::twoOnOneLine::{closure:1}' => 2,
    'Fixture\Count\Frameless::twoOnOneLine::{closure:2}' => 2,
    'Fixture\Count\Frameless::nested' => 23,
    'Fixture\Count\Frameless::nested::{closure:1}' => 23,
    'Fixture\Count\Frameless::nested::{closure:1}::{closure:1}' => 2,
    'Fixture\Count\Frameless::qualified' => 9,
    'Fixture\Count\Frameless::qualified::{closure:1}' => 2,
    'Fixture\Count\Frameless::after' => 4,
];
$framelessBefore84 = [
    'Fixture\Count\Frameless::camel' => 18,
    'Fixture\Count\Frameless::twoOnOneLine' => 25,
    'Fixture\Count\Frameless::nested' => 13,
    'Fixture\Count\Frameless::nested::{closure:1}' => 14,
    'Fixture\Count\Frameless::qualified' => 14,
] + $frameless;
# The second closure of lastArgument and the closure of multiline end on the line of the next token.
$arrow = [
    '<main>' => 2,
    'Fixture\Count\Arrow::lastArgument' => 15,
    'Fixture\Count\Arrow::lastArgument::{closure:1}' => 4,
    'Fixture\Count\Arrow::lastArgument::{closure:2}' => 4,
    'Fixture\Count\Arrow::multiline' => 5,
    'Fixture\Count\Arrow::multiline::{closure:1}' => 11,
    'Fixture\Count\Arrow::sameLine' => 2,
    'Fixture\Count\Arrow::sameLine::{closure:1}' => 4,
    'Fixture\Count\Arrow::after' => 4,
];
$arrowBefore84 = ['Fixture\Count\Arrow::sameLine' => 3] + $arrow;
# The closures of dead() on line 12 are never compiled: only the one on line 13 is counted.
$dead = [
    '<main>' => 1,
    'Fixture\Count\dead' => 8,
    'Fixture\Count\dead::{closure:3}' => 3,
    'Fixture\Count\alive' => 3,
    'Fixture\Count\alive::{closure:1}' => 1,
];

return [
    '8.1' => ['Basic.php' => $basicBefore84, 'Strings.php' => $strings81, 'Frameless.php' => $framelessBefore84, 'Arrow.php' => $arrowBefore84, 'Dead.php' => $dead],
    '8.2' => ['Basic.php' => $basicBefore84, 'Readonly.php' => $readonly, 'Strings.php' => $strings, 'Frameless.php' => $framelessBefore84, 'Arrow.php' => $arrowBefore84, 'Dead.php' => $dead],
    '8.3' => ['Basic.php' => $basicBefore84, 'Readonly.php' => $readonly, 'Strings.php' => $strings, 'Frameless.php' => $framelessBefore84, 'Arrow.php' => $arrowBefore84, 'Dead.php' => $dead],
    '8.4' => ['Basic.php' => $basic, 'Readonly.php' => $readonly, 'Hooks.php' => $hooks, 'Strings.php' => $strings, 'Frameless.php' => $frameless, 'Arrow.php' => $arrow, 'Dead.php' => $dead],
    # 8.5 drops VERIFY_RETURN_TYPE of `new self` returned as `self`.
    '8.5' => [
        'Basic.php' => $basic,
        'Readonly.php' => ['Fixture\Count\Money::add' => 7] + $readonly,
        'Hooks.php' => $hooks,
        'Strings.php' => $strings,
        'Frameless.php' => $frameless,
        'Arrow.php' => $arrow,
        'Dead.php' => $dead,
    ],
];
