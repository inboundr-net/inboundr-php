<?php

declare(strict_types=1);

namespace Inboundr\Resources;

use Inboundr\Generated\Types;
use Inboundr\Internal\Transport;

/**
 * @phpstan-import-type ListEmailsQuery from Types
 * @phpstan-import-type ListEmailsResponse from Types
 * @phpstan-import-type EmailSummary from Types
 * @phpstan-import-type Email from Types
 * @phpstan-import-type SendEmailRequest from Types
 * @phpstan-import-type ReplyRequest from Types
 * @phpstan-import-type SentEmail from Types
 * @phpstan-import-type RetryEmailDeliveriesResponse from Types
 * @phpstan-import-type AttachmentUrl from Types
 */
final class Emails
{
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * One page of emails, newest first. Bodies are omitted; use `get`.
     *
     * @param ListEmailsQuery $query
     * @return ListEmailsResponse
     */
    public function list(array $query = []): array
    {
        /** @var ListEmailsResponse */
        return $this->transport->request('GET', '/emails', $query);
    }

    /**
     * Every matching email, newest first, fetching pages as you iterate.
     *
     * @param ListEmailsQuery $query
     * @return \Generator<int, EmailSummary, mixed, void>
     */
    public function listAll(array $query = []): \Generator
    {
        /** @var \Generator<int, EmailSummary, mixed, void> */
        return $this->transport->paginate('/emails', $query);
    }

    /** @return Email */
    public function get(string $id): array
    {
        /** @var Email */
        return $this->transport->request('GET', '/emails/' . Transport::segment($id));
    }

    /**
     * Send an email. Not idempotent: retrying after a timeout can send it twice.
     *
     * @param SendEmailRequest $params
     * @return SentEmail
     */
    public function send(array $params): array
    {
        /** @var SentEmail */
        return $this->transport->request('POST', '/emails', body: $params);
    }

    /**
     * Reply to a received email. Recipients and threading come from the stored message.
     *
     * @param ReplyRequest $params
     * @return SentEmail
     */
    public function reply(string $id, array $params): array
    {
        /** @var SentEmail */
        return $this->transport->request('POST', '/emails/' . Transport::segment($id) . '/reply', body: $params);
    }

    public function delete(string $id): void
    {
        $this->transport->request('DELETE', '/emails/' . Transport::segment($id));
    }

    /**
     * Retry this email's failed webhook deliveries.
     *
     * @return RetryEmailDeliveriesResponse
     */
    public function retry(string $id): array
    {
        /** @var RetryEmailDeliveriesResponse */
        return $this->transport->request('POST', '/emails/' . Transport::segment($id) . '/retry');
    }

    /**
     * A signed download URL for one attachment, valid for 15 minutes.
     *
     * @return AttachmentUrl
     */
    public function attachmentUrl(string $emailId, int $index): array
    {
        /** @var AttachmentUrl */
        return $this->transport->request('GET', '/emails/' . Transport::segment($emailId) . "/attachments/$index");
    }

    /**
     * Download the attachment through the API and return its bytes. The whole
     * file is held in memory; for large files, use `attachmentUrl` instead.
     */
    public function downloadAttachment(string $emailId, int $index): string
    {
        /** @var string */
        return $this->transport->request(
            'GET',
            '/emails/' . Transport::segment($emailId) . "/attachments/$index",
            ['download' => '1'],
            raw: true,
        );
    }
}
