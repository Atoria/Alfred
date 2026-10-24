<?php

namespace Atoria\Alfred\Tests\Fixtures\LarAgent;

use ArrayObject;
use LarAgent\Core\DTO\DriverConfig;
use LarAgent\Drivers\Anthropic\ClaudeDriver;
use LarAgent\Drivers\OpenAi\OpenAiDriver;
use LarAgent\Drivers\OpenAi\OpenAiResponsesDriver;
use LarAgent\Messages\AssistantMessage;

/**
 * LarAgent's real drivers, minus the network: each request's payload (as the
 * driver would send it) is recorded and a scripted reply is returned. Shared
 * ArrayObjects survive Alfred cloning the driver per request.
 */
trait Records
{
    public ArrayObject $payloads;

    public ArrayObject $script;

    public function recording(array $replies): static
    {
        $this->payloads = new ArrayObject;
        $this->script = new ArrayObject($replies);

        return $this;
    }

    public function sendMessage(array $messages, DriverConfig|array $overrideSettings = []): AssistantMessage
    {
        $this->payloads->append($this->preparePayload($messages, $overrideSettings));
        $reply = $this->script->getArrayCopy();
        $next = array_shift($reply);
        $this->script->exchangeArray($reply);
        if ($next instanceof \Throwable) {
            throw $next;
        }
        // ['last' => raw response array, 'throw' => Throwable]: as when the driver got a reply it couldn't parse.
        if (is_array($next)) {
            $this->lastResponse = $next['last'];
            throw $next['throw'];
        }

        return $next ?? throw new \RuntimeException('Recording driver ran out of scripted replies');
    }
}

class RecordingResponsesDriver extends OpenAiResponsesDriver
{
    use Records;
}

class RecordingChatDriver extends OpenAiDriver
{
    use Records;
}

class RecordingClaudeDriver extends ClaudeDriver
{
    use Records;

    protected function buildClient(string $apiKey, string $baseUrl): mixed
    {
        return null;
    }
}
