<?php

/**
 * This file is part of the ApiGen (http://apigen.org)
 *
 * For the full copyright and license information, please view
 * the file license.md that was distributed with this source code.
 */

namespace ApiGen\Command;

use ApiGen\Configuration\Configuration;
use ApiGen\Configuration\ConfigurationOptions as CO;
use ApiGen\Configuration\ConfigurationOptionsResolver as COR;
use ApiGen\FileSystem\FileSystem;
use ApiGen\Generator\GeneratorQueue;
use ApiGen\Neon\NeonFile;
use ApiGen\Parser\Parser;
use ApiGen\Parser\ParserResult;
use ApiGen\Scanner\Scanner;
use ApiGen\Theme\ThemeResources;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TokenReflection\Exception\FileProcessingException;


class GenerateCommand extends Command
{

	/**
	 * @var Configuration
	 */
	private $configuration;

	/**
	 * @var Parser
	 */
	private $parser;

	/**
	 * @var ParserResult
	 */
	private $parserResult;

	/**
	 * @var Scanner
	 */
	private $scanner;

	/**
	 * @var GeneratorQueue
	 */
	private $generatorQueue;

	/**
	 * @var FileSystem
	 */
	private $fileSystem;

	/**
	 * @var ThemeResources
	 */
	private $themeResources;


	public function __construct(
		Configuration $configuration,
		Scanner $scanner,
		Parser $parser,
		ParserResult $parserResult,
		GeneratorQueue $generatorQueue,
		FileSystem $fileSystem,
		ThemeResources $themeResources
	) {
		parent::__construct();
		$this->configuration = $configuration;
		$this->scanner = $scanner;
		$this->parser = $parser;
		$this->parserResult = $parserResult;
		$this->generatorQueue = $generatorQueue;
		$this->fileSystem = $fileSystem;
		$this->themeResources = $themeResources;
	}


	protected function configure()
	{
		$this->setName('generate')
			->setDescription('Generate API documentation')
			->addOption(CO::SOURCE, 's', InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
				'Dirs documentation is generated for (can be specified multiple times).')
			->addOption(CO::DESTINATION, 'd', InputOption::VALUE_REQUIRED, 'Target dir for documentation.')
			->addOption(CO::ACCESS_LEVELS, NULL, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
				'Access levels of included method and properties (can be specified multiple times).',
				[COR::AL_PUBLIC, COR::AL_PROTECTED])
			->addOption(CO::BASE_URL, NULL, InputOption::VALUE_REQUIRED,
				'Base url used for sitemap (useful for public doc).')
			->addOption(CO::CONFIG, NULL, InputOption::VALUE_REQUIRED,
				'Custom path to apigen.neon config file.', getcwd() . '/apigen.neon')
			->addOption(CO::GOOGLE_CSE_ID, NULL, InputOption::VALUE_REQUIRED,
				'Custom google search engine id (for search box).')
			->addOption(CO::GOOGLE_ANALYTICS, NULL, InputOption::VALUE_REQUIRED, 'Google Analytics tracking code.')
			->addOption(CO::DEBUG, NULL, InputOption::VALUE_NONE, 'Turn on debug mode.')
			->addOption(CO::DEPRECATED, NULL, InputOption::VALUE_NONE,
				'Generate documentation for elements marked as @deprecated')
			->addOption(CO::DOWNLOAD, NULL, InputOption::VALUE_NONE,
				'Add link to ZIP archive of documentation.')
			->addOption(CO::EXTENSIONS, NULL, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
				'Scanned file extensions (can be specified multiple times).', ['php'])
			->addOption(CO::EXCLUDE, NULL, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
				'Directories and files matching this mask will not be parsed (can be specified multiple times).')
			->addOption(CO::GROUPS, NULL, InputOption::VALUE_REQUIRED,
				'The way elements are grouped in menu.', 'auto')
			->addOption(CO::CHARSET, NULL, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
				'Charset of scanned files (can be specified multiple times).')
			->addOption(CO::MAIN, NULL, InputOption::VALUE_REQUIRED,
				'Elements with this name prefix will be first in tree.')
			->addOption(CO::INTERNAL, NULL, InputOption::VALUE_NONE, 'Include elements marked as @internal.')
			->addOption(CO::PHP, NULL, InputOption::VALUE_NONE, 'Generate documentation for PHP internal classes.')
			->addOption(CO::SKIP_DOC_PATH, NULL, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
				'Files matching this mask will be included in class tree,'
				. ' but will not create a link to their documentation (can be specified multiple times).')
			->addOption(CO::NO_SOURCE_CODE, NULL, InputOption::VALUE_NONE,
				'Do not generate highlighted source code for elements.')
			->addOption(CO::TEMPLATE_THEME, NULL, InputOption::VALUE_REQUIRED, 'ApiGen template theme name.', 'default')
			->addOption(CO::TEMPLATE_CONFIG, NULL, InputOption::VALUE_REQUIRED,
				'Your own template config, has higher priority ' . CO::TEMPLATE_THEME . '.')
			->addOption(CO::TITLE, NULL, InputOption::VALUE_REQUIRED, 'Title of generated documentation.')
			->addOption(CO::TODO, NULL, InputOption::VALUE_NONE, 'Generate documentation for elements marked as @todo.')
			->addOption(CO::TREE, NULL, InputOption::VALUE_NONE,
				'Generate tree view of classes, interfaces, traits and exceptions.');
	}


