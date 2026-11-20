<?php

declare(strict_types=1);

namespace Inboundr\Resources;

use Inboundr\Generated\Types;
use Inboundr\Internal\Transport;

/**
 * @phpstan-import-type ListShieldRulesResponse from Types
 * @phpstan-import-type ShieldRuleInput from Types
 * @phpstan-import-type ShieldRule from Types
 * @phpstan-import-type ReplaceShieldRuleRequest from Types
 * @phpstan-import-type ShieldRuleOrder from Types
 */
final class ShieldRules
{
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * Rules in evaluation order. The first match wins.
     *
     * @return ListShieldRulesResponse
     */
    public function list(): array
    {
        /** @var ListShieldRulesResponse */
        return $this->transport->request('GET', '/shield-rules');
    }

    /**
     * Create a rule. It goes last in the order.
     *
     * @param ShieldRuleInput $params
     * @return ShieldRule
     */
    public function create(array $params): array
    {
        /** @var ShieldRule */
        return $this->transport->request('POST', '/shield-rules', body: $params);
    }

    /**
     * @return ShieldRule
     */
    public function get(string $id): array
    {
        /** @var ShieldRule */
        return $this->transport->request('GET', '/shield-rules/' . Transport::segment($id));
    }

    /**
     * Replace a rule. A rule from `get` can be passed back with changes; the API ignores its read-only fields.
     *
     * @param ReplaceShieldRuleRequest|ShieldRule $params
     * @return ShieldRule
     */
    public function update(string $id, array $params): array
    {
        /** @var ShieldRule */
        return $this->transport->request('PUT', '/shield-rules/' . Transport::segment($id), body: $params);
    }

    public function delete(string $id): void
    {
        $this->transport->request('DELETE', '/shield-rules/' . Transport::segment($id));
    }

    /**
     * Swap the rule with its neighbour. Returns the new order.
     *
     * @param 'up'|'down' $direction
     * @return ShieldRuleOrder
     */
    public function move(string $id, string $direction): array
    {
        /** @var ShieldRuleOrder */
        return $this->transport->request('POST', '/shield-rules/' . Transport::segment($id) . '/move', body: ['direction' => $direction]);
    }

    /**
     * Set the whole order. `$order` must name every rule exactly once.
     *
     * @param array<array-key, string> $order
     * @return ShieldRuleOrder
     */
    public function reorder(array $order): array
    {
        /** @var ShieldRuleOrder */
        return $this->transport->request('PUT', '/shield-rules', body: ['order' => array_values($order)]);
    }
}
