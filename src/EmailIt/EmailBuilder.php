<?php

namespace EmailIt;

class EmailBuilder
{
	private array $emailArr = [];
	private EmailItClient $client;
	
	public function __construct(EmailItClient $client)
	{
		$this->client = $client;
	}
	
	public function from(string $from): self
	{
		$this->emailArr['from'] = $from;
		return $this;
	}
	
	public function to(string $to): self
	{
		$this->emailArr['to'] = $to;
		return $this;
	}
	
	public function replyTo(string $replyTo): self
	{
		$this->emailArr['reply_to'] = $replyTo;
		return $this;
	}
	
	public function subject(string $subject): self
	{
		$this->emailArr['subject'] = $subject;
		return $this;
	}
	
	public function html(string $html): self
	{
		$this->emailArr['html'] = $html;
		return $this;
	}
	
	public function text(string $text): self
	{
		$this->emailArr['text'] = $text;
		return $this;
	}
	
	public function addAttachment(string $filename, string $content, string $contentType): self
	{
		if (!isset($this->emailArr['attachments'])) {
			$this->emailArr['attachments'] = [];
		}
		
		$this->emailArr['attachments'][] = [
			'filename' => $filename,
			'content' => $content,
			'content_type' => $contentType
		];
		
		return $this;
	}
	
	public function addHeader(string $name, string $value): self
	{
		if (!isset($this->emailArr['headers'])) {
			$this->emailArr['headers'] = [];
		}
		
		$this->emailArr['headers'][$name] = $value;
		return $this;
	}
	
	public function send(): array
	{
		return $this->client->sendEmailRequest($this->emailArr);
	}
}