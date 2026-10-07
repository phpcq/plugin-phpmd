<?php

declare(strict_types=1);

namespace Phpcq\PhpmdPluginTest;

use Phpcq\PluginApi\Version10\Configuration\PluginConfigurationBuilderInterface;
use Phpcq\PluginApi\Version10\Configuration\PluginConfigurationInterface;
use Phpcq\PluginApi\Version10\DiagnosticsPluginInterface;
use Phpcq\PluginApi\Version10\EnvironmentInterface;
use Phpcq\PluginApi\Version10\Output\OutputTransformerFactoryInterface;
use Phpcq\PluginApi\Version10\ProjectConfigInterface;
use Phpcq\PluginApi\Version10\Report\AttachmentBuilderInterface;
use Phpcq\PluginApi\Version10\Report\DiagnosticBuilderInterface;
use Phpcq\PluginApi\Version10\Report\FileDiagnosticBuilderInterface;
use Phpcq\PluginApi\Version10\Report\TaskReportInterface;
use Phpcq\PluginApi\Version10\Task\PhpTaskBuilderInterface;
use Phpcq\PluginApi\Version10\Task\TaskFactoryInterface;
use Phpcq\PluginApi\Version10\Task\TaskInterface;
use PHPUnit\Framework\TestCase;

use function dirname;
use function file_put_contents;
use function iterator_to_array;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * @coversNothing
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
final class PhpmdPluginTest extends TestCase
{
    private function instantiate(): DiagnosticsPluginInterface
    {
        return include dirname(__DIR__) . '/src/phpmd.php';
    }

    public function testPluginName(): void
    {
        self::assertSame('phpmd', $this->instantiate()->getName());
    }

    public function testPluginDescribesConfig(): void
    {
        $configOptionsBuilder = $this->createMock(PluginConfigurationBuilderInterface::class);

        $this->instantiate()->describeConfiguration($configOptionsBuilder);

        // We assume it worked out as the plugin did execute correctly.
        $this->addToAssertionCount(1);
    }

    public function testPluginCreatesDiagnosticTasks(): void
    {
        $config = $this->createMock(PluginConfigurationInterface::class);
        $environment = $this->createMock(EnvironmentInterface::class);

        $this->instantiate()->createDiagnosticTasks($config, $environment);

        // We assume it worked out as the plugin did execute correctly.
        $this->addToAssertionCount(1);
    }

    public function testBuildsAnalyzeArguments(): void
    {
        $config = $this->mockConfig(
            [
                'directories'  => ['src', 'tests'],
                'ruleset'      => ['naming', 'phpmd.xml'],
                'exclude'      => ['vendor/', ' ', '*src/foo/*'],
                'custom_flags' => ['--strict'],
            ],
            ['bootstrap' => 'tests/bootstrap.php']
        );

        $this->runWithExpectedArguments($config, [
            'analyze',
            '--no-progress',
            '--threads',
            '4',
            '--ruleset',
            'naming',
            '--ruleset',
            'phpmd.xml',
            '--exclude',
            'vendor/',
            '--exclude',
            '*src/foo/*',
            '--bootstrap',
            'tests/bootstrap.php',
            '--strict',
            '--reportfile-xml',
            '/tmp/report.xml',
            '--',
            'src',
            'tests',
        ]);
    }

    public function testOmitsUnconfiguredOptionalArguments(): void
    {
        $config = $this->mockConfig(
            [
                'directories'  => ['src'],
                'ruleset'      => ['naming', 'unusedcode'],
                'custom_flags' => [],
            ],
            ['bootstrap' => ' ']
        );

        $this->runWithExpectedArguments($config, [
            'analyze',
            '--no-progress',
            '--threads',
            '4',
            '--ruleset',
            'naming',
            '--ruleset',
            'unusedcode',
            '--reportfile-xml',
            '/tmp/report.xml',
            '--',
            'src',
        ]);
    }

