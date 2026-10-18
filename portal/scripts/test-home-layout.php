<?php

declare(strict_types=1);

/**
 * HomeLayoutService: normalisation, form parsing, and block lookups for the trial homepage layout.
 * Usage: php scripts/test-home-layout.php
 */

require dirname(__DIR__) . '/src/Services/HomeLayoutService.php';

use Portal\Services\HomeLayoutService;

$failures = [];
$assert = static function (string $label, bool $condition) use (&$failures): void {
    if (!$condition) {
        $failures[] = $label;
        echo "FAIL: {$label}\n";
        return;
    }
    echo "OK: {$label}\n";
};

$defaults = HomeLayoutService::defaults();
$keys = array_column($defaults['blocks'], 'key');
$definitionKeys = array_keys(HomeLayoutService::definitions());

$assert('defaults list every defined block once', $keys === $definitionKeys && count($keys) === count(array_unique($keys)));
$assert('defaults enable every block', array_filter($defaults['blocks'], static fn (array $b): bool => !$b['on']) === []);
$assert('general defaults show brand, currency and dock', $defaults['general']['brand'] === true && $defaults['general']['currency'] === true && $defaults['general']['dock'] === true);
$assert('blank search placeholder falls back', HomeLayoutService::normalize(['general' => ['search_placeholder' => '  ']])['general']['search_placeholder'] !== '');

$normalized = HomeLayoutService::normalize([
    'general' => ['brand' => '0', 'tagline' => "  أحذية   بالجملة  ", 'currency' => 1],
    'blocks' => [
        ['key' => 'feed', 'on' => '0', 'title' => 'المنتجات', 'options' => ['limit' => 999]],
        ['key' => 'ghost'],
        ['key' => 'showcase', 'on' => 1, 'options' => ['style' => 'banners', 'limit' => 2]],
    ],
]);
$assert('unknown blocks are dropped', !in_array('ghost', array_column($normalized['blocks'], 'key'), true));
$assert('explicit blocks keep their order first', $normalized['blocks'][0]['key'] === 'feed' && $normalized['blocks'][1]['key'] === 'showcase');
$assert('missing blocks are appended with defaults on', count($normalized['blocks']) === count($definitionKeys));
$assert('text is trimmed and collapsed', $normalized['general']['tagline'] === 'أحذية بالجملة');
$assert('booleans parse loose values', $normalized['general']['brand'] === false && $normalized['general']['currency'] === true);

$feed = HomeLayoutService::block('feed', $normalized);
$assert('int option is clamped to its max', $feed['options']['limit'] === 24 && $feed['on'] === false);
$showcase = HomeLayoutService::block('showcase', $normalized);
$assert('choice option is validated', $showcase['options']['style'] === 'banners' && $showcase['options']['limit'] === 2);
$assert('invalid choice falls back to default', HomeLayoutService::block('showcase', HomeLayoutService::normalize(['blocks' => [['key' => 'showcase', 'options' => ['style' => 'nope']]]]))['options']['style'] === 'mosaic');

$fromForm = HomeLayoutService::fromForm([
    'general' => ['tagline' => 'مرحبا'],
    'order' => ['feed', 'help', 'stories'],
    'on' => ['feed' => 'on', 'stories' => 'on'],
    'title' => ['feed' => 'كل المواد'],
    'opt' => ['feed' => ['limit' => '8'], 'stories' => ['season' => 'on']],
]);
$assert('form order is honoured then padded', array_slice(array_column($fromForm['blocks'], 'key'), 0, 3) === ['feed', 'help', 'stories']);
$assert('unchecked toggles become off', HomeLayoutService::block('help', $fromForm)['on'] === false && HomeLayoutService::block('feed', $fromForm)['on'] === true);
$assert('form checkbox options reflect presence', HomeLayoutService::block('stories', $fromForm)['options']['season'] === true && HomeLayoutService::block('stories', $fromForm)['options']['offers'] === false);
$assert('brand defaults off when checkbox missing from form', $fromForm['general']['brand'] === false);

$enabled = HomeLayoutService::enabledBlocks(HomeLayoutService::normalize([
    'blocks' => [['key' => 'feed', 'on' => true], ['key' => 'help', 'on' => false]],
]));
$assert('enabledBlocks skips disabled blocks', in_array('feed', array_column($enabled, 'key'), true) && !in_array('help', array_column($enabled, 'key'), true));

echo "\n";
if ($failures !== []) {
    echo count($failures) . " failure(s)\n";
    exit(1);
}

echo "home layout ok\n";
