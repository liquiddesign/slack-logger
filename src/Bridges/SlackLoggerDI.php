<?php

declare(strict_types=1);

namespace SlackLogger\Bridges;

use Nette\DI\Definitions\ServiceDefinition;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use SlackLogger\Logger;
use Tracy\ILogger;

class SlackLoggerDI extends \Nette\DI\CompilerExtension
{
	public function getConfigSchema(): Schema
	{
		return Expect::structure([
			'slackUrl' => Expect::string(),
			'title' => Expect::string()->required(),
			'freezeInterval' => Expect::string('24 hours'),
			'levels' => Expect::array([ILogger::ERROR, ILogger::EXCEPTION, ILogger::CRITICAL]),
		]);
	}
	
	public function loadConfiguration(): void
	{
		/** @var \stdClass $config */
		$config = $this->getConfig();
		
		$builder = $this->getContainerBuilder();
		
		$builder->removeDefinition('tracy.logger');
		
		$builder->addDefinition('tracy.logger', new ServiceDefinition())
			->setType(Logger::class)
			->setArguments([
				'request' => '@http.request',
				'slackUrl' => $config->slackUrl,
				'title' => $config->title,
				'freezeInterval' => $config->freezeInterval,
				'levels' => $config->levels,
			]);
	}
}