	protected function execute(InputInterface $input, OutputInterface $output)
	{
		try {
			$options = $this->prepareOptions($input->getOptions());
			$this->scanAndParse($options, $output);
			$this->generate($options, $output);
			return 0;

		} catch (\Exception $e) {
			$output->writeln(PHP_EOL . '<error>' . $e->getMessage() . '</error>');
			return 1;
		}
	}


	private function scanAndParse(array $options, OutputInterface $output)
	{
		$output->writeln('<info>Scanning sources and parsing</info>');

		$files = $this->scanner->scan($options[CO::SOURCE], $options[CO::EXCLUDE], $options[CO::EXTENSIONS]);
		$this->parser->parse($files);

		$this->reportParserErrors($this->parser->getErrors(), $output);

		$stats = $this->parserResult->getDocumentedStats();
		$output->writeln(sprintf(
			'Found <comment>%d classes</comment>, <comment>%d constants</comment>, '
				. '<comment>%d functions</comment> and <comment>%d PHP internal classes</comment>',
			$stats['classes'], $stats['constants'], $stats['functions'], $stats['internalClasses']
		));
	}


	private function generate(array $options, OutputInterface $output)
	{
		$this->fileSystem->purgeDir($options[CO::DESTINATION]);
		$this->themeResources->copyToDestination($options[CO::DESTINATION]);

		$output->writeln('<info>Generating API documentation</info>');
		$this->generatorQueue->run();
	}


	private function reportParserErrors(array $errors, OutputInterface $output)
	{
		/** @var FileProcessingException[] $errors */
		foreach ($errors as $error) {
			/** @var \Exception[] $reasons */
			$reasons = $error->getReasons();
			if (count($reasons) && isset($reasons[0])) {
				$output->writeln("<error>Parse error: " . $reasons[0]->getMessage() . "</error>");
			}
		}
	}


	/**
	 * @return array
	 */
	private function prepareOptions(array $cliOptions)
	{
		$cliOptions = $this->convertDashKeysToCamel($cliOptions);
		$configFile = $cliOptions[CO::CONFIG];
		$options = $cliOptions;

		if (file_exists($configFile)) {
			$configFileOptions = (new NeonFile($configFile))->read();
			foreach ($configFileOptions as $key => $value) {
				if ($this->canOptionValueBeSet($options, $key)) {
					$options[$key] = $value;
				}
			}
		}

		return $this->configuration->resolveOptions($options);
	}


	/**
	 * @return array
	 */
	private function convertDashKeysToCamel(array $options)
	{
		foreach ($options as $key => $value) {
			$camelKey = $this->camelFormat($key);
			if ($key !== $camelKey) {
				$options[$camelKey] = $value;
				unset($options[$key]);
			}
		}
		return $options;
	}


	/**
	 * @param string $name
	 * @return string
	 */
	private function camelFormat($name)
	{
		return preg_replace_callback('~-([a-z])~', function($matches) {
			return strtoupper($matches[1]);
		}, $name);
	}


	/**
	 * @param array $options
	 * @param string $key
	 * @return bool
	 */
	private function canOptionValueBeSet(array $options, $key)
	{
		return ! isset($options[$key]) || ($options[$key] === NULL || $options[$key] === []);
	}

}
