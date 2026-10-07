<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Module\Analysis\Shadow;

use Internal\Path;
use Opmin\Module\Analysis\Shadow\FileShadows;
use Opmin\Module\Analysis\Shadow\ShadowCollector;
use Opmin\Module\Analysis\Shadow\ShadowIndex;
use Opmin\Module\Project\Project;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(ShadowIndex::class)]
#[Covers(ShadowCollector::class)]
#[Covers(FileShadows::class)]
final class ShadowIndexTest
{
    private string $dir = '';

    /**
     * Code somewhere in the project that shadows `$function` in `$namespace`:
     * [code, namespace, function].
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function shadowed(): iterable
    {
        yield 'namespaced function' => ['namespace App; function strlen($s) { return 0; }', 'App', 'strlen'];
        yield 'case-insensitive' => ['namespace APP\Foo; function StrLen($s) {}', 'app\foo', 'STRLEN'];
        yield 'conditional declaration' => ['namespace App; if (!function_exists("App\\\\time")) { function time() {} }', 'App', 'time'];
        yield 'braced namespace' => ['namespace App { function count($a) {} }', 'App', 'count'];
        yield 'declared inside a function' => ['namespace App; function boot() { function strlen($s) {} }', 'App', 'strlen'];
        yield 'php-mock getFunctionMock' => ['namespace App\Tests; class T { function t() { $this->getFunctionMock(__NAMESPACE__, "time"); } }', 'App\Tests', 'time'];
        yield 'php-mock literal namespace' => ['class T { function t() { $this->getFunctionMock("App\\\\Clock", "time"); } }', 'App\Clock', 'time'];
        yield 'php-mock unknown namespace' => ['class T { function t($ns) { $this->getFunctionMock($ns, "time"); } }', 'Any\Where', 'time'];
        yield 'php-mock unknown function' => ['class T { function t($f) { $this->getFunctionMock("App", $f); } }', 'App', 'strlen'];
        yield 'php-mock static define' => ['PHPMock::defineFunctionMock("App", "rand");', 'App', 'rand'];
        yield 'php-mock-mockery' => ['\phpmock\mockery\PHPMockery::mock("App", "time");', 'App', 'time'];
        yield 'php-mock Mock class' => ['use phpmock\Mock; new Mock("App", "time", fn() => 1);', 'App', 'time'];
        yield 'MockBuilder chain' => ['$m = (new MockBuilder())->setNamespace("App")->setName("time")->build();', 'App', 'time'];
        yield 'MockBuilder name before namespace' => ['$b->setName("time")->setNamespace("App");', 'App', 'time'];
        yield 'MockBuilder namespace alone' => ['$b->setNamespace("App"); $b->setName("time");', 'App', 'strlen'];
        yield 'ClockMock::register' => ['namespace Tests; use App\Clock; ClockMock::register(Clock::class);', 'App', 'time'];
        yield 'ClockMock::register of a test class' => ['ClockMock::register(\App\Tests\Unit\FooTest::class);', 'App\Unit', 'microtime'];
        yield 'ClockMock::register of self' => ['namespace App\Tests; class T { function s() { ClockMock::register(self::class); } }', 'App', 'date'];
        yield 'ClockMock::register of a Tests root class' => ['ClockMock::register(\Tests\Unit\FooTest::class);', 'Unit', 'time'];
        yield 'ClockMock::register unknown class' => ['ClockMock::register($class);', 'Any', 'sleep'];
        yield 'DnsMock::register' => ['DnsMock::register(\App\Net::class);', 'App', 'checkdnsrr'];
        yield 'time-sensitive annotation' => ["namespace App\\Tests;\n/** @group time-sensitive */\nclass FooTest {}", 'App', 'time'];
        yield 'time-sensitive attribute' => ["namespace App\\Tests;\n#[Group('time-sensitive')]\nclass FooTest {}", 'App', 'usleep'];
        yield 'eval of a literal' => ['eval("namespace App; function time() { return 1; }");', 'App', 'time'];
        yield 'eval with a variable namespace' => ['eval("namespace $ns; function time() {}");', 'Any', 'time'];
        yield 'eval with literal parts' => ['eval($prefix . "namespace App; function helper() {}");', 'App', 'helper'];
    }

    /**
     * Code that does not shadow `$function` in `$namespace`.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function notShadowed(): iterable
    {
        yield 'another namespace' => ['namespace Other; function strlen($s) {}', 'App', 'strlen'];
        yield 'another function' => ['namespace App; function strlen2($s) {}', 'App', 'strlen'];
        yield 'method of the same name' => ['namespace App; class S { public function strlen($s) {} }', 'App', 'strlen'];
        yield 'global function' => ['function strlen_utf8($s) {}', 'App', 'strlen'];
        yield 'mock of another namespace' => ['PHPMock::defineFunctionMock("Other", "time");', 'App', 'time'];
        yield 'ClockMock does not mock strlen' => ['ClockMock::register(\App\Clock::class);', 'App', 'strlen'];
        yield 'ClockMock of another namespace' => ['ClockMock::register(\Other\Clock::class);', 'App', 'time'];
        yield 'not a time-sensitive group' => ["namespace App\\Tests;\n/** @group slow */\nclass FooTest {}", 'App', 'time'];
        yield 'own setNamespace method' => ['$this->setNamespace("App");', 'Other', 'time'];
        yield 'eval without functions' => ['eval("return 1;");', 'App', 'time'];
        yield 'global namespace never shadows' => ['function strlen2() {}', '', 'strlen'];
    }

    /**
     * @return iterable<string, array{string, string, string, bool}>
     */
    public static function constants(): iterable
    {
        yield 'namespaced const' => ['namespace App; const PHP_EOL = "x";', 'App', 'PHP_EOL', false];
        yield 'define with a namespaced name' => ['define("App\\\\PHP_EOL", 1);', 'app', 'PHP_EOL', false];
        yield 'define with __NAMESPACE__' => ['namespace App; define(__NAMESPACE__ . "\\\\E_ALL", 1);', 'App', 'E_ALL', false];
        yield 'constant name is case-sensitive' => ['namespace App; const php_eol = 1;', 'App', 'PHP_EOL', true];
        yield 'class constant does not shadow' => ['namespace App; class C { const PHP_EOL = 1; }', 'App', 'PHP_EOL', true];
        yield 'global define does not shadow' => ['define("PHP_EOL2", 1);', 'App', 'PHP_EOL', true];
    }

    #[BeforeTest]
    public function createProject(): void
    {
        $this->dir = \sys_get_temp_dir() . '/opmin-shadow-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir . '/vendor/lib', 0777, true);
        \mkdir($this->dir . '/tests', 0777, true);
    }

    #[AfterTest]
    public function removeProject(): void
    {
        \exec('rm -rf ' . \escapeshellarg($this->dir));
    }

    #[DataProvider('shadowed')]
    public function shadowedFunctionCannotBeQualified(string $code, string $namespace, string $function): void
    {
        $index = new ShadowIndex();

        $index->add((new ShadowCollector())->collect("<?php\n{$code}"));

        Assert::false($index->canQualifyFunction($namespace, $function));
    }

    #[DataProvider('notShadowed')]
    public function freeFunctionCanBeQualified(string $code, string $namespace, string $function): void
    {
        $index = new ShadowIndex();

        $index->add((new ShadowCollector())->collect("<?php\n{$code}"));

        Assert::true($index->canQualifyFunction($namespace, $function));
    }

    #[DataProvider('constants')]
    public function namespacedConstantShadowsGlobalOne(string $code, string $namespace, string $constant, bool $free): void
    {
        $index = new ShadowIndex();

        $index->add((new ShadowCollector())->collect("<?php\n{$code}"));

        Assert::same($index->canQualifyConstant($namespace, $constant), $free);
    }

    public function vendorDeclaresButDoesNotMock(): void
    {
        $collector = new ShadowCollector();

        $shadows = $collector->collect('<?php namespace Lib; function time() {} $this->getFunctionMock($ns, $name);', vendor: true);

        Assert::same($shadows->functions, ['lib\time']);
        Assert::same($shadows->mocks, []);
    }

    public function globalUserSymbolsAreKnown(): void
    {
        $index = new ShadowIndex();

        $index->add((new ShadowCollector())->collect('<?php function collect($a) {} define("APP_ROOT", "/");'));
        $index->add((new ShadowCollector())->collect('<?php namespace App; function helper() {}'));

        Assert::true($index->hasGlobalFunction('collect'));
        Assert::true($index->hasGlobalFunction('\COLLECT'));
        Assert::false($index->hasGlobalFunction('helper'));
        Assert::false($index->hasGlobalFunction('App\helper'));
        Assert::true($index->hasGlobalConstant('APP_ROOT'));
        Assert::false($index->hasGlobalConstant('app_root'));
    }

    public function buildScansTestsAndVendorAndCaches(): void
    {
        \file_put_contents($this->dir . '/tests/ClockTest.php', '<?php namespace App\Tests; ClockMock::register(\App\Clock::class);');
        \file_put_contents($this->dir . '/vendor/lib/functions.php', '<?php namespace App; function strlen($s) {}');
        \file_put_contents($this->dir . '/plain.php', '<?php namespace App; class Plain { public function strlen() {} }');
        $project = new Project(Path::create($this->dir), true, null);
        $cache = Path::create($this->dir . '/.cache');

        $first = ShadowIndex::build($project, $cache);
        \file_put_contents($this->dir . '/tests/ClockTest.php', '<?php namespace App\Tests; ClockMock::register(\Other\Clock::class);');
        \touch($this->dir . '/tests/ClockTest.php', \time() + 10);
        $second = ShadowIndex::build($project, $cache);

        Assert::false($first->canQualifyFunction('App', 'time'));
        Assert::false($first->canQualifyFunction('App', 'strlen'));
        Assert::true($first->canQualifyFunction('App', 'count'));
        Assert::true($second->canQualifyFunction('App', 'time'));
        Assert::false($second->canQualifyFunction('Other', 'time'));
        Assert::true(\is_file($this->dir . '/.cache/shadows.json'));
    }

    public function phpunitConfigWithTimeSensitiveNamespacesCoversEverything(): void
    {
        \file_put_contents($this->dir . '/phpunit.xml.dist', '<phpunit><listeners><listener class="SymfonyTestsListener"><arguments><array><element key="time-sensitive"><string>App</string></element></array></arguments></listener></listeners></phpunit>');

        $index = ShadowIndex::build(new Project(Path::create($this->dir), true, null), null);

        Assert::false($index->canQualifyFunction('Any\Namespace', 'time'));
        Assert::true($index->canQualifyFunction('Any\Namespace', 'checkdnsrr'));
    }

    public function survivesSerialization(): void
    {
        $index = new ShadowIndex();
        $index->add((new ShadowCollector())->collect('<?php namespace App; function strlen() {} const X = 1; PHPMock::defineFunctionMock("Lib", "time");'));

        $copy = ShadowIndex::fromArray(\json_decode(\json_encode($index->toArray(), \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR));

        Assert::same($copy->toArray(), $index->toArray());
        Assert::false($copy->canQualifyFunction('App', 'strlen'));
        Assert::false($copy->canQualifyConstant('App', 'X'));
        Assert::false($copy->canQualifyFunction('Lib', 'time'));
    }

    public function cacheNoticesASizeChangeWithTheSameMtime(): void
    {
        $file = $this->dir . '/a.php';
        \file_put_contents($file, '<?php namespace App; function strlen() {}');
        \touch($file, 1_700_000_000);
        $project = new Project(Path::create($this->dir), true, null);
        $cache = Path::create($this->dir . '/.cache');
        ShadowIndex::build($project, $cache);

        \file_put_contents($file, '<?php namespace App; function strlen2() {}');
        \touch($file, 1_700_000_000);
        $index = ShadowIndex::build($project, $cache);

        Assert::true($index->canQualifyFunction('App', 'strlen'));
        Assert::false($index->canQualifyFunction('App', 'strlen2'));
    }

    public function cacheOfAnotherFormatIsIgnoredAndTheCacheDirIsNotScanned(): void
    {
        \mkdir($this->dir . '/.cache');
        \file_put_contents($this->dir . '/.cache/stale.php', '<?php namespace App; function count() {}');
        \file_put_contents($this->dir . '/a.php', '<?php namespace App; function strlen() {}');
        $stamp = \filesize($this->dir . '/a.php') . ':' . \filemtime($this->dir . '/a.php');
        \file_put_contents($this->dir . '/.cache/shadows.json', \json_encode([
            'format' => 'old',
            'files' => ['a.php' => [$stamp, ['functions' => ['app\time'], 'constants' => [], 'mocks' => []]]],
        ]));

        $index = ShadowIndex::build(new Project(Path::create($this->dir), true, null), Path::create($this->dir . '/.cache'));
        /** @var array{format: string, files: array<string, array{string, mixed}>} $cache */
        $cache = \json_decode((string) \file_get_contents($this->dir . '/.cache/shadows.json'), true);

        Assert::true($index->canQualifyFunction('App', 'time'));
        Assert::false($index->canQualifyFunction('App', 'strlen'));
        Assert::true($index->canQualifyFunction('App', 'count'));
        Assert::same(\array_keys($cache['files']), ['a.php']);
        Assert::same($cache['files']['a.php'][0], $stamp);
        Assert::string($cache['format'])->startsWith('1:');
    }

    public function aValidCacheEntryIsTrusted(): void
    {
        \file_put_contents($this->dir . '/a.php', '<?php echo 1;');
        $project = new Project(Path::create($this->dir), true, null);
        $cache = Path::create($this->dir . '/.cache');
        ShadowIndex::build($project, $cache);
        /** @var array{format: string, files: array<string, array{string, mixed}>} $data */
        $data = \json_decode((string) \file_get_contents($this->dir . '/.cache/shadows.json'), true);
        $data['files']['a.php'][1] = ['functions' => ['app\time'], 'constants' => [], 'mocks' => []];
        \file_put_contents($this->dir . '/.cache/shadows.json', \json_encode($data));

        $index = ShadowIndex::build($project, $cache);

        Assert::false($index->canQualifyFunction('App', 'time'));
    }

    public function nestedVendorDirectoriesOnlyDeclare(): void
    {
        \mkdir($this->dir . '/packages/lib/vendor/x', 0777, true);
        \file_put_contents($this->dir . '/packages/lib/vendor/x/mock.php', '<?php PHPMock::defineFunctionMock("App", "time"); namespace\f();');

        $index = ShadowIndex::build(new Project(Path::create($this->dir), true, null), null);

        Assert::true($index->canQualifyFunction('App', 'time'));
    }

    public function phpunitXmlWithoutDistCounts(): void
    {
        \file_put_contents($this->dir . '/phpunit.xml', '<phpunit><element key="dns-sensitive"/></phpunit>');

        $index = ShadowIndex::build(new Project(Path::create($this->dir), true, null), null);

        Assert::false($index->canQualifyFunction('Any', 'checkdnsrr'));
        Assert::true($index->canQualifyFunction('Any', 'time'));
        Assert::same($index->toArray()['mocks'][0], [null, 'checkdnsrr']);
    }

    public function namesAreNormalized(): void
    {
        $index = new ShadowIndex();
        $index->add(new FileShadows(['app\strlen', 'helper'], ['app\X', 'GLOBAL_C'], [['app', 'time'], [null, 'rand']]));

        Assert::false($index->canQualifyFunction('\App\\', 'strlen'));
        Assert::false($index->canQualifyConstant('\APP\\', 'X'));
        Assert::true($index->hasGlobalConstant('\GLOBAL_C'));
        Assert::true($index->canQualifyFunction('', 'rand'));
        Assert::false($index->canQualifyFunction('Other', 'rand'));
        Assert::same($index->toArray(), [
            'functions' => ['app\strlen', 'helper'],
            'constants' => ['GLOBAL_C', 'app\X'],
            'mocks' => [[null, 'rand'], ['app', 'time']],
        ]);
    }
}