    public function testReportsViolationsAndProcessingErrors(): void
    {
        $xmlFile = tempnam(sys_get_temp_dir(), 'phpcq-phpmd');
        file_put_contents(
            $xmlFile,
            <<<'XML'
            <?xml version="1.0" encoding="UTF-8" ?>
            <pmd version="3.0.0" tool="phpmd" timestamp="2026-10-07T09:56:33+00:00">
              <file name="/project/src/Foo.php">
                <violation beginline="4" endline="7" rule="ShortMethodName" ruleset="Naming Rules"
                  externalInfoUrl="https://phpmd.org/rules/naming.html#shortmethodname" priority="3">
                  Avoid using short method names like Foo::x().
                </violation>
              </file>
              <error filename="/project/src/Broken.php" msg="Unexpected token: {, line: 1.
            #0 phar:///phpmd.phar/vendor/pdepend/pdepend/src/Engine.php(805): parse()" />
              <error filename="" msg="Something went wrong." />
            </pmd>
            XML
        );

        $diagnostics = [];
        $report      = $this->mockReport($diagnostics);

        try {
            $transformerFactory = $this->captureOutputTransformerFactory($xmlFile);
            $transformerFactory->createFor($report)->finish(3);
        } finally {
            unlink($xmlFile);
        }

        self::assertSame(
            [
                [
                    'severity' => TaskReportInterface::SEVERITY_MAJOR,
                    'message'  => "Avoid using short method names like Foo::x().\n"
                        . '(Ruleset: Naming Rules, https://phpmd.org/rules/naming.html#shortmethodname)',
                    'file'     => 'src/Foo.php',
                    'range'    => [4, null, 7],
                    'source'   => 'ShortMethodName',
                ],
                [
                    'severity' => TaskReportInterface::SEVERITY_FATAL,
                    'message'  => 'Unexpected token: {, line: 1.',
                    'file'     => 'src/Broken.php',
                ],
                [
                    'severity' => TaskReportInterface::SEVERITY_FATAL,
                    'message'  => 'Something went wrong.',
                ],
            ],
            $diagnostics
        );
    }

    /**
     * @param array<string, list<string>> $stringLists
     * @param array<string, string>       $strings
     */
    private function mockConfig(array $stringLists, array $strings): PluginConfigurationInterface
    {
        $config = $this->createMock(PluginConfigurationInterface::class);
        $config->method('has')->willReturnCallback(
            static fn (string $key): bool => isset($stringLists[$key]) || isset($strings[$key])
        );
        $config->method('getStringList')->willReturnCallback(
            static fn (string $key): array => $stringLists[$key] ?? []
        );
        $config->method('getString')->willReturnCallback(
            static fn (string $key): string => $strings[$key] ?? ''
        );

        return $config;
    }

    /** @param list<string> $expectedArguments */
    private function runWithExpectedArguments(PluginConfigurationInterface $config, array $expectedArguments): void
    {
        $task    = $this->createMock(TaskInterface::class);
        $builder = $this->createMock(PhpTaskBuilderInterface::class);
        $builder->method('withoutXDebug')->willReturnSelf();
        $builder->expects(self::once())->method('withCosts')->with(4)->willReturnSelf();
        $builder->method('withWorkingDirectory')->willReturnSelf();
        $builder->method('withOutputTransformer')->willReturnSelf();
        $builder->method('build')->willReturn($task);

        $factory = $this->createMock(TaskFactoryInterface::class);
        $factory->expects(self::once())
            ->method('buildRunPhar')
            ->with('phpmd', $expectedArguments)
            ->willReturn($builder);

        $environment = $this->mockEnvironment($factory, '/tmp/report.xml');

        self::assertSame(
            [$task],
            iterator_to_array($this->instantiate()->createDiagnosticTasks($config, $environment), false)
        );
    }

