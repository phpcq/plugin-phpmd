<?php

/**
 * Tool home: https://github.com/phpmd/phpmd
 */

declare(strict_types=1);

use Phpcq\PluginApi\Version10\Configuration\PluginConfigurationBuilderInterface;
use Phpcq\PluginApi\Version10\Configuration\PluginConfigurationInterface;
use Phpcq\PluginApi\Version10\DiagnosticsPluginInterface;
use Phpcq\PluginApi\Version10\EnvironmentInterface;
use Phpcq\PluginApi\Version10\Output\OutputInterface;
use Phpcq\PluginApi\Version10\Output\OutputTransformerFactoryInterface;
use Phpcq\PluginApi\Version10\Output\OutputTransformerInterface;
use Phpcq\PluginApi\Version10\Report\TaskReportInterface;
use Phpcq\PluginApi\Version10\Util\BufferedLineReader;

return new class implements DiagnosticsPluginInterface {
    public function getName(): string
    {
        return 'phpmd';
    }

    public function describeConfiguration(PluginConfigurationBuilderInterface $configOptionsBuilder): void
    {
        $configOptionsBuilder->supportDirectories();
        $configOptionsBuilder
            ->describeStringListOption(
                'ruleset',
                'List of rulesets (cleancode, codesize, controversial, design, naming, unusedcode) or paths to '
                . 'ruleset files.'
            )
            ->isRequired()
            ->withDefaultValue(['naming', 'unusedcode']);

        $configOptionsBuilder
            ->describeStringListOption(
                'custom_flags',
                'Any custom flags to pass to phpmd. For valid flags refer to the phpmd documentation.'
            )
            ->isRequired()
            ->withDefaultValue([]);

        $configOptionsBuilder
            ->describeStringListOption(
                'exclude',
                'List of paths or patterns to exclude. Use asterisks to exclude by pattern, e.g. "*src/foo/*.php" '
                . 'or "*src/foo/*".'
            );

        $configOptionsBuilder
            ->describeStringOption(
                'bootstrap',
                'Path to a PHP script (relative to the project root) which is loaded before the analysis.'
            );
    }

    public function createDiagnosticTasks(
        PluginConfigurationInterface $config,
        EnvironmentInterface $environment
    ): iterable {
        $threads = $environment->getAvailableThreads();
        $args    = ['analyze', '--no-progress', '--threads', (string) $threads];

        foreach ($config->getStringList('ruleset') as $ruleset) {
            $args[] = '--ruleset';
            $args[] = $ruleset;
        }

        if ($config->has('exclude')) {
            foreach ($config->getStringList('exclude') as $pattern) {
                if ('' === ($pattern = trim($pattern))) {
                    continue;
                }
                $args[] = '--exclude';
                $args[] = $pattern;
            }
        }

        if ($config->has('bootstrap') && '' !== ($bootstrap = trim($config->getString('bootstrap')))) {
            $args[] = '--bootstrap';
            $args[] = $bootstrap;
        }

        if ($config->has('custom_flags')) {
            foreach ($config->getStringList('custom_flags') as $value) {
                $args[] = $value;
            }
        }

        $xmlfile = $environment->getUniqueTempFile($this, 'xml');
        $args[]  = '--reportfile-xml';
        $args[]  = $xmlfile;

        $args[] = '--';
        foreach ($config->getStringList('directories') as $directory) {
            $args[] = $directory;
        }

        yield $environment
            ->getTaskFactory()
            ->buildRunPhar('phpmd', $args)
            ->withoutXDebug()
            ->withCosts($threads)
            ->withWorkingDirectory($environment->getProjectConfiguration()->getProjectRootPath())
            ->withOutputTransformer(
                $this->createOutputTransformer($xmlfile, $environment->getProjectConfiguration()->getProjectRootPath())
            )
            ->build();
    }

    private function createOutputTransformer(string $xmlFile, string $rootDir): OutputTransformerFactoryInterface
    {
        return new class ($xmlFile, $rootDir) implements OutputTransformerFactoryInterface {
            private $xmlFile;
            private $rootDir;

            public function __construct(string $xmlFile, string $rootDir)
            {
                $this->xmlFile = $xmlFile;
                $this->rootDir = $rootDir;
            }

            public function createFor(TaskReportInterface $report): OutputTransformerInterface
            {
                return new class ($this->xmlFile, $this->rootDir, $report) implements OutputTransformerInterface {
                    /** @var string */
                    private $xmlFile;
                    /** @var string */
                    private $rootDir;
                    /** @var TaskReportInterface */
                    private $report;
                    /** @var BufferedLineReader */
                    private $stdOut;
                    /** @var BufferedLineReader */
                    private $stdErr;

                    public function __construct(string $xmlFile, string $rootDir, TaskReportInterface $report)
                    {
                        $this->xmlFile = $xmlFile;
                        $this->rootDir = $rootDir;
                        $this->report  = $report;
                        $this->stdOut  = BufferedLineReader::create();
                        $this->stdErr  = BufferedLineReader::create();
                    }


                    public function write(string $data, int $channel): void
                    {
                        if (OutputInterface::CHANNEL_STDERR === $channel) {
                            $this->stdErr->push($data);
                            return;
                        }
                        $this->stdOut->push($data);
                    }

                    public function finish(int $exitCode): void
                    {
                        if (null === $xmlDocument = $this->openReportFile()) {
                            return;
                        }
                        $rootNode = $xmlDocument->firstChild;

                        if (!$rootNode instanceof DOMNode) {
                            $this->report->close(
                                $exitCode === 0
                                    ? TaskReportInterface::STATUS_PASSED
                                    : TaskReportInterface::STATUS_FAILED
                            );
                            return;
                        }

                        foreach ($rootNode->childNodes as $childNode) {
                            if (!$childNode instanceof DOMElement) {
                                continue;
                            }

                            if ($childNode->nodeName === 'error') {
                                $this->addError($childNode);
                                continue;
                            }

                            if ($childNode->nodeName !== 'file') {
                                continue;
                            }

                            $fileName = $this->relativeFileName($childNode->getAttribute('name'));

                            foreach ($childNode->childNodes as $violationNode) {
                                if (!$violationNode instanceof DOMElement) {
                                    continue;
                                }

                                /*
                                 * <violation> may have:
                                 * beginline: starting line of the issue.
                                 * endline:   ending line of the issue.
                                 * rule:      name of the rule.
                                 * ruleset:   name of the ruleset the rule is defined within.
                                 * package:   namespace of the class where the issue is within.
                                 * class:     name of the class where the issue is within.
                                 * method:    name of the method where the issue is within.
                                 * externalInfoUrl: external URL describing the violation.
                                 * priority: The priority for the rule.
                                 *           This can be a value in the range 1-5, where 1 is the highest priority and
                                 *           5 the lowest priority.
                                 */

                                $message = sprintf(
                                    '%s%s(Ruleset: %s, %s)',
                                    trim($violationNode->textContent),
                                    "\n",
                                    (string) $this->getXmlAttribute($violationNode, 'ruleset', ''),
                                    (string) $this->getXmlAttribute($violationNode, 'externalInfoUrl', '')
                                );

                                $severity = TaskReportInterface::SEVERITY_FATAL;
                                if (null !== $prio = $this->getIntXmlAttribute($violationNode, 'priority')) {
                                    // FIXME: Is this mapping correct?
                                    switch ($prio) {
                                        case 1:
                                        case 2:
                                        case 3:
                                            $severity = TaskReportInterface::SEVERITY_MAJOR;
                                            break;
                                        case 4:
                                            $severity = TaskReportInterface::SEVERITY_MINOR;
                                            break;
                                        case 5:
                                        default:
                                            $severity = TaskReportInterface::SEVERITY_INFO;
                                    }
                                }

                                $beginLine = $this->getIntXmlAttribute($violationNode, 'beginline');
                                $endLine   = $this->getIntXmlAttribute($violationNode, 'endline');
                                $this->report->addDiagnostic($severity, $message)
                                    ->forFile($fileName)
                                        ->forRange(
                                            (int) $this->getIntXmlAttribute($violationNode, 'beginline'),
                                            null,
                                            $endLine !== $beginLine ? $endLine : null,
                                        )
                                        ->end()
                                    ->fromSource((string) $this->getXmlAttribute($violationNode, 'rule'))
                                    ->end();
                            }
                        }

                        $this->report->addAttachment('pmd.xml')
                                ->fromFile($this->xmlFile)
                                ->setMimeType('application/xml')
                            ->end();

                        $this->report->close(
                            $exitCode === 0
                                ? TaskReportInterface::STATUS_PASSED
                                : TaskReportInterface::STATUS_FAILED
                        );
                    }

                    /**
                     * Processing errors are reported as <error filename="..." msg="..."/>. The message may contain a
                     * stack trace which is stripped (line breaks are normalized to spaces by the XML parser).
                     */
                    private function addError(DOMElement $errorNode): void
                    {
                        $message = (string) $this->getXmlAttribute($errorNode, 'msg', '');
                        if (false !== ($parts = preg_split('/\s+#0 /', $message, 2))) {
                            $message = $parts[0];
                        }

                        $builder = $this->report->addDiagnostic(TaskReportInterface::SEVERITY_FATAL, trim($message));
                        $fileName = (string) $this->getXmlAttribute($errorNode, 'filename', '');
                        if ('' !== $fileName) {
                            $builder->forFile($this->relativeFileName($fileName))->end();
                        }
                        $builder->end();
                    }

                    private function relativeFileName(string $fileName): string
                    {
                        if (strpos($fileName, $this->rootDir) === 0) {
                            return substr($fileName, strlen($this->rootDir) + 1);
                        }

                        return $fileName;
                    }

                    private function openReportFile(): ?DOMDocument
                    {
                        if (is_readable($this->xmlFile) && filesize($this->xmlFile)) {
                            $xmlDocument = new DOMDocument('1.0');
                            $xmlDocument->load($this->xmlFile);
                            return $xmlDocument;
                        }
                        $this->report->addDiagnostic(
                            TaskReportInterface::SEVERITY_FATAL,
                            'Report file was not produced: ' . $this->xmlFile
                        );
                        $contents = [];
                        while (null !== $line = $this->stdOut->fetch()) {
                            $contents[] = $line;
                        }
                        if (!empty($contents)) {
                            $this->report
                                ->addAttachment('output.log')
                                ->fromString(implode("\n", $contents))
                                ->setMimeType('text/plain')
                                ->end();
                        }
                        $contents = [];
                        while (null !== $line = $this->stdErr->fetch()) {
                            $contents[] = $line;
                        }
                        if (!empty($contents)) {
                            $this->report
                                ->addAttachment('error.log')
                                ->fromString(implode("\n", $contents))
                                ->setMimeType('text/plain')
                                ->end();
                        }
                        $this->report->close(TaskReportInterface::STATUS_FAILED);

                        return null;
                    }

                    private function getXmlAttribute(
                        DOMElement $element,
                        string $attribute,
                        ?string $defaultValue = null
                    ): ?string {
                        if ($element->hasAttribute($attribute)) {
                            return $element->getAttribute($attribute);
                        }

                        return $defaultValue;
                    }

                    private function getIntXmlAttribute(DOMElement $element, string $attribute): ?int
                    {
                        $value = $this->getXmlAttribute($element, $attribute);
                        if ($value === null) {
                            return null;
                        }

                        return (int) $value;
                    }
                };
            }
        };
    }
};
