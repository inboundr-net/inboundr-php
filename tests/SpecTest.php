<?php

declare(strict_types=1);

namespace Inboundr\Tests;

use Inboundr\Inboundr;
use Inboundr\Tests\Support\MockClient;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * The client must cover the API exactly as openapi/openapi.yaml describes it.
 * Every SDK method is called against a mock client and each request is matched
 * to a spec operation; an operation no method reaches, or a request the spec
 * doesn't describe, fails the run.
 */
final class SpecTest extends TestCase
{
    /** @return list<array{key: string, method: string, pattern: string, hasBody: bool}> */
    private static function operations(): array
    {
        /** @var array{paths: array<string, array<string, mixed>>} $spec */
        $spec = Yaml::parseFile(__DIR__ . '/../openapi/openapi.yaml');
        $ops = [];
        foreach ($spec['paths'] as $path => $item) {
            foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
                if (isset($item[$method]) && is_array($item[$method])) {
                    $ops[] = [
                        'key' => strtoupper($method) . " $path",
                        'method' => strtoupper($method),
                        'pattern' => '#^' . preg_replace('/\{[^}]+\}/', '[^/]+', $path) . '$#',
                        'hasBody' => isset($item[$method]['requestBody']),
                    ];
                }
            }
        }
        return $ops;
    }

    public function testEveryOperationHasAMethodAndEveryCallIsInTheSpec(): void
    {
        $ops = self::operations();
        $hits = [];
        $unmatched = [];
        [$c] = MockClient::client(function (RequestInterface $request) use ($ops, &$hits, &$unmatched) {
            $path = substr($request->getUri()->getPath(), strlen('/api/v1'));
            foreach ($ops as $op) {
                if ($op['method'] === $request->getMethod() && preg_match($op['pattern'], $path) === 1) {
                    $hits[$op['key']] = (string) $request->getBody() !== '';
                    return MockClient::json(['data' => [], 'nextCursor' => null]);
                }
            }
            $unmatched[] = $request->getMethod() . " $path";
            return MockClient::json(['data' => [], 'nextCursor' => null]);
        });

        $rule = ['name' => 'n', 'action' => 'block', 'conditions' => [['field' => 'subject', 'operator' => 'contains', 'value' => 'x']]];
        $c->emails->list();
        iterator_to_array($c->emails->listAll());
        $c->emails->get('em_1');
        $c->emails->send(['from' => 'a@x.dev', 'to' => ['b@x.dev'], 'subject' => 's', 'text' => 't']);
        $c->emails->reply('em_1', ['text' => 't']);
        $c->emails->delete('em_1');
        $c->emails->retry('em_1');
        $c->emails->attachmentUrl('em_1', 0);
        $c->emails->downloadAttachment('em_1', 0);
        $c->threads->get('thr_1');
        $c->domains->list();
        $c->domains->create(['domain' => 'x.dev']);
        $c->domains->get('dom_1');
        $c->domains->update('dom_1', ['catchAllEndpointIds' => []]);
        $c->domains->delete('dom_1');
        $c->addresses->list();
        $c->addresses->create(['domainId' => 'dom_1', 'localPart' => 'a']);
        $c->addresses->get('addr_1');
        $c->addresses->update('addr_1', ['active' => false]);
        $c->addresses->delete('addr_1');
        $c->endpoints->list();
        $c->endpoints->create(['name' => 'n', 'url' => 'https://x.dev']);
        $c->endpoints->get('ep_1');
        $c->endpoints->update('ep_1', ['active' => false]);
        $c->endpoints->delete('ep_1');
        $c->endpoints->test('ep_1');
        $c->endpoints->deliveries('ep_1');
        iterator_to_array($c->endpoints->listAllDeliveries('ep_1'));
        $c->shieldRules->list();
        $c->shieldRules->create($rule);
        $c->shieldRules->get('sr_1');
        $c->shieldRules->update('sr_1', $rule);
        $c->shieldRules->delete('sr_1');
        $c->shieldRules->move('sr_1', 'up');
        $c->shieldRules->reorder(['sr_1']);
        $c->senders->list();
        $c->suppressions->list();
        $c->suppressions->create(['address' => 'a@x.dev']);
        $c->suppressions->delete('a@x.dev');
        $c->forwardDestinations->list();
        $c->forwardDestinations->create(['address' => 'a@x.dev']);
        $c->forwardDestinations->delete('a@x.dev');

        self::assertSame([], $unmatched, 'requests the spec does not describe');
        $missing = array_values(array_diff(array_column($ops, 'key'), array_keys($hits)));
        self::assertSame([], $missing, 'operations no SDK method calls');
        $wrongBody = [];
        foreach ($ops as $op) {
            if ($hits[$op['key']] !== $op['hasBody']) {
                $wrongBody[] = $op['key'];
            }
        }
        self::assertSame([], $wrongBody, 'a body sent where the spec has none, or missing');
    }

    public function testGeneratedTypesAreUpToDate(): void
    {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../scripts/generate-types.php') . ' --check 2>&1', $out, $code);
        self::assertSame(0, $code, implode("\n", $out));
    }

    public function testVersionConstantIsSemver(): void
    {
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', Inboundr::VERSION);
    }
}