    private function captureOutputTransformerFactory(string $xmlFile): OutputTransformerFactoryInterface
    {
        $transformerFactory = null;
        $builder = $this->createMock(PhpTaskBuilderInterface::class);
        $builder->method('withoutXDebug')->willReturnSelf();
        $builder->method('withCosts')->willReturnSelf();
        $builder->method('withWorkingDirectory')->willReturnSelf();
        $builder->method('withOutputTransformer')->willReturnCallback(
            function (OutputTransformerFactoryInterface $factory) use (&$transformerFactory, $builder) {
                $transformerFactory = $factory;

                return $builder;
            }
        );
        $builder->method('build')->willReturn($this->createMock(TaskInterface::class));

        $factory = $this->createMock(TaskFactoryInterface::class);
        $factory->method('buildRunPhar')->willReturn($builder);

        iterator_to_array(
            $this->instantiate()->createDiagnosticTasks(
                $this->mockConfig(['directories' => ['src'], 'ruleset' => ['naming']], []),
                $this->mockEnvironment($factory, $xmlFile)
            )
        );

        self::assertInstanceOf(OutputTransformerFactoryInterface::class, $transformerFactory);

        return $transformerFactory;
    }

    private function mockEnvironment(TaskFactoryInterface $factory, string $tempFile): EnvironmentInterface
    {
        $project = $this->createMock(ProjectConfigInterface::class);
        $project->method('getProjectRootPath')->willReturn('/project');

        $environment = $this->createMock(EnvironmentInterface::class);
        $environment->method('getTaskFactory')->willReturn($factory);
        $environment->method('getProjectConfiguration')->willReturn($project);
        $environment->method('getAvailableThreads')->willReturn(4);
        $environment->method('getUniqueTempFile')->willReturn($tempFile);

        return $environment;
    }

    /**
     * Mocks the report and records all added diagnostics in $diagnostics.
     *
     * @param list<array<string, mixed>> $diagnostics
     */
    private function mockReport(array &$diagnostics): TaskReportInterface
    {
        $report = $this->createMock(TaskReportInterface::class);

        $attachment = $this->createMock(AttachmentBuilderInterface::class);
        $attachment->method('fromFile')->willReturnSelf();
        $attachment->method('setMimeType')->willReturnSelf();
        $attachment->method('end')->willReturn($report);
        $report->method('addAttachment')->willReturn($attachment);
        $report->expects(self::once())->method('close')->with(TaskReportInterface::STATUS_FAILED);

        $report->method('addDiagnostic')->willReturnCallback(
            function (string $severity, string $message) use (&$diagnostics, $report): DiagnosticBuilderInterface {
                $index         = count($diagnostics);
                $diagnostics[] = ['severity' => $severity, 'message' => $message];

                $diagnostic = $this->createMock(DiagnosticBuilderInterface::class);
                $file       = $this->createMock(FileDiagnosticBuilderInterface::class);
                $diagnostic->method('forFile')->willReturnCallback(
                    function (string $fileName) use (&$diagnostics, $index, $file): FileDiagnosticBuilderInterface {
                        $diagnostics[$index]['file'] = $fileName;

                        return $file;
                    }
                );
                $diagnostic->method('fromSource')->willReturnCallback(
                    function (string $source) use (&$diagnostics, $index, $diagnostic): DiagnosticBuilderInterface {
                        $diagnostics[$index]['source'] = $source;

                        return $diagnostic;
                    }
                );
                $diagnostic->method('end')->willReturn($report);
                $file->method('forRange')->willReturnCallback(
                    function (
                        int $line,
                        ?int $column = null,
                        ?int $endLine = null
                    ) use (
                        &$diagnostics,
                        $index,
                        $file
                    ): FileDiagnosticBuilderInterface {
                        $diagnostics[$index]['range'] = [$line, $column, $endLine];

                        return $file;
                    }
                );
                $file->method('end')->willReturn($diagnostic);

                return $diagnostic;
            }
        );

        return $report;
    }
}
