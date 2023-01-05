<?php

declare(strict_types=1);

namespace SlackLogger;

use GuzzleHttp\Client;
use Nette\Http\Request;
use Nette\Utils\Arrays;
use Nette\Utils\Strings;
use Tracy\Debugger;
use Tracy\ILogger;

class Logger extends \Tracy\Logger
{
	private const MAX_MESSAGE_LENGTH = 4850;
	
	private ?string $slackUrl;
	
	private string $title;
	
	private ?string $freezeInterval;
	
	private Request $request;

	/**
	 * @var array<string>
	 */
	private array $levels;
	
	/**
	 * @var array<string>
	 */
	private array $omitExceptions;
	
	/**
	 * @param string|null $slackUrl
	 * @param string $title
	 * @param string|null $freezeInterval
	 * @param array<string> $levels
	 * @param array<class-string> $omitExceptions
	 */
	public function __construct(Request $request, ?string $slackUrl, string $title, ?string $freezeInterval, array $levels, array $omitExceptions = [])
	{
		parent::__construct(Debugger::$logDirectory, Debugger::$email, Debugger::getBlueScreen());
		
		$this->slackUrl = $slackUrl;
		$this->title = $title;
		$this->freezeInterval = $freezeInterval;
		$this->levels = $levels;
		$this->request = $request;
		$this->omitExceptions = $omitExceptions;
	}
	
	/**
	 * @param mixed $message
	 * @param string $level
	 * phpcs:ignore
	 */
	public function log($message, $level = \Tracy\ILogger::INFO): ?string
	{
		$result = parent::log($message, $level);
		
		if (!$this->slackUrl || !Arrays::contains($this->levels, $level)) {
			return $result;
		}
		
		if ($message instanceof \Throwable && Arrays::contains($this->omitExceptions, \get_class($message))) {
			return $result;
		}
		
		$message = $this->parseMessage($message);
		
		$lockFile = $this->freezeInterval !== null ? Debugger::$logDirectory . '/slack-sent-' . \md5($message) : null;
		
		// phpcs:ignore
		if ($lockFile === null || (@\filemtime($lockFile) <= \strtotime('-' . $this->freezeInterval) && @\file_put_contents($lockFile, 'sent'))) {
			$this->sentToSlack($this->slackUrl, $message, $level);
		}
		
		return $result;
	}

	public function getSlackUrl(): ?string
	{
		return $this->slackUrl;
	}
	
	public function sentToSlack(string $url, string $message, string $level): void
	{
		$client = new Client();
		$client->post($url, [
			'json' => [
				'attachments' => [
					[
						'color' => self::getColor($level),
						'pretext' => $this->title . ' - ' . $this->request->getUrl(),
						'text' => 'ERROR: ' . $message . \PHP_EOL . 'IP: ' . $this->request->getRemoteAddress() . ' | ' . $this->request->getMethod(),
					],
				],
			],
			'verify' => false,
		]);
	}
	
	/**
	 * @param mixed $message
	 */
	private function parseMessage($message): string
	{
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
		
		return $message;
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
