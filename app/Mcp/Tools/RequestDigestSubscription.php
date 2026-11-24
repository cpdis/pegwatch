<?php

namespace App\Mcp\Tools;

use App\Digest\DigestSignup;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[Name('request_digest_subscription')]
#[Description('Ask for the free weekly digest of the WA register for the person you are helping. Double opt-in: this only emails a confirm link, and nothing else is sent until they press it. Use only an address the person gave you for this.')]
#[IsOpenWorld]
class RequestDigestSubscription extends AgentTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $this->input($request, ['email' => ['required', 'string', 'email:rfc', 'max:254']]);
        $email = Str::lower(trim($v['email']));
        if (! DigestSignup::request($email, 'agent')) {
            return $this->missing('Too many sign-ups from here or for this address. Try again later.');
        }

        return $this->result(['status' => 'confirmation_sent', 'email' => $email,
            'next' => 'Tell the person to press the link in the email from Pegwatch. Nothing else is sent until then.']);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'email' => $schema->string()->format('email')->max(254)->description('The person\'s own email address.')->required(),
        ];
    }
}
