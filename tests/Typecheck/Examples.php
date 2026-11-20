<?php

declare(strict_types=1);

namespace Inboundr\Tests\Typecheck;

use Inboundr\Exception\SignatureException;
use Inboundr\Inboundr;
use Inboundr\Webhook;

/**
 * Analysed by PHPStan (level max), never run. Each method is real usage. The
 * lines carrying an ignore comment must be errors: PHPStan reports an
 * ignore that matches nothing, so a type that stops rejecting a wrong call
 * fails CI.
 */
final class Examples
{
    public function __construct(private readonly Inboundr $client)
    {
    }

    public function readAndReply(): string
    {
        $page = $this->client->emails->list(['direction' => 'inbound', 'limit' => 20]);
        $email = $this->client->emails->get($page['data'][0]['id']);
        $subject = $email['subject'];
        $sent = $this->client->emails->reply($email['id'], ['text' => 'Thanks']);
        return ($subject ?? '') . $sent['threadId'];
    }

    public function send(): string
    {
        return $this->client->emails->send(['from' => 'a@b.dev', 'to' => ['c@d.dev'], 'subject' => 's', 'text' => 't'])['id'];
    }

    public function wrongCalls(): void
    {
        $this->client->domains->update('dom_1', []); // @phpstan-ignore argument.type
        $this->client->shieldRules->move('sr_1', 'left'); // @phpstan-ignore argument.type
        $this->client->emails->list(['direction' => 'sideways']); // @phpstan-ignore argument.type
        $this->client->emails->send(['to' => ['c@d.dev'], 'subject' => 's']); // @phpstan-ignore argument.type
    }

    public function ruleRoundTrip(string $id): bool
    {
        $rule = $this->client->shieldRules->get($id);
        $rule['enabled'] = false;
        return $this->client->shieldRules->update($id, $rule)['enabled'];
    }

    /** @return list<string> */
    public function iterate(): array
    {
        $ids = [];
        foreach ($this->client->emails->listAll(['deliveryStatus' => 'failed']) as $email) {
            $ids[] = $email['id'];
        }
        return $ids;
    }

    /** @param array<string, string> $headers */
    public function webhook(string $body, array $headers, string $secret): ?string
    {
        try {
            $event = Webhook::verify($body, $headers, $secret);
        } catch (SignatureException $e) {
            if ($e->reason === 'missing_body') {
                $ids = Webhook::verifyIds($headers, $secret);
                return $this->client->emails->get($ids->emailId)['subject'];
            }
            throw $e;
        }
        $deliveryId = Webhook::readHeaders($headers)->deliveryId;
        return $deliveryId === null ? null : $event['email']['subject'];
    }
}
