<?php

declare(strict_types=1);

namespace SlackLogger;

use GuzzleHttp\Client;
use Nette\Utils\Arrays;
use Nette\Utils\Strings;
use Tracy\Debugger;
use Tracy\ILogger;

class Logger extends \Tracy\Logger
{
	private const MAX_MESSAGE_LENGTH = 4850;
	
	private ?string $slackHook;
	
	private string $title;
	
	private ?string $freezeInterval;
	
	/**
	 * @var array<string>
	 */
	private array $levels;
	
	/**
	 * @param string|null $slackHook
	 * @param string $title
	 * @param string|null $freezeInterval
	 * @param array<string> $levels
	 */
	public function __construct(?string $slackHook, string $title, ?string $freezeInterval, array $levels)
	{
		parent::__construct(Debugger::$logDirectory, Debugger::$email, Debugger::getBlueScreen());
		
		$this->slackHook = $slackHook;
		$this->title = $title;
		$this->freezeInterval = $freezeInterval;
		$this->levels = $levels;
	}
	
	/**
	 * @param mixed $message
	 * @param string $level
	 * phpcs:ignore
	 */
	public function log($message, $level = \Tracy\ILogger::INFO): ?string
	{
		$result = parent::log($message, $level);
		
		if ($this->slackHook === null || !Arrays::contains($this->levels, $level)) {
			return $result;
		}
		
		if ($message instanceof \Throwable) {
			$message = $message->getMessage() . ' #' . $message->getCode() . \PHP_EOL . $message->getFile() . ':' . $message->getLine();
		} elseif (\is_array($message)) {
			$message = (string) Arrays::first($message);
		} else {
			$message = (string) $message;
		}
		
		if (Strings::length($message) > self::MAX_MESSAGE_LENGTH) {
			$message = Strings::substring($message, 0, self::MAX_MESSAGE_LENGTH);
		}
		
		$lockFile = $this->freezeInterval !== null ? Debugger::$logDirectory . '/slack-sent-' . \md5($message) : null;
		
		// phpcs:ignore
		if ($lockFile === null || (@\filemtime($lockFile) <= \strtotime('-' . $this->freezeInterval) && @\file_put_contents($lockFile, 'sent'))) {
			$this->sentToSlack($this->slackHook, $message, $level);
		}
		
		return $result;
	}
	
	public function sentToSlack(string $hook, string $message, string $level): void
	{
		$client = new Client();
		$client->post($hook, [
			'json' => [
				'attachments' => [
				  [
					'color' => $this->getColor($level),
					'pretext' => Strings::upper($level) . ': ' . $this->title,
					'text' => $message,
				  ],
				],
			],
			'verify' => false,
		]);
	}
	
	private static function getColor(string $level): ?string
	{
		switch ($level) {
			case ILogger::DEBUG:
			case ILogger::INFO:
				return '#444444';
			case ILogger::WARNING:
				return 'warning';
			case ILogger::ERROR:
			case ILogger::EXCEPTION:
			case ILogger::CRITICAL:
				return 'danger';
			default:
				return null;
		}
	}
}
