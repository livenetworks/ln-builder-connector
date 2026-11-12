<?php
/**
 * Elementor read -> lean -> write -> read round-trip check (CLI only, no WordPress needed).
 *
 * Usage: php tests/elementor-roundtrip.php [fixture.json]
 * Exits 1 when the lean document is not stable across a round-trip or the expander reports errors.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit;
}

$GLOBALS['opts'] = [
    'elementor_active_kit' => 5,
    'ln_builder_site_id' => 'site-1',
    'ln_builder_extra_secret' => 'test-secret',
    'ln_builder_managed_css' => [
        'classes' => [['name' => 'hero'], ['name' => 'btn-primary']],
        'variables' => [['name' => '--space-m', 'value' => '1rem']],
    ],
];
$GLOBALS['meta'] = [];

function get_option($k, $d = false) { return $GLOBALS['opts'][$k] ?? $d; }
function update_option($k, $v, $a = null) { $GLOBALS['opts'][$k] = $v; return true; }
function get_post_status($id) { return 'publish'; }
function get_post_meta($id, $k, $s = false) { return $GLOBALS['meta'][$id][$k] ?? ''; }
function update_post_meta($id, $k, $v) { $GLOBALS['meta'][$id][$k] = $v; return true; }
function wp_json_encode($d, $f = 0) { return json_encode($d, $f); }
function wp_slash($v) { return $v; }

spl_autoload_register(static function (string $class): void {
    $prefix = 'LiveNetworks\\BuilderConnector\\';
    if (str_starts_with($class, $prefix)) {
        $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

use LiveNetworks\BuilderConnector\Builders\Elementor\ElementorAdapter;
use LiveNetworks\BuilderConnector\Converter\Compactor;
use LiveNetworks\BuilderConnector\Converter\Expander;

$fixture = $argv[1] ?? __DIR__ . '/fixtures/elementor-sample.json';
$GLOBALS['meta'][10]['_elementor_data'] = (string) file_get_contents($fixture);

$adapter = new ElementorAdapter('3.25.0');
$vocab = $adapter->buildSiteVocabulary();
$schema = $adapter->getSchema();

$doc = (new Compactor())->compact($adapter->contentArea(10, 'content'), 'content', $schema, $vocab);
echo json_encode($doc->data(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
foreach ($doc->problems() as $p) {
    echo 'compact: ', json_encode($p->toArray()), "\n";
}

$expanded = (new Expander())->expand($doc->data(), $schema, $vocab, true);
if ($expanded->hasErrors()) {
    foreach ($expanded->problems() as $p) {
        echo 'expand: ', json_encode($p->toArray()), "\n";
    }
    exit(1);
}
$adapter->putContentArea(10, 'content', $expanded->data());

$doc2 = (new Compactor())->compact($adapter->contentArea(10, 'content'), 'content', $schema, $vocab);
$stable = json_encode($doc2->data()['sections']) === json_encode($doc->data()['sections']);
echo 'round-trip stable: ', $stable ? 'YES' : 'NO', "\n";
exit($stable ? 0 : 1);
